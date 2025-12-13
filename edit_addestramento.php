<?php
// edit_addestramento.php — modifica di una sessione esistente (UID o fallback legacy per start/end)
// Allinea il caricamento al gemello addestramenti.php: stesso DATA_DIR/addestramenti.json

ob_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

require __DIR__.'/auth.php';
require __DIR__.'/tenant_bootstrap.php'; // definisce DATA_DIR / tenant attivo
if (function_exists('require_tenant_user')) { require_tenant_user(); }
if (function_exists('require_mfa')) { require_mfa(); }

// utils per next_id(), minuti_da_intervallo_datetime(), load_infortuni() ecc.
require __DIR__.'/utils.php';

// ===== UTILITIES LOCALI: stesse di addestramenti.php (no storage.php) =====
if (!defined('DATA_DIR')) {
  define('DATA_DIR', __DIR__ . '/data');
}
function _path(string $file): string {
  $file = ltrim($file, '/');
  return rtrim(DATA_DIR, '/').'/'.$file;
}
function _read_json(string $absPath): array {
  if (!is_file($absPath)) return [];
  $s = @file_get_contents($absPath);
  if ($s === false) return [];
  $j = json_decode($s, true);
  return is_array($j) ? $j : [];
}
function _write_json(string $absPath, array $data): void {
  $dir = dirname($absPath);
  if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
  $json = json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
  if ($json === false) throw new RuntimeException('JSON encoding fallita: '.json_last_error_msg());
  $tmp = $absPath.'.tmp-'.bin2hex(random_bytes(6));
  if (file_put_contents($tmp, $json) === false) throw new RuntimeException('Scrittura temporanea fallita: '.$tmp);
  if (!@rename($tmp, $absPath)) { @unlink($tmp); throw new RuntimeException('Rename atomico fallito verso: '.$absPath); }
  @chmod($absPath, 0664);
}
function load_vigili_local(): array {
  $path = defined('VIGILI_JSON') ? VIGILI_JSON : _path('vigili.json');
  return _read_json($path);
}
function load_addestramenti_local(): array {
  $path = defined('ADDESTRAMENTI_JSON') ? ADDESTRAMENTI_JSON : _path('addestramenti.json');
  return _read_json($path);
}
function save_addestramenti_local(array $rows): void {
  $path = defined('ADDESTRAMENTI_JSON') ? ADDESTRAMENTI_JSON : _path('addestramenti.json');
  _write_json($path, $rows);
}
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// ===== Permessi =====
if (function_exists('require_perm')) {
  require_perm('view:addestramenti');
  require_perm('edit:addestramenti');
}

// ===== Sessione + CSRF =====
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['_csrf_edit_addestramento'])) {
  $_SESSION['_csrf_edit_addestramento'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['_csrf_edit_addestramento'];

// ===== Catalogo attività (stessa logica, in DATA_DIR/attivita.json) =====
if (!defined('ATTIVITA_JSON')) define('ATTIVITA_JSON', DATA_DIR . '/attivita.json');
if (!file_exists(ATTIVITA_JSON)) { @file_put_contents(ATTIVITA_JSON, "[]"); }

function load_attivita_catalogo(): array {
  $rows = _read_json(ATTIVITA_JSON);
  if (!is_array($rows)) $rows = [];
  $clean = [];
  foreach ($rows as $x) {
    if (is_string($x)) { $s = trim($x); if ($s!=='') $clean[]=$s; }
    elseif (is_array($x) && isset($x['label'])) { $s=trim((string)$x['label']); if ($s!=='') $clean[]=$s; }
  }
  $seen=[]; $out=[];
  foreach ($clean as $s){ $k=mb_strtolower($s,'UTF-8'); if(!isset($seen[$k])){$seen[$k]=1; $out[]=$s;} }
  sort($out, SORT_FLAG_CASE | SORT_STRING);
  return $out;
}
function add_to_attivita_catalogo(string $label): void {
  $label = trim($label);
  if ($label==='') return;
  $all = load_attivita_catalogo();
  $kNew = mb_strtolower($label,'UTF-8');
  foreach($all as $ex) if(mb_strtolower($ex,'UTF-8')===$kNew) return;
  $all[]=$label;
  sort($all, SORT_FLAG_CASE | SORT_STRING);
  _write_json(ATTIVITA_JSON, $all);
}
$catalogoAtt = load_attivita_catalogo();

// ===== Menu opzionale =====
$menuPath = __DIR__.'/_menu.php';
if (is_file($menuPath)) { include $menuPath; }

// ===== Helper errore =====
function html_err($code, $msg) {
  http_response_code($code);
  ?><!doctype html><html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Errore</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  </head><body class="bg-light"><div class="container py-4">
    <div class="alert alert-danger"><strong>Errore:</strong> <?= htmlspecialchars($msg) ?></div>
    <a class="btn btn-secondary" href="addestramenti.php">← Torna all’elenco</a>
  </div></body></html><?php
  exit;
}

// ===== Caricamento dati base =====
$vigili = load_vigili_local();
$vigById = [];
foreach ($vigili as $v) {
  if ((int)($v['attivo'] ?? 1) !== 1) continue;
  $vid = (int)($v['id'] ?? 0);
  if ($vid>0) $vigById[$vid] = $v;
}
$vigiliOrdinati = array_values($vigById);
usort($vigiliOrdinati, fn($a,$b)=>strcmp(
  trim(($a['cognome']??'').' '.($a['nome']??'')),
  trim(($b['cognome']??'').' '.($b['nome']??'')))
);

$items = load_addestramenti_local();

// ===== Parametri =====
$uid = trim((string)($_GET['uid'] ?? $_POST['uid'] ?? ''));
$idParamRaw = $_GET['id'] ?? $_POST['id'] ?? '';
$idParamStr = (string)$idParamRaw;
$idParamInt = (int)$idParamRaw;

// Normalizza start/end per legacy
$normSE = function(array $r): array {
  $d = (string)($r['data'] ?? '');
  $s = $r['inizio_dt'] ?? ($d ? ($d.'T'.substr((string)($r['inizio']??'00:00'),0,5).':00') : '');
  $e = $r['fine_dt']   ?? ($d ? ($d.'T'.substr((string)($r['fine']  ??'00:00'),0,5).':00') : '');
  return [$s,$e];
};

// ===== Individua le righe della sessione =====
$sessionRowsAll = [];
if ($uid !== '') {
  foreach ($items as $it) if (is_array($it) && (($it['sessione_uid'] ?? '') === $uid)) $sessionRowsAll[]=$it;
}

$legacyMode = false;
$legacyKeyStart = $legacyKeyEnd = null;
$probe = null;

if (empty($sessionRowsAll) && ($idParamStr !== '' || $idParamInt > 0)) {
  foreach ($items as $it) {
    if (!is_array($it)) continue;
    $rid = $it['id'] ?? null;
    if ($rid === null) continue;
    if ((string)$rid === $idParamStr || (int)$rid === $idParamInt) { $probe = $it; break; }
  }
  if ($probe) {
    $uidProbe = trim((string)($probe['sessione_uid'] ?? ''));
    if ($uidProbe !== '') {
      $uid = $uidProbe;
      foreach ($items as $it) if (is_array($it) && (($it['sessione_uid'] ?? '') === $uid)) $sessionRowsAll[]=$it;
    } else {
      [$ps,$pe] = $normSE($probe);
      if ($ps && $pe) {
        $legacyKeyStart=$ps; $legacyKeyEnd=$pe; $legacyMode=true;
        foreach ($items as $it) {
          if (!is_array($it)) continue;
          [$s,$e] = $normSE($it);
          if ($s===$ps && $e===$pe) $sessionRowsAll[]=$it;
        }
      }
    }
  }
}

if (empty($sessionRowsAll) && $probe) {
  $sessionRowsAll = [$probe];
  [$legacyKeyStart, $legacyKeyEnd] = $normSE($probe);
  $legacyMode = true;
}

// ===== DELETE intera sessione =====
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['delete'])) {
  if (!hash_equals($_SESSION['_csrf_edit_addestramento'] ?? '', $_POST['csrf'] ?? '')) {
    html_err(400,'Bad CSRF token.');
  }
  $keep = [];
  foreach ($items as $it) {
    $sameUid = ($uid!=='' && ($it['sessione_uid'] ?? '') === $uid);
    $sameLegacy = false;
    if ($legacyMode) {
      [$s,$e] = $normSE($it);
      $sameLegacy = ($s===$legacyKeyStart && $e===$legacyKeyEnd);
    }
    if (!($sameUid || $sameLegacy)) $keep[] = $it;
  }
  save_addestramenti_local($keep);
  header('Location: addestramenti.php?del=ok', true, 303); exit;
}

// ===== Se ancora nulla → selettore legacy =====
if (empty($sessionRowsAll)) {
  $self = basename(__FILE__);
  $groups = [];
  foreach ($items as $it) {
    if (!is_array($it)) continue;
    [$s,$e] = $normSE($it);
    if(!$s||!$e) continue;
    $gid = $s.'|'.$e;
    if(!isset($groups[$gid])) $groups[$gid]=['s'=>$s,'e'=>$e,'rows'=>[]];
    $groups[$gid]['rows'][]=$it;
  }
  $groups = array_values($groups);
  usort($groups, fn($a,$b)=>strcmp($b['e'],$a['e']));
  ?><!doctype html>
  <html lang="it"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Seleziona sessione (legacy)</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  </head><body class="bg-light"><div class="container py-4">
    <div class="alert alert-warning mb-3">
      <strong>Sessione non trovata per UID.</strong> I tuoi dati non usano <code>sessione_uid</code>.
      Seleziona qui sotto una sessione legacy (raggruppata per inizio/fine).
    </div>
    <table class="table table-sm align-middle">
      <thead><tr><th>#</th><th>Periodo</th><th>Partecipanti</th><th></th></tr></thead>
      <tbody>
      <?php $i=1; foreach (array_slice($groups,0,300) as $g):
        $rRapp = $g['rows'][0];
        $idRep = $rRapp['id'] ?? null; if ($idRep===null || $idRep==='') continue;
        $data  = substr($g['s'],0,10);
        $hhmm  = function($dt){ $x=(strlen($dt)===16?$dt.':00':$dt); return substr($x,11,5); };
        $iniz  = $hhmm($g['s']); $fine=$hhmm($g['e']);
        $partecipanti = 0; foreach ($g['rows'] as $rr){ if (isset($rr['vigile_id'])) $partecipanti++; }
      ?>
        <tr>
          <td><?= $i++ ?></td>
          <td><?= h("$data $iniz → $fine") ?></td>
          <td><?= (int)$partecipanti ?></td>
          <td class="text-end">
            <a class="btn btn-sm btn-primary" href="<?= h($self) ?>?id=<?= urlencode((string)$idRep) ?>">Apri</a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <a class="btn btn-outline-secondary" href="addestramenti.php">← Elenco</a>
  </div></body></html><?php
  exit;
}

// ===== Master/sessione corrente =====
$sessionRows = array_values(array_filter($sessionRowsAll, fn($r)=> isset($r['vigile_id'])));
$r0 = $sessionRows[0] ?? $sessionRowsAll[0];

$inizioDT  = $r0['inizio_dt'] ?? (($r0['data']??'').'T'.substr((string)($r0['inizio']??'00:00'),0,5).':00');
$fineDT    = $r0['fine_dt']   ?? (($r0['data']??'').'T'.substr((string)($r0['fine']  ??'00:00'),0,5).':00');
$attivita0 = trim((string)($r0['attivita'] ?? ''));
$note0     = trim((string)($r0['note'] ?? ''));

if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?$/', (string)$inizioDT)) html_err(500,'Record sessione corrotto: inizio mancante o non valido.');
if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?$/', (string)$fineDT))   html_err(500,'Record sessione corrotto: fine mancante o non valido.');

// ===== Banca-ore (12 mesi pre-mese sessione, pro-rata con infortuni) =====
$yyyy = (int)substr($inizioDT,0,4);
$mm   = (int)substr($inizioDT,5,2);
$startRef = DateTime::createFromFormat('Y-m-d', sprintf('%04d-%02d-01',$yyyy,$mm));
$winStart = (clone $startRef)->modify('-1 year');
$winEnd   = (clone $startRef)->modify('-1 day');
$winStartStr = $winStart->format('Y-m-d');
$winEndStr   = $winEnd->format('Y-m-d');

$mesiFinestra=[]; $cur=(clone $winStart)->modify('first day of this month'); $end=(clone $winEnd)->modify('first day of next month');
while($cur<$end){ $mesiFinestra[]=$cur->format('Y-m'); $cur->modify('+1 month'); }

$nonrec=[]; $rec=[];
foreach($items as $r){
  if(!is_array($r)) continue;
  $vid=(int)($r['vigile_id']??0); $d=(string)($r['data']??'');
  if(!$vid||!$d) continue;
  if($d<$winStartStr || $d>$winEndStr) continue;
  $minr = isset($r['minuti']) ? (int)$r['minuti']
         : minuti_da_intervallo_datetime(
             $r['inizio_dt'] ?? ($d.'T'.substr((string)($r['inizio']??'00:00'),0,5).':00'),
             $r['fine_dt']   ?? ($d.'T'.substr((string)($r['fine']  ??'00:00'),0,5).':00')
           );
  if ((int)($r['recupero']??0)===1) $rec[$vid]=($rec[$vid]??0)+$minr;
  else                              $nonrec[$vid]=($nonrec[$vid]??0)+$minr;
}
// infortuni
$infortuniRaw = function_exists('load_infortuni') ? (load_infortuni() ?: []) : [];
$INFORTUNI=[];
if (is_array($infortuniRaw)) {
  foreach ($infortuniRaw as $row) {
    $vid=(int)($row['vigile_id']??0); $dal=(string)($row['dal']??''); $al=(string)($row['al']??'');
    if($vid && preg_match('/^\d{4}-\d{2}-\d{2}$/',$dal) && preg_match('/^\d{4}-\d{2}-\d{2}$/',$al)) $INFORTUNI[$vid][]=[$dal,$al];
  }
}
$mesiInfByVid=[];
foreach($INFORTUNI as $vid=>$ranges){
  $set=[];
  foreach($ranges as [$dal,$al]){
    if($al<$winStartStr || $dal>$winEndStr) continue;
    $from=max($dal,$winStartStr); $to=min($al,$winEndStr);
    $p=DateTime::createFromFormat('Y-m-d', substr($from,0,7).'-01'); $last=substr($to,0,7);
    while($p->format('Y-m')<=$last){ $set[$p->format('Y-m')]=true; $p->modify('+1 month'); }
  }
  $mesiInfByVid[$vid]=$set;
}
const QUOTA_MENSILE_MIN = 5*60;
$BANCA=[];
foreach($vigById as $vid=>$vv){
  $ing = $vv['data_ingresso'] ?? $vv['ingresso'] ?? null;
  $ingMonth = $ing ? substr($ing,0,7) : ($mesiFinestra[0] ?? substr($winStartStr,0,7));
  $mesiRilevanti = array_filter($mesiFinestra, fn($ym)=> $ym >= $ingMonth);
  $setInf = $mesiInfByVid[$vid] ?? [];
  $mesiQuota = array_values(array_filter($mesiRilevanti, fn($ym)=> empty($setInf[$ym])));
  $n = count($mesiQuota);
  $soglia = $n*QUOTA_MENSILE_MIN;
  $nr=(int)($nonrec[$vid]??0); $rc=(int)($rec[$vid]??0);
  $ecc=max(0,$nr-$soglia);
  $bank=$ecc-$rc; if($bank<0)$bank=0;
  $BANCA[$vid]=$bank;
}
$recupero0=0;
foreach($sessionRows as $r){ if((int)($r['recupero']??0)===1){ $recupero0=1; break; } }
$presenti=[];
foreach($sessionRows as $r){ if(isset($r['vigile_id'])) $presenti[(int)$r['vigile_id']]=true; }

// ===== POST: Salva =====
if ($_SERVER['REQUEST_METHOD']==='POST' && (($_POST['action'] ?? '')==='save')) {
  if (!hash_equals($_SESSION['_csrf_edit_addestramento'] ?? '', $_POST['csrf'] ?? '')) html_err(400,'Bad CSRF token.');
  try{
    $inizioDT = trim($_POST['inizio_dt'] ?? '');
    $fineDT   = trim($_POST['fine_dt']   ?? '');

    $att_sel = trim((string)($_POST['attivita_select'] ?? ''));
    $att_cus = trim(preg_replace('/\s+/', ' ', $_POST['attivita_custom'] ?? ''));
    $addProg = isset($_POST['add_to_program']) ? 1 : 0;
    $attivita = ($att_sel && $att_sel!=='__ALTRO__') ? $att_sel : $att_cus;
    if ($addProg && $attivita!=='') add_to_attivita_catalogo($attivita);

    $note       = trim(preg_replace('/\s+/', ' ', $_POST['note'] ?? ''));
    $selez      = $_POST['vigili_presenti'] ?? [];
    $isRecupero = isset($_POST['recupero']) ? 1 : 0;

    if(!is_array($selez)) $selez=[];
    $selez = array_values(array_unique(array_map('intval',$selez)));
    if (empty($selez)) throw new InvalidArgumentException('Seleziona almeno un presente');

    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?$/',$inizioDT)) throw new InvalidArgumentException('Inizio non valido');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?$/',$fineDT))   throw new InvalidArgumentException('Fine non valido');

    $min = minuti_da_intervallo_datetime($inizioDT,$fineDT);

    $tz  = new DateTimeZone(date_default_timezone_get());
    $fix = fn($s)=> (strlen($s)===16 ? $s.':00' : $s);
    $startTs = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s',$fix($inizioDT),$tz)->getTimestamp();
    $endTs   = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s',$fix($fineDT),  $tz)->getTimestamp();

    $legacyMatch = function(array $it) use ($legacyMode,$legacyKeyStart,$legacyKeyEnd,$normSE): bool {
      if (!$legacyMode) return false;
      [$s,$e] = $normSE($it);
      return ($s===$legacyKeyStart && $e===$legacyKeyEnd);
    };

    $esistenti=[];
    foreach ($items as $idx=>$it){
      $sameUid = (($it['sessione_uid'] ?? '') === $uid) && ($uid!=='');
      if (($sameUid || $legacyMatch($it)) && isset($it['vigile_id'])) $esistenti[(int)$it['vigile_id']]=$idx;
    }

    // overlap
    foreach ($selez as $vid){
      foreach($items as $it){
        if ((int)($it['vigile_id'] ?? 0)!==$vid) continue;
        $isSame = (($uid!=='' && ($it['sessione_uid'] ?? '')===$uid) || $legacyMatch($it));
        if ($isSame) continue;

        if (isset($it['inizio_dt'],$it['fine_dt'])) {
          $s = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s',$fix($it['inizio_dt']),$tz);
          $e = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s',$fix($it['fine_dt']),  $tz);
        } else {
          $s = DateTimeImmutable::createFromFormat('Y-m-d H:i', ($it['data']??'').' '.substr((string)($it['inizio']??'00:00'),0,5), $tz);
          $e = DateTimeImmutable::createFromFormat('Y-m-d H:i', ($it['data']??'').' '.substr((string)($it['fine']  ??'00:00'),0,5), $tz);
          if ($s && $e && $e <= $s) $e = $e->add(new DateInterval('P1D'));
        }
        if(!$s||!$e) continue;
        if (max($startTs,$s->getTimestamp()) < min($endTs,$e->getTimestamp())) {
          throw new InvalidArgumentException("Sovrapposizione oraria con altro addestramento per vigile ID $vid");
        }
      }
    }

    $data = substr($inizioDT,0,10);
    $toHH = fn($dt)=> substr($fix($dt),11,5);

    foreach ($selez as $vid){
      $bank = (int)($BANCA[$vid] ?? 0);
      $minEff = $isRecupero ? min($min, max(0,$bank)) : $min;

      if (isset($esistenti[$vid])) {
        $i = $esistenti[$vid];
        $items[$i]['data']=$data;
        $items[$i]['inizio']=$toHH($inizioDT);
        $items[$i]['fine']=$toHH($fineDT);
        $items[$i]['inizio_dt']=$fix($inizioDT);
        $items[$i]['fine_dt']=$fix($fineDT);
        $items[$i]['minuti']=(int)$minEff;
        $items[$i]['attivita']=($attivita!==''?$attivita:null);
        $items[$i]['note']=($note!==''?$note:null);
        $items[$i]['recupero']=$isRecupero?1:0;
        if ($uid!=='' && empty($items[$i]['sessione_uid'])) $items[$i]['sessione_uid']=$uid;
      } else {
        $id = function_exists('next_id') ? next_id($items) : (max(array_column($items,'id'))+1);
        $row = [
          'id'           => $id,
          'sessione_uid' => ($uid!=='' ? $uid : null),
          'vigile_id'    => $vid,
          'data'         => $data,
          'inizio'       => $toHH($inizioDT),
          'fine'         => $toHH($fineDT),
          'inizio_dt'    => $fix($inizioDT),
          'fine_dt'      => $fix($fineDT),
          'minuti'       => (int)$minEff,
          'attivita'     => ($attivita!==''?$attivita:null),
          'note'         => ($note!==''?$note:null),
          'created_at'   => date('Y-m-d H:i:s'),
          'recupero'     => $isRecupero?1:0,
        ];
        if ($row['sessione_uid']===null) unset($row['sessione_uid']);
        $items[]=$row;
      }
    }

    // Tieni solo i presenti nella sessione corrente
    $keepIds = array_flip($selez);
    $items = array_values(array_filter($items, function($it) use ($uid,$legacyMode,$legacyKeyStart,$legacyKeyEnd,$normSE,$keepIds){
      $sameUid = (($it['sessione_uid'] ?? '') === $uid) && ($uid!=='');
      $sameLegacy=false;
      if ($legacyMode){ [$s,$e]=$normSE($it); $sameLegacy = ($s===$legacyKeyStart && $e===$legacyKeyEnd); }
      if (!($sameUid || $sameLegacy)) return true;
      if (!isset($it['vigile_id']))   return false;
      return isset($keepIds[(int)$it['vigile_id']]);
    }));

    save_addestramenti_local($items);
    header('Location: addestramenti.php?msg=ok', true, 303); exit;

  } catch(Throwable $e){ html_err(400,$e->getMessage()); }
}

// ===== UI =====
$usaAltro = ($attivita0 !== '' && !in_array($attivita0, $catalogoAtt, true));
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Modifica addestramento</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  .form-check-label.text-danger { font-weight: 600; }
  .form-check-input.is-invalid { outline: 2px solid rgba(220,53,69,.6); }
  .form-check-label.text-success { font-weight: 600; }
  .form-check-input.is-valid { outline: 2px solid rgba(25,135,84,.6); }
  .form-check-label.text-warning { font-weight: 600; }
  .form-check-input.is-partial { outline: 2px solid rgba(255,193,7,.6); }
  .form-check-input:disabled + .form-check-label { opacity:.65; }
  select.long-options { width: 100%; max-width: 100%; }
  select.long-options option { white-space: normal; word-break: break-word; }
</style>
</head>
<body class="bg-light">
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center">
    <h1 class="mb-0">Dettaglio / Modifica addestramento</h1>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-secondary" href="addestramenti.php">← Elenco</a>
      <form method="post" onsubmit="return confirm('Eliminare TUTTA la sessione?');">
        <input type="hidden" name="delete" value="1">
        <input type="hidden" name="uid" value="<?= h($uid) ?>">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <button class="btn btn-outline-danger">Elimina addestramento</button>
      </form>
    </div>
  </div>

  <div class="row g-3 mt-2">
    <div class="col-lg-6">
      <div class="card shadow-sm">
        <div class="card-body">
        <form id="form-edit" method="post" class="row g-2">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="uid" value="<?= h($uid) ?>">
            <input type="hidden" name="id"  value="<?= h((string)$idParamStr) ?>">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">

            <div class="col-12">
              <label class="form-label">Inizio (data e ora)</label>
              <input id="inizio_dt" type="datetime-local" name="inizio_dt" class="form-control" value="<?= h(substr($inizioDT,0,16)) ?>" required>
            </div>
            <div class="col-12">
              <label class="form-label">Fine (data e ora)</label>
              <input id="fine_dt" type="datetime-local" name="fine_dt" class="form-control" value="<?= h(substr($fineDT,0,16)) ?>" required>
            </div>

            <div class="col-12 form-check mt-1">
              <input class="form-check-input" type="checkbox" id="chkRec" name="recupero" value="1" <?= $recupero0 ? 'checked' : '' ?>>
              <label class="form-check-label fw-semibold" for="chkRec">Addestramento di recupero</label>
              <div class="form-text">
                Se attivo, per ciascun vigile verranno conteggiati al massimo i minuti disponibili nella
                <strong>Banca ore (ultimi 12 mesi)</strong>
                <span class="text-muted">(soglia: <em>5h × mesi rilevanti</em>, esclusi mesi di infortunio e pre-ingresso)</span>.
              </div>
            </div>

            <div class="col-12">
              <label class="form-label mb-1">Attività</label>
              <select class="form-select long-options" name="attivita_select" id="f_att_sel">
                <option value="">— Seleziona dal programma —</option>
                <?php foreach ($catalogoAtt as $lbl): ?>
                  <option value="<?= h($lbl) ?>" title="<?= h($lbl) ?>" <?= ($attivita0!=='' && $attivita0===$lbl)?'selected':'' ?>>
                    <?= h($lbl) ?>
                  </option>
                <?php endforeach; ?>
                <option value="__ALTRO__" <?= $usaAltro ? 'selected' : '' ?>>— Altro (scrivi sotto) —</option>
              </select>

              <input type="text" class="form-control mt-2 <?= $usaAltro ? '' : 'd-none' ?>"
                     id="f_att_custom" name="attivita_custom"
                     value="<?= $usaAltro ? h($attivita0) : '' ?>"
                     placeholder="Nuova attività…">

              <div class="form-check mt-2">
                <input class="form-check-input" type="checkbox" id="f_add_prog" name="add_to_program" value="1">
                <label class="form-check-label" for="f_add_prog">Aggiungi l’attività al programma</label>
              </div>
            </div>

            <div class="col-12">
              <label class="form-label">Note (opz.)</label>
              <textarea name="note" class="form-control" rows="2"><?= h($note0) ?></textarea>
            </div>
        </div>
      </div>
    </div>

    <div class="col-lg-6">
      <div class="card shadow-sm">
        <div class="card-body">
          <h5 class="card-title mb-2">Partecipanti</h5>
          <div class="text-muted small mb-2">
            <span class="me-3"><strong>Banca ore (ultimi 12 mesi)</strong> = max(0, <em>non-recupero − soglia</em>) − recuperi.</span><br>
            <em>Soglia</em> = 5h × mesi rilevanti (da ingresso, esclusi i mesi con infortunio/sospensione).<br>
            Con “<em>Recupero</em>” attivo: se <em>banca</em> &lt; durata, conteggia solo i minuti disponibili.
          </div>
          <div class="border rounded p-2" style="max-height:280px; overflow:auto;">
            <?php foreach ($vigiliOrdinati as $v):
              $id = (int)$v['id'];
              $label = h(($v['cognome']??'').' '.($v['nome']??''));
              $chk = isset($presenti[$id]) ? 'checked' : '';
              $bancaMin = (int)($BANCA[$id] ?? 0);
            ?>
              <div class="form-check">
                <input class="form-check-input vigile-checkbox" type="checkbox" name="vigili_presenti[]" id="v<?= $id ?>" value="<?= $id ?>" <?= $chk ?>>
                <label class="form-check-label" for="v<?= $id ?>">
                  <?= $label ?>
                  <span class="ms-1 badge rounded-pill bg-light text-muted small vigile-info" data-vid="<?= $id ?>">
                    Banca: <?= h(sprintf('%d:%02d', intdiv($bancaMin,60), $bancaMin%60)) ?>
                  </span>
                  <small class="badge rounded-pill text-bg-danger-subtle d-none badge-infortunio" data-vid="<?= $id ?>">Infortunio</small>
                </label>
              </div>
            <?php endforeach; ?>
          </div>
          <div class="mt-2 d-flex gap-2">
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="document.querySelectorAll('input[name=\'vigili_presenti[]\']').forEach(c=>{ if(!c.disabled) c.checked=true; })">Seleziona tutti</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="document.querySelectorAll('input[name=\'vigili_presenti[]\']').forEach(c=>c.checked=false)">Deseleziona tutti</button>
          </div>
          <div class="mt-2 text-muted small">
            <span class="me-3">Legenda:</span>
            <span class="fw-semibold text-success">Verde</span> = banca ≥ durata (recupero) •
            <span class="fw-semibold text-warning">Arancione</span> = 0 &lt; banca &lt; durata (recupero) •
            <span class="fw-semibold text-danger">Rosso</span> = banca = 0 (recupero)
          </div>
        </div>
      </div>
    </div>

    <div class="col-12">
      <div class="d-grid">
        <button class="btn btn-primary">Salva modifiche</button>
      </div>
      </form>
    </div>
  </div>
</div>

<script>
(function(){
  const sel  = document.getElementById('f_att_sel');
  const txt  = document.getElementById('f_att_custom');
  function toggleAltro(){ if(!sel||!txt) return; (sel.value==='__ALTRO__') ? txt.classList.remove('d-none') : txt.classList.add('d-none'); }
  sel?.addEventListener('change', toggleAltro); toggleAltro();
})();

(() => {
  const BANCA    = <?= json_encode($BANCA, JSON_UNESCAPED_UNICODE) ?>;
  const INFORTUNI= <?= json_encode([]) ?>; // opzionale: popola se usi gli infortuni anche qui

  const inpInizio = document.getElementById('inizio_dt');
  const inpFine   = document.getElementById('fine_dt');
  const chkRec    = document.getElementById('chkRec');
  const chks      = Array.from(document.querySelectorAll('input.vigile-checkbox'));

  function parseDatetimeLocal(v){
    if (!v || !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/.test(v)) return null;
    const val = v.length === 16 ? v + ':00' : v;
    const d = new Date(val.replace('T',' ') + '');
    return isNaN(d.getTime()) ? null : d;
  }
  function durataSessioneMin(){
    const s = parseDatetimeLocal(inpInizio.value);
    const e = parseDatetimeLocal(inpFine.value);
    if (!s || !e) return null;
    const diff = (e - s) / 60000;
    return diff > 0 ? Math.round(diff) : null;
  }
  function hmm(min){ min = Math.max(0, parseInt(min||0,10)); const h=Math.floor(min/60), m=min%60; return h+':'+String(m).padStart(2,'0'); }
  function datePart(v){ return v ? v.split('T')[0] : null; }

  function aggiornaStato() {
    const dur = durataSessioneMin();
    const isRec = !!chkRec?.checked;

    chks.forEach(cb => {
      cb.classList.remove('is-invalid', 'is-valid', 'is-partial');
      const lab = document.querySelector(`label[for="${cb.id}"]`);
      if (lab) lab.classList.remove('text-danger', 'text-success', 'text-warning');
      const badge = lab ? lab.querySelector('.vigile-info[data-vid="'+cb.value+'"]') : null;
      if (badge) { const bank=BANCA[cb.value]||0; badge.textContent = 'Banca: '+hmm(bank); badge.classList.remove('text-danger','text-success','text-warning'); }
    });

    if (dur === null) return;

    chks.forEach(cb => {
      const vid   = parseInt(cb.value, 10);
      const bank  = parseInt(BANCA[vid] || 0, 10);
      const lab   = document.querySelector(`label[for="${cb.id}"]`);
      const badge = lab ? lab.querySelector('.vigile-info[data-vid="'+vid+'"]') : null;

      if (isRec && badge) {
        const eff = Math.min(bank, dur);
        badge.textContent = `Banca: ${hmm(bank)} • Richiesti: ${hmm(dur)} • Conteggio: ${hmm(eff)}`;
        badge.classList.remove('text-danger','text-success','text-warning');
        if (bank >= dur)      badge.classList.add('text-success');
        else if (bank > 0)    badge.classList.add('text-warning');
        else                  badge.classList.add('text-danger');
      }

      if (cb.checked && isRec) {
        if (bank >= dur)      { lab?.classList.add('text-success'); cb.classList.add('is-valid'); }
        else if (bank > 0)    { lab?.classList.add('text-warning'); cb.classList.add('is-partial'); }
        else                  { lab?.classList.add('text-danger');  cb.classList.add('is-invalid'); }
      }
    });
  }

  inpInizio?.addEventListener('input', aggiornaStato);
  inpFine?.addEventListener('input', aggiornaStato);
  chkRec?.addEventListener('change', aggiornaStato);
  chks.forEach(cb => cb.addEventListener('change', aggiornaStato));
  aggiornaStato();
})();
</script>
</body>
</html>