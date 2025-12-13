<?php
// migrate_vigile_ids.php — riallinea i vigile_id di addestramenti.json dopo cambio ID
//
// Uso web:
//   GET /migrate_vigile_ids.php            -> legge DATA_DIR/vigili_old.json
//   GET /migrate_vigile_ids.php?old=path   -> specifica un vecchio vigili.json alternativo
//
// Uso CLI:
//   php migrate_vigile_ids.php
//   php migrate_vigile_ids.php /percorso/vecchio_vigili.json
//
// Output (in DATA_DIR):
//   - addestramenti.migrated.json
//   - migrate_report.json

ob_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

require __DIR__.'/auth.php';
require __DIR__.'/tenant_bootstrap.php'; // definisce DATA_DIR / tenant
// GATE: richiedi login tenant se disponibile
if (function_exists('require_tenant_user')) {
  require_tenant_user();
} elseif (function_exists('require_login')) {
  require_login();
}
// MFA opzionale (non tutti gli ambienti lo hanno)
if (function_exists('require_mfa')) {
  require_mfa();
}

require __DIR__.'/storage.php';
require __DIR__.'/utils.php';

function json_relaxed_load(string $path, $def) {
  if (!is_file($path)) return $def;
  $s = @file_get_contents($path);
  if ($s === false || $s === '') return $def;
  $s = preg_replace('/^\xEF\xBB\xBF/', '', $s);
  $s = preg_replace('#/\*.*?\*/#s', '', $s);
  $s = preg_replace('#//.*$#m', '', $s);
  $s = preg_replace('/,\s*([\}\]])/', '$1', $s);
  $d = json_decode($s, true);
  return is_array($d) ? $d : $def;
}
function json_save_pretty(string $path, $arr): void {
  @mkdir(dirname($path), 0775, true);
  $json = json_encode($arr, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
  @file_put_contents($path, $json);
  @chmod($path, 0664);
}

// Normalizzazione robusta (coerente con lo sync)
function norm_ascii(string $s): string {
  $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
  $s = trim($s);
  $s = mb_strtolower($s, 'UTF-8');
  if (class_exists('Normalizer')) {
    $s = Normalizer::normalize($s, Normalizer::FORM_D);
    $s = preg_replace('/\p{Mn}+/u', '', $s);  // rimuovi diacritici
    $s = Normalizer::normalize($s, Normalizer::FORM_C);
  } elseif (function_exists('iconv')) {
    $tmp = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    if ($tmp !== false) $s = $tmp;
  }
  $s = str_replace(['’','‘','ʼ','´','`',"'"], '.', $s);
  $s = preg_replace('/[^a-z0-9.]+/', '.', $s);
  $s = preg_replace('/\.{2,}/', '.', $s);
  $s = trim($s, '.');
  return $s;
}
function key_nome(array $v): string {
  $c = norm_ascii((string)($v['cognome'] ?? ''));
  $n = norm_ascii((string)($v['nome'] ?? ''));
  return $c.'|'.$n;
}
function key_cf(array $v): string {
  return strtoupper(trim((string)($v['cf'] ?? '')));
}

$DATA_DIR   = defined('DATA_DIR') ? DATA_DIR : (__DIR__.'/data');
$VIGILI_NEW = defined('VIGILI_JSON') ? VIGILI_JSON : ($DATA_DIR.'/vigili.json');
$ADDESTR    = defined('ADDESTR_JSON') ? ADDESTR_JSON : ($DATA_DIR.'/addestramenti.json');

// Percorso del vecchio vigili.json
$oldParam = (php_sapi_name()==='cli') ? ($argv[1] ?? null) : ($_GET['old'] ?? null);
$VIGILI_OLD = $oldParam ? $oldParam : ($DATA_DIR.'/vigili_old.json');

// Verifiche preliminari
if (!is_file($VIGILI_OLD)) {
  http_response_code(400);
  header('Content-Type: text/plain; charset=utf-8');
  echo "ERRORE: file con il VECCHIO vigili.json non trovato: $VIGILI_OLD\n";
  echo "Suggerimento: copia/rinomina il vigili.json pre-SIPEC in $VIGILI_OLD oppure passa ?old=/percorso/file\n";
  exit;
}
if (!is_file($VIGILI_NEW)) {
  http_response_code(500);
  header('Content-Type: text/plain; charset=utf-8');
  echo "ERRORE: vigili.json attuale non trovato: $VIGILI_NEW\n";
  exit;
}
if (!is_file($ADDESTR)) {
  http_response_code(500);
  header('Content-Type: text/plain; charset=utf-8');
  echo "ERRORE: addestramenti.json non trovato: $ADDESTR\n";
  exit;
}

// Caricamenti
$oldV = json_relaxed_load($VIGILI_OLD, []);
$newV = json_relaxed_load($VIGILI_NEW, []);
$adds = json_relaxed_load($ADDESTR, []);

if (!is_array($oldV) || !is_array($newV)) {
  http_response_code(500);
  header('Content-Type: text/plain; charset=utf-8');
  echo "ERRORE: vigili_old o vigili_new non validi.\n";
  exit;
}

// Indici per matching
$idxOldByCF  = [];  // CF -> old_id
$idxNewByCF  = [];  // CF -> new_id
$idxOldByNN  = [];  // cognome|nome -> [old_id...]
$idxNewByNN  = [];  // cognome|nome -> [new_id...]

foreach ($oldV as $v) {
  $id = (int)($v['id'] ?? 0); if (!$id) continue;
  $cf = key_cf($v);
  if ($cf !== '') $idxOldByCF[$cf] = $id;
  $nn = key_nome($v);
  if ($nn !== '') $idxOldByNN[$nn][] = $id;
}
foreach ($newV as $v) {
  $id = (int)($v['id'] ?? 0); if (!$id) continue;
  $cf = key_cf($v);
  if ($cf !== '') $idxNewByCF[$cf] = $id;
  $nn = key_nome($v);
  if ($nn !== '') $idxNewByNN[$nn][] = $id;
}

// Mappa old_id -> new_id
$map = [];
$report = [
  'matched_by' => ['cf'=>[], 'name'=>[]],
  'conflicts'  => [],
  'not_found'  => [],
];

// 1) CF
foreach ($oldV as $v) {
  $oldId = (int)($v['id'] ?? 0); if (!$oldId) continue;
  $cf = key_cf($v);
  if ($cf !== '' && isset($idxNewByCF[$cf])) {
    $map[$oldId] = $idxNewByCF[$cf];
    $report['matched_by']['cf'][] = ['old_id'=>$oldId, 'new_id'=>$idxNewByCF[$cf], 'cf'=>$cf];
  }
}
// 2) Cognome|Nome (solo se non mappato via CF)
foreach ($oldV as $v) {
  $oldId = (int)($v['id'] ?? 0); if (!$oldId || isset($map[$oldId])) continue;
  $nn = key_nome($v);
  if ($nn === '' || !isset($idxNewByNN[$nn])) continue;
  $candidati = $idxNewByNN[$nn];
  if (count($candidati) === 1) {
    $map[$oldId] = $candidati[0];
    $report['matched_by']['name'][] = ['old_id'=>$oldId, 'new_id'=>$candidati[0], 'name_key'=>$nn];
  } else {
    $report['conflicts'][] = ['old_id'=>$oldId, 'name_key'=>$nn, 'candidates'=>$candidati];
  }
}
// 3) non trovati
foreach ($oldV as $v) {
  $oldId = (int)($v['id'] ?? 0); if (!$oldId) continue;
  if (!isset($map[$oldId])) {
    $report['not_found'][] = [
      'old_id'=>$oldId,
      'cf'=> key_cf($v),
      'name_key'=> key_nome($v)
    ];
  }
}

// Applica la mappa agli addestramenti
$migrated = [];
$unmappedCount = 0;
foreach ($adds as $r) {
  if (!is_array($r)) { $migrated[] = $r; continue; }
  $oldVid = (int)($r['vigile_id'] ?? 0);
  if ($oldVid && isset($map[$oldVid])) {
    $r['vigile_id'] = (int)$map[$oldVid];
  } else {
    if ($oldVid) $unmappedCount++;
  }
  $migrated[] = $r;
}

// Scrive output
$outAdds   = $DATA_DIR.'/addestramenti.migrated.json';
$outReport = $DATA_DIR.'/migrate_report.json';
json_save_pretty($outAdds,   $migrated);
json_save_pretty($outReport, $report);

// Riepilogo
header('Content-Type: text/plain; charset=utf-8');
echo "Migrazione completata.\n";
echo "- Mappature per CF:     ".count($report['matched_by']['cf'])."\n";
echo "- Mappature per Nome:   ".count($report['matched_by']['name'])."\n";
echo "- Conflitti (omonimie): ".count($report['conflicts'])."\n";
echo "- Non trovati:          ".count($report['not_found'])."\n";
echo "- Addestramenti non mappati (lasciati invariati): $unmappedCount\n\n";
echo "File prodotti in DATA_DIR:\n";
echo "  • addestramenti.migrated.json\n";
echo "  • migrate_report.json\n\n";
echo "Se tutto torna, fai un backup e poi rinomina:\n";
echo "  mv addestramenti.json addestramenti.backup.json\n";
echo "  mv addestramenti.migrated.json addestramenti.json\n";