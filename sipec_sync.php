<?php
// sipec_sync.php — importa da SIPEC e aggiorna vigili/personale + AUTO-PROVISION utenti per TUTTI i distaccamenti

ob_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

// ---- performance / timeout ----
@ini_set('max_execution_time', '300'); // 5 minuti
@set_time_limit(300);

require_once __DIR__.'/auth.php';
require_once __DIR__.'/tenant_bootstrap.php'; // per load_caserme/save_caserme se già definite

// === GATE di sicurezza: usa ciò che esiste nel tuo auth ===
if (function_exists('require_superadmin')) {
  require_superadmin();
} elseif (function_exists('require_perm')) {
  require_perm('admin:perms');
} else {
  http_response_code(403);
  die('Accesso negato: mancano i controlli di autenticazione.');
}

// ---- SHIM caserme: se tenant_bootstrap non ha (ancora) definito le funzioni, definiscile qui ----
if (!function_exists('load_caserme')) {
  function load_caserme(): array {
    $base = __DIR__ . '/data';
    $cfg  = $base . '/caserme.json';
    $out  = [];

    if (is_file($cfg)) {
      $raw = @file_get_contents($cfg);
      $arr = $raw ? json_decode($raw, true) : null;
      if (is_array($arr)) {
        foreach ($arr as $r) {
          $slug = preg_replace('/[^a-z0-9_-]/i', '', (string)($r['slug'] ?? ''));
          $name = trim((string)($r['name'] ?? ''));
          if ($slug === '') continue;
          if (is_dir($base.'/'.$slug)) {
            $out[$slug] = ['slug'=>$slug, 'name'=> ($name!=='' ? $name : $slug)];
          }
        }
      }
    }
    foreach (glob($base.'/*', GLOB_ONLYDIR) ?: [] as $dir) {
      $slug = basename($dir);
      if (!preg_match('/^[a-z0-9_-]+$/i', $slug)) continue;
      if (!isset($out[$slug]) && (is_file($dir.'/vigili.json') || is_file($dir.'/users.json'))) {
        $out[$slug] = ['slug'=>$slug, 'name'=>$slug];
      }
    }
    uasort($out, fn($a,$b)=>strcasecmp($a['name'],$b['name']));
    return array_values($out);
  }
}
if (!function_exists('save_caserme')) {
  function save_caserme(array $rows): void {
    $base = __DIR__ . '/data';
    @mkdir($base, 0775, true);
    $p = $base . '/caserme.json';
    $out = [];
    foreach ($rows as $r) {
      $slug = preg_replace('/[^a-z0-9_-]/i', '', (string)($r['slug'] ?? ''));
      $name = trim((string)($r['name'] ?? ''));
      if ($slug === '') continue;
      $out[] = ['slug'=>$slug, 'name'=> ($name!=='' ? $name : $slug)];
    }
    @file_put_contents($p, json_encode($out, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));
    @chmod($p, 0664);
  }
}

// ---------- CONFIG ----------
const SIPEC_URL = 'https://allerto.vvf.to.it/allerto/sipec';  // cambia se serve
$DATA_BASE = __DIR__ . '/data';
$MAP_FILE  = $DATA_BASE . '/cod_sede_map.json';

// Password/permesso iniziali per auto-provision
const DEFAULT_INITIAL_PASSWORD = '0000';
const DEFAULT_BASE_PERM = 'view:index';
const DEFAULT_PERMS = [
  'view:index',          // Vedi Dashboard
  'edit:index',          // Inserisci Addestramento Dashboard
  'view:addestramenti',  // Vedi Addestramenti
  'view:attivita',       // Vedi Attività
  'edit:attivita',       // Modifica Attività
];

// Pre-calcola UNA SOLA VOLTA l'hash della password iniziale (cost più basso per velocità)
$DEFAULT_HASH = password_hash(DEFAULT_INITIAL_PASSWORD, PASSWORD_BCRYPT, ['cost'=>8]);

// ---------- Helper JSON ----------
function _json_load_relaxed(string $path, $def) {
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
function _json_save_pretty(string $path, $arr): void {
  @mkdir(dirname($path), 0775, true);
  $json = json_encode($arr, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
  @file_put_contents($path, $json);
  @chmod($path, 0664);
}

// ---------- Sanitizzazione testo ----------
function sanitize_text($s): string {
  return html_entity_decode((string)$s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

// ---------- Date helpers ----------
function _parse_sipec_date(string $s): ?string {
  $s = trim($s);
  if ($s === '') return null;
  $mappa = [
    'GEN'=>'01','FEB'=>'02','MAR'=>'03','APR'=>'04','MAG'=>'05','GIU'=>'06',
    'LUG'=>'07','AGO'=>'08','SET'=>'09','OTT'=>'10','NOV'=>'11','DIC'=>'12',
  ];
  if (!preg_match('/^(\d{1,2})-([A-Z]{3})-(\d{2})$/u', strtoupper($s), $m)) return null;
  $d = (int)$m[1];
  $mon = $mappa[$m[2]] ?? null;
  $y2 = (int)$m[3];
  if (!$mon) return null;
  $y = 2000 + $y2;
  return sprintf('%04d-%02d-%02d', $y, (int)$mon, $d);
}

// ---------- Qualifica -> Grado ----------
function _map_grado(string $qualifica): string {
  $q = strtoupper(trim($qualifica));
  return match($q) {
    'VV'  => 'VIG.',
    'CSV' => 'CSV.',
    'CRV' => 'CRV.',
    'FTAV'=> 'FTAV.',
    default => $q,
  };
}

// ---------- Specializzazioni -> Patenti ministeriali ----------
function _patenti_from_special(array $specializzazioni): array {
  $out = [];
  foreach (($specializzazioni ?? []) as $sp) {
    $desc = strtoupper((string)($sp['descrizione'] ?? ''));
    if (preg_match('/AUTISTA DI\s+([123])\^/u', $desc, $m)) {
      $out[] = $m[1].'° grado';
    }
  }
  $out = array_values(array_unique($out));
  sort($out);
  return $out;
}

// ---------- Username helpers ----------
function _norm_ascii(string $s): string {
  // 1) decodifica entità HTML (es. &#039; -> ')
  $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
  $s = trim($s);

  // 2) minuscolo
  $s = mb_strtolower($s, 'UTF-8');

  // 3) rimuovi accenti / diacritici
  if (class_exists('Normalizer')) {
    $s = Normalizer::normalize($s, Normalizer::FORM_D);
    // elimina segni diacritici (Mn = Mark, Nonspacing)
    $s = preg_replace('/\p{Mn}+/u', '', $s);
    // ricomponi in NFC giusto per sicurezza
    $s = Normalizer::normalize($s, Normalizer::FORM_C);
  } elseif (function_exists('iconv')) {
    $tmp = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    if ($tmp !== false) $s = $tmp;
  }

  // 4) apostrofi e simili -> punto (o niente), poi tieni solo lettere, numeri e punto
  $s = str_replace(['’','‘','ʼ','´','`',"'"], '.', $s);
  $s = preg_replace('/[^a-z0-9.]+/', '.', $s);

  // 5) compatta punti e rimuovi punto iniziale/finale
  $s = preg_replace('/\.{2,}/', '.', $s);
  $s = trim($s, '.');

  return $s;
}

function _username_base_from_vigile(array $v): string {
  $cogn = _norm_ascii((string)($v['cognome'] ?? ''));
  $nome = _norm_ascii((string)($v['nome'] ?? ''));
  $cf   = _norm_ascii((string)($v['cf'] ?? ''));

  if ($cogn !== '' || $nome !== '') {
    $base = ($cogn !== '' ? $cogn : 'user') . '.' . ($nome !== '' ? $nome : 'x');
  } elseif ($cf !== '') {
    $base = $cf;
  } else {
    $base = 'user';
  }

  if ($base === '') $base = 'user';
  return $base;
}
function _unique_username(string $base, array $taken): string {
  $u = $base; $i = 1;
  while (isset($taken[strtolower($u)])) { $u = $base.$i; $i++; }
  return $u;
}

// ---------- Carica mappa cod_sede ----------
$codMap = _json_load_relaxed($MAP_FILE, [
  // puoi popolarla in data/cod_sede_map.json
]);

// ---------- Scarica SIPEC ----------
$ctx = stream_context_create(['http'=>['timeout'=>15, 'ignore_errors'=>true]]);
$raw = @file_get_contents(SIPEC_URL, false, $ctx);
if ($raw === false || trim($raw) === '') {
  die('Errore: impossibile scaricare dati da SIPEC.');
}
$data = json_decode($raw, true);
if (!is_array($data)) {
  die('Errore: risposta SIPEC non valida (JSON).');
}

// Accumulo caserme per aggiornare data/caserme.json a fine import
$casermeAttuali = [];
foreach (load_caserme() as $r) $casermeAttuali[$r['slug']] = $r['name'] ?? $r['slug'];

// Indice per distaccamento: cod_sede => array di records
$bySede = [];
foreach ($data as $row) {
  $cod = trim((string)($row['cod_sede'] ?? ''));
  if ($cod === '') continue;
  $bySede[$cod][] = $row;
}

// ---------- Cicla per ciascun distaccamento ----------
foreach ($bySede as $codSede => $rows) {
  // Risolvi slug e nome
  if (isset($codMap[$codSede])) {
    $slug = preg_replace('/[^a-z0-9_-]/i','', (string)($codMap[$codSede]['slug'] ?? ''));
    $name = trim((string)($codMap[$codSede]['name'] ?? $slug));
    if ($slug === '') $slug = 's'.$codSede;
  } else {
    $slug = 's'.$codSede;
    $name = 'Distaccamento '.$codSede;
    $codMap[$codSede] = ['slug'=>$slug, 'name'=>$name];
  }

  // Assicurati cartella e seed
  $dir = $DATA_BASE . '/' . $slug;
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  foreach ([
    'vigili.json'        => "[]",
    'personale.json'     => "{}",
    'addestramenti.json' => "[]",
    'infortuni.json'     => "[]",
    'attivita.json'      => "[]",
    'users.json'         => "[]",
  ] as $fn=>$seed) {
    $p = $dir.'/'.$fn;
    if (!is_file($p)) { @file_put_contents($p, $seed); @chmod($p,0664); }
  }

  // Path file distaccamento
  $vigiliPath     = $dir.'/vigili.json';
  $personalePath  = $dir.'/personale.json';
  $usersPath      = $dir.'/users.json';

  // Carica stati correnti
  $vigili    = _json_load_relaxed($vigiliPath, []);
  $personale = _json_load_relaxed($personalePath, []);
  $users     = _json_load_relaxed($usersPath, []);

  // Indici rapidi esistenti
  $idxByCF = []; // cf -> id
  $maxId = 0;
  foreach ($vigili as $v) {
    $id = (int)($v['id'] ?? 0);
    if ($id > $maxId) $maxId = $id;
    $cf = strtoupper(trim((string)($v['cf'] ?? '')));
    if ($cf !== '') $idxByCF[$cf] = $id;
  }

  // ====== Import persone della sede ======
  foreach ($rows as $r) {
    $nome    = trim((string)($r['nome'] ?? ''));
    $cognome = trim((string)($r['cognome'] ?? ''));
    $cf      = strtoupper(trim((string)($r['cf'] ?? '')));
    if ($cf === '' && ($nome==='' || $cognome==='')) continue; // serve almeno CF o nome+cognome

    // Trova/crea ID vigile
    $id = $idxByCF[$cf] ?? 0;
    if ($id <= 0) {
      foreach ($vigili as $vv) {
        if (strcasecmp($vv['nome'] ?? '', $nome)===0 && strcasecmp($vv['cognome'] ?? '', $cognome)===0) {
          $id = (int)($vv['id'] ?? 0);
          if ($id > 0) break;
        }
      }
    }
    if ($id <= 0) {
      $id = ++$maxId;
      $vigili[] = [
        'id'        => $id,
        'nome'      => $nome,
        'cognome'   => $cognome,
        'cf'        => $cf,
        'attivo'    => 1,
        'grado'     => _map_grado((string)($r['qualifica'] ?? '')),
        'ingresso'  => '',
      ];
      if ($cf !== '') $idxByCF[$cf] = $id;
    } else {
      foreach ($vigili as &$vv) {
        if ((int)($vv['id'] ?? 0) === $id) {
          if ($nome    !== '') $vv['nome']    = $nome;
          if ($cognome !== '') $vv['cognome'] = $cognome;
          if ($cf      !== '') $vv['cf']      = $cf;
          $vv['attivo'] = 1;
          $vv['grado']  = _map_grado((string)($r['qualifica'] ?? ($vv['grado'] ?? '')));
          break;
        }
      }
      unset($vv);
    }

    // Aggiorna PERSONALE
    if (!isset($personale[$id]) || !is_array($personale[$id])) $personale[$id] = [];
    $P = &$personale[$id];

    $tel1 = trim((string)($r['telefoni'][0]['tel1'] ?? ''));
    $tel2 = trim((string)($r['telefoni'][0]['tel2'] ?? ''));
    $tel3 = trim((string)($r['telefoni'][0]['tel3'] ?? ''));
    $tel_candidates = array_filter([$tel1, $tel2, $tel3], fn($t) => $t !== '' && !preg_match('/^0[0-9]/', $t));
    $cell = reset($tel_candidates) ?: ($tel1 ?: ($tel2 ?: $tel3));
    $P['contatti'] = $P['contatti'] ?? [];
    if ($cell !== '') $P['contatti']['telefono'] = $cell;
    if ($tel2 !== '') $P['contatti']['telefono1'] = $tel2;
    if ($tel3 !== '') $P['contatti']['telefono2'] = $tel3;

    $P['indirizzo'] = $P['indirizzo'] ?? [];
    $via = trim((string)($r['via'] ?? ''));
    $cap = trim((string)($r['cap'] ?? ''));
    $com = trim((string)($r['residenza_comune'] ?? ''));
    if ($via !== '') $P['indirizzo']['via'] = $via;
    if ($cap !== '') $P['indirizzo']['cap'] = $cap;
    if ($com !== '') $P['indirizzo']['comune'] = $com;

    $P['grado'] = _map_grado((string)($r['qualifica'] ?? ($P['grado'] ?? '')));
    $P['cod_sede_aggregato'] = trim((string)($r['cod_sede_aggregato'] ?? ''));

    $P['scadenze'] = $P['scadenze'] ?? [];
    $ultima   = _parse_sipec_date((string)($r['data_certificazione'] ?? ''));
    $scadenza = _parse_sipec_date((string)($r['data_certificazione_scadenza'] ?? ''));
    if ($ultima   !== null) $P['scadenze']['visita_medica_ultima'] = $ultima;
    if ($scadenza !== null) $P['scadenze']['visita_medica']        = $scadenza;

    // Scadenza patente ministeriale e gradi AM1/AM2/AM3
    $grades = [];
    $pmExpiry = null;
    $abilDesc = [];
    foreach ((array)($r['specializzazioni'] ?? []) as $sp) {
      $cod = strtoupper((string)($sp['codice'] ?? ''));
      $desc = trim((string)($sp['descrizione'] ?? ''));
      if ($desc !== '') $abilDesc[] = $desc;
      if ($cod === 'AM1') $grades[] = '1° grado';
      if ($cod === 'AM2') $grades[] = '2° grado';
      if ($cod === 'AM3') $grades[] = '3° grado';
      $f = _parse_sipec_date((string)($sp['fine'] ?? ''));
      if ($f) {
        if ($pmExpiry === null || strcmp($f, $pmExpiry) > 0) $pmExpiry = $f;
      }
    }
    $grades = array_values(array_unique($grades));
    $P['patenti'] = $grades ?: ['Nessuna'];
    $P['abilitazioni'] = $abilDesc;
    if ($pmExpiry) $P['scadenze']['patente_b'] = $pmExpiry;

    // Date aggiuntive
    $P['data_inizio_qualifica'] = _parse_sipec_date((string)($r['dataInizioQualifica'] ?? ($P['data_inizio_qualifica'] ?? '')));
    $P['ingresso'] = _parse_sipec_date((string)($r['dataAssunzione'] ?? ($P['ingresso'] ?? '')));

    unset($P);
  }

  // ordina vigili e salva
  usort($vigili, fn($a,$b)=> strcasecmp(($a['cognome']??'').' '.($a['nome']??''), ($b['cognome']??'').' '.($b['nome']??'')));
  _json_save_pretty($vigiliPath, $vigili);
  _json_save_pretty($personalePath, $personale);

  // === Provision UTENTI solo se mancano (non tocca password/perms/capo esistenti) ===
  $usersByUsername = [];
  $taken = [];
  foreach ($users as $u) {
    $un = strtolower(trim((string)($u['username'] ?? '')));
    if ($un === '') continue;
    if (!isset($usersByUsername[$un])) $usersByUsername[$un] = $u;
    $taken[$un] = 1;
  }

  $newUsersAdded = false;
  $changedVigili = false;

  foreach ($vigili as &$vv) {
    $curUser = trim((string)($vv['username'] ?? ''));
    if ($curUser === '') {
      // genera username stabile se manca
      $base = _username_base_from_vigile($vv);
      $candidate = _unique_username($base, $taken);
      $vv['username'] = $candidate;
      $curUser = $candidate;
      $changedVigili = true;
    }
    $lc = strtolower($curUser);
    if ($lc === '') continue;
    if (!isset($usersByUsername[$lc])) {
      // crea nuovo utente con permessi di default; capo resta 0
      $users[] = [
        'username'      => $curUser,
        'password_hash' => $DEFAULT_HASH,
        'is_capo'       => 0,
        'perms'         => DEFAULT_PERMS,
      ];
      $usersByUsername[$lc] = end($users);
      $taken[$lc] = 1;
      $newUsersAdded = true;
    }
  }
  unset($vv);

  if ($changedVigili) {
    _json_save_pretty($vigiliPath, $vigili);
  }
  if ($newUsersAdded) {
    // Salva utenti aggiungendo solo i nuovi, senza toccare quelli esistenti
    _json_save_pretty($usersPath, $users);
  }

  // Aggiorna elenco caserme globale
  $casermeAttuali[$slug] = $name;
}

// Salva mappa cod_sede
_json_save_pretty($MAP_FILE, $codMap);

// Salva caserme.json globale (solo cartelle esistenti)
$casermeRows = [];
foreach ($casermeAttuali as $slug=>$name) {
  if (is_dir($DATA_BASE.'/'.$slug)) {
    $casermeRows[] = ['slug'=>$slug, 'name'=>$name];
  }
}
_json_save_pretty($DATA_BASE.'/caserme.json', $casermeRows);

// redirect di cortesia
header('Location: caserme.php?msg=sipec_ok');
exit;
