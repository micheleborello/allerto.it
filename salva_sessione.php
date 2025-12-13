<?php
// salva_sessione.php — inserisce una *nuova* sessione di addestramento
// - Crea un unico sessione_uid e lo salva su tutte le righe dei vigili selezionati.
// - Permette presenti=0 (capo/non-capo). Aggiunge SEMPRE una riga "template" per il QR.
// - Solo il CAPO può inserire manualmente i presenti; i non-capo: presenze solo via QR.

ob_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

require __DIR__.'/auth.php';
require __DIR__.'/tenant_bootstrap.php'; // definisce DATA_DIR / sessione tenant
if (function_exists('require_tenant_user')) { require_tenant_user(); }
if (function_exists('require_mfa')) { require_mfa(); }

require __DIR__.'/storage.php';
require __DIR__.'/utils.php';
if (is_file(__DIR__.'/event_log.php')) require __DIR__.'/event_log.php';

/* -----------------------------------------------------------------
   SHIM next_id(): calcola il prossimo ID come (max esistente + 1)
------------------------------------------------------------------ */
if (!function_exists('next_id')) {
  function next_id(array $rows): int {
    $max = 0;
    foreach ($rows as $r) {
      $id = (int)($r['id'] ?? 0);
      if ($id > $max) $max = $id;
    }
    return $max + 1;
  }
}

/* -----------------------------------------------------------------
   Tenant attivo (slug) — usalo sia in lettura che in scrittura
------------------------------------------------------------------ */
$tenant = $_SESSION['tenant_slug'] ?? $_SESSION['CASERMA_SLUG'] ?? (function_exists('storage_current_tenant') ? storage_current_tenant() : 'default');

/* -----------------------------------------------------------------
   SHIM save_json_atomic() (alcune utility lo usano)
------------------------------------------------------------------ */
if (!function_exists('save_json_atomic')) {
  function save_json_atomic(string $pathOrArrayFile, $data): void {
    if (str_starts_with($pathOrArrayFile, DIRECTORY_SEPARATOR) || preg_match('~^[A-Z]:\\\\~i', $pathOrArrayFile)) {
      $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      if ($json === false) throw new RuntimeException('Impossibile serializzare il JSON.');
      $tmp = $pathOrArrayFile . '.tmp';
      if (@file_put_contents($tmp, $json) === false) throw new RuntimeException('Scrittura file temporaneo fallita.');
      @chmod($tmp, 0664);
      if (!@rename($tmp, $pathOrArrayFile)) { @unlink($tmp); throw new RuntimeException('Rinomina atomica fallita.'); }
      return;
    }
    if (function_exists('write_json')) {
      write_json($pathOrArrayFile, is_array($data) ? $data : [], $GLOBALS['tenant'] ?? null);
      return;
    }
    throw new RuntimeException('save_json_atomic: impossibile determinare il metodo di salvataggio.');
  }
}

/* -----------------------------------------------------------------
   Catalogo attività (formato [{label,tipo}]); tenant-aware
------------------------------------------------------------------ */
const TIPI_ATT = ['pratica','teoria','varie'];
function norm_tipo($t){ $t = strtolower(trim((string)$t)); return in_array($t, TIPI_ATT, true) ? $t : 'varie'; }

function load_attivita_catalogo(): array {
  if (function_exists('read_json')) {
    $rows = read_json('attivita.json', $GLOBALS['tenant'] ?? null);
  } else {
    $path = (defined('DATA_DIR') ? DATA_DIR : (__DIR__.'/data')).'/attivita.json';
    $rows = json_decode(@file_get_contents($path), true) ?: [];
  }
  if (!is_array($rows)) $rows = [];
  $out = [];
  foreach ($rows as $x) {
    if (is_string($x)) {
      $lab = trim($x);
      if ($lab !== '') $out[] = ['label'=>$lab,'tipo'=>'varie'];
    } elseif (is_array($x)) {
      $lab = trim((string)($x['label'] ?? ''));
      $tp  = norm_tipo($x['tipo'] ?? 'varie');
      if ($lab !== '') $out[] = ['label'=>$lab,'tipo'=>$tp];
    }
  }
  $seen=[]; $clean=[];
  foreach ($out as $r) {
    $k = mb_strtolower($r['label'],'UTF-8');
    if (isset($seen[$k])) continue;
    $seen[$k]=1; $clean[]=$r;
  }
  usort($clean, fn($a,$b)=> strcasecmp($a['label'],$b['label']));
  return $clean;
}
function save_attivita_catalogo(array $items): void {
  $norm=[]; $seen=[];
  foreach ($items as $r) {
    $lab = trim((string)($r['label'] ?? '')); if ($lab==='') continue;
    $tp  = norm_tipo($r['tipo'] ?? 'varie');
    $k   = mb_strtolower($lab,'UTF-8'); if (isset($seen[$k])) continue;
    $seen[$k]=1; $norm[]=['label'=>$lab,'tipo'=>$tp];
  }
  usort($norm, fn($a,$b)=> strcasecmp($a['label'],$b['label']));
  if (function_exists('write_json')) {
    write_json('attivita.json', $norm, $GLOBALS['tenant'] ?? null);
  } else {
    $path = (defined('DATA_DIR') ? DATA_DIR : (__DIR__.'/data')).'/attivita.json';
    save_json_atomic($path, $norm);
  }
}
function add_to_attivita_catalogo(string $label, string $tipo='varie'): void {
  $label = trim($label); if ($label==='') return;
  $tipo  = norm_tipo($tipo);
  $all   = load_attivita_catalogo();
  $kNew  = mb_strtolower($label,'UTF-8');
  $found = false;
  foreach ($all as &$r) {
    if (mb_strtolower($r['label'],'UTF-8') === $kNew) { $r['tipo'] = $r['tipo'] ?? 'varie'; $found = true; break; }
  }
  unset($r);
  if (!$found) $all[] = ['label'=>$label,'tipo'=>$tipo];
  save_attivita_catalogo($all);
}

/* -----------------------------------------------------------------
   Solo POST
------------------------------------------------------------------ */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo 'Metodo non consentito';
  exit;
}

try {
  // ------ Lettura input -------
  $inizioDT   = trim((string)($_POST['inizio_dt'] ?? ''));
  $fineDT     = trim((string)($_POST['fine_dt']   ?? ''));
  $isRecupero = isset($_POST['recupero']) ? 1 : 0;

  // Attività: select oppure "Altro"
  $att_sel  = trim((string)($_POST['attivita_select'] ?? ''));
  $att_cus  = trim(preg_replace('/\s+/', ' ', (string)($_POST['attivita_custom'] ?? '')));
  $attivita = ($att_sel !== '' && $att_sel !== '__ALTRO__') ? $att_sel : $att_cus;

  $addProg  = isset($_POST['add_to_program']) ? 1 : 0;
  $att_tipo = norm_tipo($_POST['attivita_tipo'] ?? 'varie'); // da index con "Altro + aggiungi"
  $note     = trim(preg_replace('/\s+/', ' ', (string)($_POST['note'] ?? '')));

  // Selezioni presenti (checkbox)
  $selez = $_POST['vigili_presenti'] ?? [];
  if (!is_array($selez)) $selez = [];
  $selez = array_values(array_unique(array_map('intval', $selez)));

  // Solo il CAPO può inserire manualmente i presenti.
  // Se l'UI era quella "da capo", arriva is_capo_ui=1 e ci fidiamo.
  // Altrimenti accettiamo anche permessi di editing equivalenti (fallback).
  $capo = false;
  if (isset($_POST['is_capo_ui']) && $_POST['is_capo_ui'] === '1') {
    $capo = true;
  } elseif (function_exists('auth_can')) {
    $capo = (bool)(
      auth_can('capo:*') ||
      auth_can('edit:index') ||
      auth_can('edit:addestramenti') ||
      auth_can('presenze:write')
    );
  } else {
    $capo = true; // ambiente senza ACL: consenti
  }

  if (!$capo) {
    $selez = []; // i non-capo non possono impostare presenze manuali
  }

  // Validazioni base su datetime
  if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?$/', $inizioDT)) throw new InvalidArgumentException('Inizio non valido.');
  if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?$/', $fineDT))   throw new InvalidArgumentException('Fine non valido.');

  // Normalizzatore ":ss"
  $fix = fn(string $s) => (strlen($s) === 16 ? $s . ':00' : $s);

  // ------ Durata -------
  $minuti = minuti_da_intervallo_datetime($inizioDT, $fineDT);
  if ($minuti <= 0) throw new InvalidArgumentException('Intervallo orario non valido.');

  // ------ Timestamp per overlap -------
  $tz = new DateTimeZone(date_default_timezone_get());
  $startTs = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', $fix($inizioDT), $tz)->getTimestamp();
  $endTs   = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', $fix($fineDT),   $tz)->getTimestamp();

  // ------ Carica addestramenti esistenti (TENANT) -------
  if (!function_exists('get_addestramenti')) throw new RuntimeException('get_addestramenti() non disponibile (storage.php).');
  $items = get_addestramenti($tenant);
  if (!is_array($items)) $items = [];

  // ------ Controllo sovrapposizioni -------
  foreach ($selez as $vid) {
    foreach ($items as $it) {
      if ((int)($it['vigile_id'] ?? 0) !== (int)$vid) continue;
      $s = $e = null;
      if (isset($it['inizio_dt'], $it['fine_dt'])) {
        $s = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', $fix((string)$it['inizio_dt']), $tz);
        $e = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', $fix((string)$it['fine_dt']),   $tz);
      } else {
        $d   = (string)($it['data'] ?? '');
        $hh1 = substr((string)($it['inizio'] ?? '00:00'), 0, 5);
        $hh2 = substr((string)($it['fine']   ?? '00:00'), 0, 5);
        $s = DateTimeImmutable::createFromFormat('Y-m-d H:i', $d.' '.$hh1, $tz);
        $e = DateTimeImmutable::createFromFormat('Y-m-d H:i', $d.' '.$hh2, $tz);
        if ($s && $e && $e <= $s) $e = $e->add(new DateInterval('P1D'));
      }
      if (!$s || !$e) continue;
      if (max($startTs, $s->getTimestamp()) < min($endTs, $e->getTimestamp())) {
        throw new InvalidArgumentException("Sovrapposizione oraria per il vigile ID $vid con un addestramento già registrato.");
      }
    }
  }

  // ------ UID di sessione -------
  $sessionUID = bin2hex(random_bytes(8));

  // ------ Normalizzazioni per storage -------
  $data = substr($inizioDT, 0, 10);
  $toHH = fn(string $dt) => substr($fix($dt), 11, 5);

  // ------ CREA SEMPRE UNA RIGA "TEMPLATE" DI SESSIONE (per QR) ------
  $idTemplate = next_id($items);
  $items[] = [
    'id'           => $idTemplate,
    'sessione_uid' => $sessionUID,
    'type'         => 'sessione',            // marker utile per letture future
    'titolo'       => ($attivita !== '' ? $attivita : 'Sessione addestramento'),
    'data'         => $data,
    'inizio'       => $toHH($inizioDT),
    'fine'         => $toHH($fineDT),
    'inizio_dt'    => $fix($inizioDT),
    'fine_dt'      => $fix($fineDT),
    'minuti'       => $minuti,
    'attivita'     => ($attivita !== '' ? $attivita : null),
    'note'         => ($note !== '' ? $note : null),
    'recupero'     => $isRecupero ? 1 : 0,
    'created_at'   => date('Y-m-d H:i:s'),
  ];

  // ------ Appendi le righe per tutti i presenti (solo se CAPO) -------
  foreach ($selez as $vid) {
    $id = next_id($items);
    $items[] = [
      'id'           => $id,
      'sessione_uid' => $sessionUID,
      'vigile_id'    => (int)$vid,
      'data'         => $data,
      'inizio'       => $toHH($inizioDT),
      'fine'         => $toHH($fineDT),
      'inizio_dt'    => $fix($inizioDT),
      'fine_dt'      => $fix($fineDT),
      'minuti'       => $minuti,
      'attivita'     => ($attivita !== '' ? $attivita : null),
      'note'         => ($note !== '' ? $note : null),
      'created_at'   => date('Y-m-d H:i:s'),
      'recupero'     => $isRecupero ? 1 : 0,
    ];
  }

  // ------ Salva (TENANT) -------
  if (!function_exists('put_addestramenti')) throw new RuntimeException('put_addestramenti() non disponibile (storage.php).');
  put_addestramenti($items, $tenant);

  // ------ (Opzionale) aggiorna il catalogo attività -------
  if ($addProg && $attivita !== '') {
    add_to_attivita_catalogo($attivita, $att_tipo ?: 'varie');
  }

  // ------ LOG: aggiungi anche i NOMI dei presenti (best-effort) -------
  if (function_exists('event_log_append')) {
    $vigili = function_exists('get_vigili') ? get_vigili($tenant) : (json_decode(@file_get_contents((defined('DATA_DIR')?DATA_DIR:__DIR__.'/data').'/vigili.json'), true) ?: []);
    $id2nome = [];
    foreach ($vigili as $v) { $id2nome[(int)($v['id'] ?? 0)] = trim(($v['nome'] ?? '').' '.($v['cognome'] ?? '')); }
    $presenti = [];
    foreach ($selez as $vid) { $presenti[] = ['vigile_id'=>(int)$vid, 'nome'=>$id2nome[(int)$vid] ?? '']; }

    event_log_append('addestramento.create', [
      'sessione_uid' => $sessionUID,
      'data'         => $data,
      'inizio_dt'    => $fix($inizioDT),
      'fine_dt'      => $fix($fineDT),
      'minuti'       => $minuti,
      'attivita'     => $attivita,
      'recupero'     => (bool)$isRecupero,
      'presenti'     => $presenti,
      'tenant'       => $tenant,
    ]);
  }

  // ------ Redirect: QR subito ------
  $slug = $tenant;
  header('Location: qr_view.php?uid=' . urlencode($sessionUID) . '&slug=' . urlencode($slug), true, 303);
  exit;

} catch (Throwable $e) {
  http_response_code(400);
  echo 'Errore: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
  exit;
}