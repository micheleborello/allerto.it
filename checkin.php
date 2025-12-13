<?php
// checkin.php — check-in pubblico tramite sessione vol_user (login user+password)

ini_set('display_errors', 1);
error_reporting(E_ALL);

require __DIR__.'/tenant_bootstrap.php';
require __DIR__.'/storage.php';
require __DIR__.'/utils.php';

if (session_status() === PHP_SESSION_NONE) @session_start();

/* ========= Parametri da QR ========= */
$uid  = isset($_GET['uid'])  ? trim((string)$_GET['uid'])  : '';
$slug = isset($_GET['slug']) ? preg_replace('/[^a-z0-9_-]/i','', (string)$_GET['slug']) : '';
$goto = isset($_GET['goto']) ? trim((string)$_GET['goto']) : ''; // es. 'profilo' per redirect

/* ========= Tenant risolto come negli altri file ========= */
if (!function_exists('tenant_active_slug')) {
  function tenant_active_slug(): string {
    return (string)($_SESSION['tenant_slug'] ?? $_SESSION['CASERMA_SLUG'] ?? 'default');
  }
}
$tenant = $slug !== '' ? $slug : (function_exists('storage_current_tenant') ? storage_current_tenant() : tenant_active_slug());
$tenant = preg_replace('/[^a-z0-9_-]/i','', $tenant);

/* ========= Percorsi COERENTI con vigili.php/riepilogo.php ========= */
if (!defined('DATA_CASERME_BASE')) {
  define('DATA_CASERME_BASE', __DIR__ . '/data');
}
$__dir = rtrim(DATA_CASERME_BASE,'/').'/'.$tenant;
if (!defined('DATA_DIR')) define('DATA_DIR', $__dir);

if (!defined('VIGILI_JSON'))    define('VIGILI_JSON',    $__dir.'/vigili.json');
if (!defined('ADDESTR_JSON'))   define('ADDESTR_JSON',   $__dir.'/addestramenti.json');

/* ========= IO JSON tolleranti (fallback se storage.php non li dà) ========= */
if (!function_exists('load_json')) {
  function load_json(string $path): array {
    if (!is_file($path)) return [];
    $s = @file_get_contents($path);
    if ($s === false) return [];
    $s = preg_replace('/^\xEF\xBB\xBF/', '', $s);           // BOM
    $s = preg_replace('/,\s*([\}\]])/m', '$1', $s);         // virgole finali
    $j = json_decode($s, true);
    return is_array($j) ? $j : [];
  }
}
if (!function_exists('save_json')) {
  function save_json(string $path, array $data): void {
    $dir = dirname($path);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $json = json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
    if ($json === false) throw new RuntimeException('JSON encoding fallita: '.json_last_error_msg());
    $tmp = $path.'.tmp-'.bin2hex(random_bytes(6));
    if (file_put_contents($tmp, $json) === false) throw new RuntimeException('Scrittura temporanea fallita');
    if (!@rename($tmp, $path)) { @unlink($tmp); throw new RuntimeException('Rename atomico fallito'); }
    @chmod($path, 0664);
  }
}
if (!function_exists('next_id')) {
  function next_id(array $rows): int {
    $max = 0; foreach ($rows as $r) { $id = (int)($r['id'] ?? 0); if ($id > $max) $max = $id; }
    return $max + 1;
  }
}

/* ========= Adapter di storage tenant-aware (se storage.php non li espone) ========= */
$HAS_STORAGE_FUNCS =
  function_exists('get_vigili') &&
  function_exists('get_addestramenti') &&
  function_exists('put_addestramenti');

$get_vigili_tenant = function(string $slug) use ($HAS_STORAGE_FUNCS) : array {
  if ($HAS_STORAGE_FUNCS) return get_vigili($slug);
  $dir = rtrim(DATA_CASERME_BASE,'/').'/'.$slug;
  return load_json($dir.'/vigili.json');
};
$get_addestr_tenant = function(string $slug) use ($HAS_STORAGE_FUNCS) : array {
  if ($HAS_STORAGE_FUNCS) return get_addestramenti($slug);
  $dir = rtrim(DATA_CASERME_BASE,'/').'/'.$slug;
  return load_json($dir.'/addestramenti.json');
};
$put_addestr_tenant = function(array $rows, string $slug) use ($HAS_STORAGE_FUNCS) : void {
  if ($HAS_STORAGE_FUNCS) { put_addestramenti($rows, $slug); return; }
  $dir = rtrim(DATA_CASERME_BASE,'/').'/'.$slug;
  save_json($dir.'/addestramenti.json', $rows);
};

/* ========= CSRF ========= */
if (empty($_SESSION['csrf_checkin'])) $_SESSION['csrf_checkin'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['csrf_checkin'];

$err = $ok = '';

/* ========= Serve un utente "vigile" autenticato (login pubblico) ========= */
$vol = $_SESSION['vol_user'] ?? null;
if (!$vol || ($vol['slug'] ?? '') !== $tenant) {
  // manda al login pubblico e poi torna qui
  $here = 'checkin.php?uid='.urlencode($uid).'&slug='.urlencode($tenant).($goto ? '&goto='.urlencode($goto) : '');
  header('Location: vol_login.php?slug='.urlencode($tenant).'&next='.urlencode($here));
  exit;
}

/* ========= Carica dati tenant-aware ========= */
$vigili = $get_vigili_tenant($tenant);
$items  = $get_addestr_tenant($tenant);

/* ========= Verifica utente esiste ed è attivo ========= */
$vid = (int)($vol['id'] ?? 0);
$me  = null;
foreach ($vigili as $v) {
  if ((int)($v['id'] ?? 0) === $vid && (int)($v['attivo'] ?? 1) === 1) { $me = $v; break; }
}
if (!$me) { $err = 'Utente non valido o non attivo.'; }

/* ========= Trova sessione master (qualsiasi riga con quel sessione_uid va bene) ========= */
$sessione = null;
if (!$err) {
  foreach ($items as $r) {
    if (($r['sessione_uid'] ?? '') === $uid) { $sessione = $r; break; }
  }
  if (!$sessione) { $err = 'Sessione non trovata. Verifica il QR.'; }
}

/* ========= Conferma presenza (POST) ========= */
if ($_SERVER['REQUEST_METHOD']==='POST' && !$err) {
  if (!hash_equals($CSRF, $_POST['csrf'] ?? '')) { http_response_code(400); die('Bad CSRF'); }

  // già presente?
  foreach ($items as $r) {
    if (($r['sessione_uid'] ?? '') === $uid && (int)($r['vigile_id'] ?? 0) === $vid) {
      $err = 'Presenza già registrata per questo addestramento.';
      break;
    }
  }

  if (!$err) {
    // Durata/minuti robusti dalla sessione
    $inizio = $sessione['inizio_dt'] ?? (($sessione['data'] ?? '').'T'.substr((string)($sessione['inizio'] ?? '00:00'),0,5).':00');
    $fine   = $sessione['fine_dt']   ?? (($sessione['data'] ?? '').'T'.substr((string)($sessione['fine']   ?? '00:00'),0,5).':00');
    try { $min = minuti_da_intervallo_datetime($inizio, $fine); } catch (\Throwable $e) { $min = (int)($sessione['minuti'] ?? 0); }
    if ($min <= 0) $min = 1; // evita 0

    // Aggiungi riga presenza con ID progressivo (tenant-aware)
    $id = next_id($items);
    $items[] = [
      'id'           => $id,
      'sessione_uid' => $uid,
      'vigile_id'    => $vid,
      'data'         => $sessione['data'] ?? substr($inizio,0,10),
      'inizio'       => substr($inizio,11,5),
      'fine'         => substr($fine,11,5),
      'inizio_dt'    => $inizio,
      'fine_dt'      => $fine,
      'attivita'     => (string)($sessione['attivita'] ?? ''),
      'recupero'     => (int)($sessione['recupero'] ?? 0),
      'minuti'       => (int)$min,
      'created_at'   => date('Y-m-d H:i:s'),
    ];

    // Salva nel JSON del tenant giusto
    $put_addestr_tenant($items, $tenant);

    // success
    $ok = 'Presenza registrata. Grazie, '.htmlspecialchars(trim(($me['cognome']??'').' '.($me['nome']??''))).'!';

    // Redirect automatico al profilo se richiesto da query ?goto=profilo
    if ($goto === 'profilo') {
      header('Location: dashboard_vigile.php');
      exit;
    }
  }
}

/* ========= UI ========= */
?>
<!doctype html>
<html lang="it"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Check-in Addestramento</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>body{background:#f7f9fc}</style>
</head>
<body class="d-flex align-items-center justify-content-center" style="min-height:100vh;">
<div class="container" style="max-width:520px">
  <div class="card shadow-sm">
    <div class="card-body">
      <h1 class="h5 mb-2">Check-in Addestramento</h1>
      <?php if ($err): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($err) ?></div><?php endif; ?>
      <?php if ($ok):  ?><div class="alert alert-success py-2"><?= htmlspecialchars($ok) ?></div><?php endif; ?>

      <?php if (!$err && $sessione): ?>
        <div class="mb-3 small text-muted">
          <div><b>Tu:</b> <?= htmlspecialchars(trim(($me['cognome']??'').' '.($me['nome']??''))) ?></div>
          <div><b>Attività:</b> <?= htmlspecialchars((string)($sessione['attivita'] ?? '—')) ?></div>
          <div><b>Data:</b> <?= htmlspecialchars((string)($sessione['data'] ?? substr((string)($sessione['inizio_dt'] ?? ''),0,10))) ?></div>
          <div><b>Orario:</b>
            <?= htmlspecialchars(substr((string)($sessione['inizio_dt'] ?? ''),11,5) ?: (string)($sessione['inizio'] ?? '')) ?>
             – <?= htmlspecialchars(substr((string)($sessione['fine_dt'] ?? ''),11,5)   ?: (string)($sessione['fine']   ?? '')) ?>
          </div>
        </div>

        <?php if (!$ok): ?>
        <form method="post" class="vstack gap-2">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF) ?>">
          <button class="btn btn-primary w-100">Conferma presenza</button>
          <div class="d-grid gap-2">
            <a class="btn btn-outline-secondary" href="vol_logout.php?slug=<?= urlencode($tenant) ?>">Esci</a>
            <a class="btn btn-outline-dark" href="dashboard_vigile.php">Vai al mio profilo</a>
          </div>
        </form>
        <?php else: ?>
          <div class="d-grid gap-2 mt-2">
            <a class="btn btn-outline-dark" href="dashboard_vigile.php">Vai al mio profilo</a>
            <a class="btn btn-outline-secondary" href="vol_logout.php?slug=<?= urlencode($tenant) ?>">Esci</a>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

  <p class="text-center mt-3">
    <a class="small text-decoration-none"
       href="vol_login.php?slug=<?= urlencode($tenant) ?>&next=<?= urlencode('checkin.php?uid='.$uid.'&slug='.$tenant.($goto?('&goto='.$goto):'')) ?>">
      Accedi con un altro profilo
    </a>
  </p>
</div>
</body></html>