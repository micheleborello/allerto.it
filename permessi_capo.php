<?php
// permessi_capo.php — gestione permessi utenti del distaccamento (senza sezione Vigili)
ob_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__.'/auth.php';
require_once __DIR__.'/tenant_bootstrap.php';
require_once __DIR__.'/storage.php';

if (function_exists('require_tenant_user')) require_tenant_user();
if (function_exists('require_mfa')) require_mfa();

$okAdmin = false;
if (function_exists('auth_is_superadmin') && auth_is_superadmin()) $okAdmin = true;
if (function_exists('auth_can') && auth_can('admin:perms'))        $okAdmin = true;
if (!$okAdmin) { http_response_code(403); exit('Accesso negato: permesso mancante (admin:perms).'); }

if (session_status() === PHP_SESSION_NONE) session_start();

/* ---------- Slug & paths ---------- */
$slug = function_exists('tenant_active_slug') ? tenant_active_slug() : ($_SESSION['tenant_slug'] ?? '');
$slug = preg_replace('/[^a-z0-9_-]/i', '', (string)$slug);
if ($slug === '') { http_response_code(500); exit('Tenant non determinato.'); }

$tenantUsersPath = __DIR__."/data/{$slug}/users.json";
$globalUsersPath = __DIR__."/data/users.json";
@mkdir(dirname($tenantUsersPath), 0775, true);
if (!is_file($tenantUsersPath)) { @file_put_contents($tenantUsersPath, "[]"); @chmod($tenantUsersPath,0664); }

/* ---------- Permessi selezionabili ---------- */
$PERM_OPTS = [
  'view:index'         => 'Vedi Dashboard',
  'edit:index'         => 'Inserisci Addestramento Dashboard',
  'view:addestramenti' => 'Vedi Addestramenti',
  'edit:addestramenti' => 'Modifica Addestramenti',
  'view:personale'     => 'Vedi Personale',
  'edit:personale'     => 'Modifica Personale',
  'view:attivita'      => 'Vedi Attività',
  'edit:attivita'      => 'Modifica Attività',
  'view:infortuni'     => 'Vedi Infortuni',
  'edit:infortuni'     => 'Modifica Infortuni',
  'view:riepilogo'     => 'Vedi Riepilogo',
  'edit:riepilogo'     => 'Modifica Riepilogo',
  'export:pdf'         => 'Esporta PDF',
  'admin:perms'        => 'Gestione Permessi',
  'admin:pins'         => 'Gestione PIN',
];

/* ---------- Helpers ---------- */
if (!function_exists('checked')) { function checked($c){ return $c?'checked':''; } }

function users_decode_any($json) {
  if (!is_string($json) || $json==='') return [];
  $data = json_decode($json, true);
  if (is_array($data)) return $data;
  $s = preg_replace('/^\xEF\xBB\xBF/', '', $json);
  $s = preg_replace('/,\s*([\}\]])/m', '$1', $s);
  $data = json_decode($s, true);
  if (is_array($data)) return $data;
  return null;
}

function normalize_users_list($rows) {
  if ($rows === null) return null;
  if (!is_array($rows)) return [];
  $list = [];
  if (array_values($rows) === $rows) {
    $list = $rows;
  } else {
    foreach ($rows as $k=>$v) {
      if (is_array($v)) { $v['username'] = $v['username'] ?? (string)$k; $list[] = $v; }
    }
  }
  $out=[]; $seen=[];
  foreach ($list as $u) {
    $un = strtolower(trim((string)($u['username'] ?? '')));
    if ($un==='' || isset($seen[$un])) continue;
    $seen[$un]=1;
    $out[] = [
      'username'      => $un,
      'password_hash' => (string)($u['password_hash'] ?? ''),
      'is_capo'       => !empty($u['is_capo']) ? 1 : 0,
      'perms'         => is_array($u['perms'] ?? []) ? array_values(array_unique(array_map('strval',$u['perms']))) : [],
    ];
  }
  usort($out, fn($a,$b)=>strcasecmp($a['username'],$b['username']));
  return $out;
}

function load_users_any(string $path, &$debug = null): array {
  $debug = ['exists'=>is_file($path), 'size'=>0, 'valid'=>true];
  if (!is_file($path)) return [];
  $raw = @file_get_contents($path);
  if ($raw === false) { $debug['valid']=false; return []; }
  $debug['size'] = strlen($raw);
  $rows = users_decode_any($raw);
  if ($rows === null) { $debug['valid']=false; return []; }
  $norm = normalize_users_list($rows);
  if ($norm === null) { $debug['valid']=false; return []; }
  return $norm;
}

function save_json_atomic_raw(string $path, array $data): void {
  @mkdir(dirname($path), 0775, true);
  $json = json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
  if ($json === false) throw new RuntimeException('JSON encoding fallito: '.json_last_error_msg());
  $tmp = $path.'.tmp-'.bin2hex(random_bytes(6));
  $fp  = @fopen($tmp,'wb'); if(!$fp) throw new RuntimeException('Impossibile aprire file temporaneo.');
  try{
    @flock($fp, LOCK_EX);
    if (@fwrite($fp,$json) === false) throw new RuntimeException('Scrittura temp fallita.');
    @fflush($fp); @flock($fp, LOCK_UN);
  } finally { @fclose($fp); }
  @chmod($tmp, 0664);
  if (!@rename($tmp, $path)) { @unlink($tmp); throw new RuntimeException('Rename atomico fallito.'); }
}

/* ---------- Caricamento: tenant -> fallback globale ---------- */
$dbgTenant = $dbgGlobal = null;
$usersTenant  = load_users_any($tenantUsersPath, $dbgTenant);
$usingGlobal  = false;
$users        = $usersTenant;

if (empty($usersTenant)) {
  $usersGlobal = load_users_any($globalUsersPath, $dbgGlobal);
  if (!empty($usersGlobal)) {
    $users = $usersGlobal;
    $usingGlobal = true; // mostro banner + tasto Importa
  }
}

$savedOk=false; $errMsg=''; $infoMsg='';

/* ---------- CSRF (stabile) ---------- */
if (empty($_SESSION['_csrf_perms'])) {
  $_SESSION['_csrf_perms'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['_csrf_perms'];

/* ---------- POST ---------- */
if ($_SERVER['REQUEST_METHOD']==='POST') {
  try {
    if (($_POST['_csrf'] ?? '') !== ($_SESSION['_csrf_perms'] ?? '')) throw new Exception('CSRF non valido.');

    // Import dal globale al tenant
    if (isset($_POST['action']) && $_POST['action']==='import_from_global') {
      $src = load_users_any($globalUsersPath, $dbgGlobal);
      if (empty($src)) throw new Exception('Nessun utente valido da importare.');
      save_json_atomic_raw($tenantUsersPath, $src);
      $users       = load_users_any($tenantUsersPath, $dbgTenant);
      $usingGlobal = false;
      $infoMsg     = 'Utenti importati nel distaccamento.';
      $_SESSION['_csrf_perms'] = bin2hex(random_bytes(16));
      $csrf = $_SESSION['_csrf_perms'];
    }

    // Salva permessi (sempre nel TENANT)
    if (isset($_POST['_save_perms'])) {
      $posted_is_capo = $_POST['is_capo'] ?? [];
      $posted_perms   = $_POST['perms'] ?? [];
      $allowedPerms   = array_keys($PERM_OPTS);
      $baseUsers      = $usingGlobal ? $users : $usersTenant;

      $newUsers = [];
      foreach ($baseUsers as $u) {
        $un = (string)($u['username'] ?? ''); if ($un==='') continue;
        $isCapo = isset($posted_is_capo[$un]);
        $pp = isset($posted_perms[$un]) && is_array($posted_perms[$un]) ? $posted_perms[$un] : [];
        $pp = array_values(array_unique(array_filter(array_map('strval', $pp), fn($p)=> in_array($p, $allowedPerms, true))));
        $newUsers[] = [
          'username'      => $un,
          'password_hash' => (string)($u['password_hash'] ?? ''),
          'is_capo'       => $isCapo ? 1 : 0,
          'perms'         => $pp,
        ];
      }

      save_json_atomic_raw($tenantUsersPath, $newUsers);
      $users       = load_users_any($tenantUsersPath, $dbgTenant);
      $usingGlobal = false;
      $savedOk     = true;

      $_SESSION['_csrf_perms'] = bin2hex(random_bytes(16));
      $csrf = $_SESSION['_csrf_perms'];
    }

  } catch(Throwable $e) {
    $errMsg = $e->getMessage();
  }
}

/* ---------- UI ---------- */
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Permessi Utenti — Distaccamento</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  .perm-grid{ display:grid; grid-template-columns: repeat(auto-fit,minmax(240px,1fr)); gap:.35rem .8rem; }
  .perm-chip{ display:flex; align-items:center; gap:.45rem; border:1px solid #d6dbe4; border-radius:.5rem; padding:.3rem .5rem; background:#fff; }
  .thead-sticky th{ position:sticky; top:0; background:#f8f9fa; z-index:2; }
</style>
</head>
<body class="bg-light">
<?php if (is_file(__DIR__.'/_menu.php')) include __DIR__.'/_menu.php'; ?>

<div class="container py-4">
  <h1 class="mb-2">Permessi Utenti</h1>

  <?php if ($usingGlobal): ?>
    <div class="alert alert-warning d-flex justify-content-between align-items-center">
      <div>
        Ho trovato utenti definiti globalmente; puoi importarli nel distaccamento e poi assegnare i permessi qui.
      </div>
      <form method="post" class="ms-3">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="action" value="import_from_global">
        <button class="btn btn-sm btn-primary">Importa nel distaccamento</button>
      </form>
    </div>
  <?php endif; ?>

  <?php if ($savedOk): ?><div class="alert alert-success">Permessi aggiornati.</div><?php endif; ?>
  <?php if ($infoMsg):  ?><div class="alert alert-info"><?= htmlspecialchars($infoMsg) ?></div><?php endif; ?>
  <?php if ($errMsg):   ?><div class="alert alert-danger"><?= htmlspecialchars($errMsg) ?></div><?php endif; ?>

  <form method="post" class="card shadow-sm">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
    <input type="hidden" name="_save_perms" value="1">
    <div class="table-responsive">
      <table class="table align-middle mb-0">
        <thead class="thead-sticky">
          <tr>
            <th style="min-width:180px">Utente</th>
            <th style="width:100px">Capo</th>
            <th>Permessi</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($users)): ?>
            <tr>
              <td colspan="3" class="text-muted">
                Nessun utente disponibile. Crea gli account e poi assegna i permessi qui.
              </td>
            </tr>
          <?php else: foreach ($users as $u):
            $un = (string)($u['username'] ?? '');
            $isCapo = !empty($u['is_capo']);
            $perms  = is_array($u['perms'] ?? []) ? $u['perms'] : [];
          ?>
            <tr>
              <td class="fw-semibold"><?= htmlspecialchars($un) ?></td>
              <td>
                <div class="form-check form-switch">
                  <input class="form-check-input" type="checkbox" name="is_capo[<?= htmlspecialchars($un) ?>]" value="1" <?= checked($isCapo) ?>>
                </div>
              </td>
              <td>
                <div class="perm-grid">
                  <?php foreach ($PERM_OPTS as $code=>$label): ?>
                    <label class="perm-chip">
                      <input type="checkbox" class="form-check-input me-1"
                             name="perms[<?= htmlspecialchars($un) ?>][]"
                             value="<?= htmlspecialchars($code) ?>"
                             <?= checked(in_array($code, $perms, true)) ?>>
                      <span><?= htmlspecialchars($label) ?></span>
                      <small class="text-muted ms-1">(<?= htmlspecialchars($code) ?>)</small>
                    </label>
                  <?php endforeach; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <div class="p-3 border-top bg-light d-flex gap-2">
      <button class="btn btn-primary">Salva modifiche</button>
      <a class="btn btn-outline-secondary" href="permessi_capo.php">Annulla</a>
    </div>
  </form>
</div>
</body>
</html>