<?php
// _menu.php — topbar/sidebar con gate MFA (solo se loggato)

require_once __DIR__.'/auth.php';
require_once __DIR__.'/tenant_bootstrap.php';
require_once __DIR__.'/storage.php';
// MFA (opzionale): se presente, lo usiamo per capire se richiedere la verifica
@include_once __DIR__.'/mfa_lib.php';

if (session_status() === PHP_SESSION_NONE) { @session_start(); }

/* ========== helper di escape sicuro (evita deprecated con null) ========== */
if (!function_exists('e')) {
  function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

/* ========== Gate MFA ==========
   Richiedi OTP se:
   - c'è un utente in sessione
   - non ha ancora passato MFA (mfa_ok vuoto)
   - e, se mfa_lib esiste, la policy lo richiede
*/
$needMfa = false;
if (!empty($_SESSION['user']) && empty($_SESSION['mfa_ok'])) {
  // Prova a derivare un contesto per la policy
  $ctx = $_SESSION['mfa_ctx'] ?? null;
  if (!is_array($ctx)) {
    $u = $_SESSION['user'];
    $ctx = [
      'type'     => (($u['role'] ?? '') === 'superadmin') ? 'superadmin' : 'tenant',
      'username' => ($u['username'] ?? ''),
      'slug'     => ($_SESSION['tenant_slug'] ?? $_SESSION['CASERMA_SLUG'] ?? ''),
    ];
  }
  if (function_exists('mfa_should_require')) {
    $needMfa = mfa_should_require($ctx);
  } else {
    $needMfa = true;
  }
}
if ($needMfa) {
  header('Location: totp_verify.php?next='.urlencode($_SERVER['REQUEST_URI'] ?? 'index.php'));
  exit;
}

/* ========== Dati utente correnti ========== */
$u = function_exists('auth_current_user') ? auth_current_user() : ($_SESSION['user'] ?? null);

$currUsername = '';
if (is_array($u) && !empty($u['username'])) $currUsername = (string)$u['username'];
if ($currUsername === '' && !empty($_SESSION['username'])) $currUsername = (string)$_SESSION['username'];

/* Display name robusto (mai null) — catena unica di fallback */
$displayName =
    ($u['display_name'] ?? null)
    ?? ($u['name'] ?? null)
    ?? ($u['username'] ?? null)
    ?? (is_array($u) ? trim(((string)($u['cognome'] ?? '')).' '.((string)($u['nome'] ?? ''))) : null)
    ?? ($currUsername ?: null)
    ?? ((!empty($u['role']) && $u['role'] === 'superadmin') ? 'Super Admin' : 'Utente');
$displayName = trim((string)$displayName) !== '' ? (string)$displayName : 'Utente';

/* ========== Distaccamento attivo ========== */
if (!function_exists('load_caserme')) {
  // fallback minimal nel caso tenant_bootstrap non definisca
  function load_caserme(): array { return [['slug'=>'default','name'=>'Default']]; }
}

$caserme    = load_caserme();
$activeSlug = $_SESSION['tenant_slug'] ?? $_SESSION['CASERMA_SLUG'] ?? 'default';
$activeName = function_exists('tenant_active_name') ? tenant_active_name() : 'Default';
if ($activeName === 'Default') {
  foreach ($caserme as $r) {
    if (($r['slug'] ?? '') === $activeSlug) { $activeName = $r['name'] ?? $activeSlug; break; }
  }
}

/* ========== rileva "Capo" ========== */
function capo_detect(string $currUsername, string $activeSlug): bool {
  if (function_exists('auth_is_capo') && auth_is_capo()) return true;
  if (function_exists('auth_can') && (auth_can('admin:perms') || auth_can('capo:*'))) return true;

  // fallback: users.json del tenant
  if (defined('DATA_DIR')) {
    $usersPath = DATA_DIR.'/users.json';
  } elseif (!empty($activeSlug)) {
    $usersPath = __DIR__.'/data/'.preg_replace('/[^a-z0-9_-]/i','', $activeSlug).'/users.json';
  } else {
    $usersPath = '';
  }

  if ($usersPath && is_file($usersPath) && $currUsername!=='') {
    $raw = @file_get_contents($usersPath);
    $arr = $raw ? json_decode($raw, true) : null;
    if (is_array($arr)) {
      foreach ($arr as $row) {
        $un = (string)($row['username'] ?? '');
        if ($un!=='' && strcasecmp($un, $currUsername)===0) {
          $v = $row['is_capo'] ?? false;
          if (is_bool($v))   return $v;
          if (is_int($v))    return $v===1;
          if (is_string($v)) return in_array(strtolower($v), ['1','true','yes','on'], true);
        }
      }
    }
  }
  return false;
}
$isCapo = capo_detect($currUsername, $activeSlug);

/* ========== pagina corrente e nav ========== */
$cur = basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '');
$isActive = fn(string $f) => $cur === $f;

$nav = [
  'index.php'         => ['Addestramenti',               'view:index'],
  'addestramenti.php' => ['Elenco Addestramenti',        'view:addestramenti'],
  'personale.php'     => ['Gestione Personale',          'view:personale'],
  'attivita.php'      => ['Programma Attività',          'view:attivita'],
  'infortuni.php'     => ['Infortuni / Sospensioni',     'view:infortuni'],
  'riepilogo.php'     => ['Riepiloghi',                  'view:riepilogo'],
  'dashboard_vigile.php'     => ['Profilo',                  'view:dashboard_vigile'],
];

$showPerms = (function_exists('auth_is_superadmin') && auth_is_superadmin()) || $isCapo || (function_exists('auth_can') && auth_can('admin:perms'));
$showPins  = (function_exists('auth_is_superadmin') && auth_is_superadmin()) || $isCapo || (function_exists('auth_can') && auth_can('admin:pins'));
if ($showPerms) $nav['permessi_capo.php'] = ['Permessi Utenti', 'admin:perms'];
if ($showPins)  $nav['pins_admin.php']    = ['Gestione PIN',    'admin:pins'];
if ((function_exists('auth_is_superadmin') && auth_is_superadmin()) || $isCapo) {
  $nav['logs_audit.php'] = ['Log attività', null];
}
if (function_exists('auth_is_superadmin') && auth_is_superadmin()) {
  $nav['caserme.php'] = ['Impostazioni Distaccamenti', null];
}

// Filtra: Capo e Superadmin vedono tutto
$nav = array_filter(
  $nav,
  function($info) use ($isCapo) {
    [$label, $perm] = $info;
    if ($perm === null) return true;
    if ((function_exists('auth_is_superadmin') && auth_is_superadmin()) || $isCapo) return true;
    return function_exists('auth_can') ? auth_can($perm) : false;
  }
);

$brand = 'Gestione VV.F.';
$role  = $u['role'] ?? 'tenant';
$badge = $isCapo ? ' — Capo' : '';
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  :root{ --sidebar-w: 236px; }
  html,body{height:100%}
  body{
    background:
      radial-gradient(20px 20px at 0 0, rgba(13,110,253,.06) 1px, transparent 1px),
      radial-gradient(20px 20px at 10px 10px, rgba(13,110,253,.04) 1px, transparent 1px),
      linear-gradient(180deg,#f7f9fc 0%, #eff3f9 100%);
    background-size: 20px 20px, 20px 20px, 100% 100%;
  }
  a, a:hover, .btn-link, .btn-link:hover { text-decoration: none !important; }
  .topbar{ position: sticky; top:0; z-index:1040; background:#fff; border-bottom:1px solid #e9ecef; }
  .topbar-grid{ display:grid; grid-template-columns:auto 1fr auto; align-items:center; }
  .topbar-left{ display:flex; align-items:center; gap:.5rem; }
  .menu-btn{ display:inline-flex; align-items:center; gap:.5rem }
  .menu-icon-round{ width:32px; height:32px; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; background:#0d6efd; color:#fff; }
  .caserma-center{ text-align:center; font-weight:800; letter-spacing:.02em; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .caserma-center .name{ color:#0d6efd; }
  .subnav{ background:#ffffffcc; border-bottom:1px solid #e9ecef; backdrop-filter:saturate(120%) blur(4px); position: relative; z-index: 1021; }
  .subnav .btn{ border-radius:.75rem; border:1px solid #d6dbe4; font-weight:600; }
  .subnav .btn.active{ background:#0d6efd; color:#fff; border-color:#0d6efd; pointer-events:none; }
  .sidebar.offcanvas{ width:var(--sidebar-w); background:#f8f9fb; border-right:1px solid #e9eef5; }
  .sidebar .sidebar-header{ padding:.9rem 1rem .75rem; border-bottom:1px solid #e9eef5; }
  .caserma-pill{ display:inline-block; font-weight:700; background:#eef5ff; border:1px solid #cfe2ff; color:#0b5ed7; padding:.3rem .6rem; border-radius:999px; }
  .nav-btn{ display:flex; align-items:center; gap:.5rem; width:100%; text-align:left; border:1px solid #d6dbe4; border-radius:.8rem; padding:.55rem .75rem; margin-bottom:.5rem; background:#fff; color:#0d6efd; font-weight:600; font-size:.95rem; }
  .nav-btn.active{ background:#0d6efd; color:#fff; border-color:#0d6efd; pointer-events:none; }
  .nav-ico{ width:16px; height:16px; flex:0 0 16px; opacity:.9 }
  @media (min-width: 992px){ body.with-sidebar-open { padding-left: var(--sidebar-w); } }
</style>

<!-- TOPBAR -->
<nav class="navbar topbar">
  <div class="container-fluid topbar-grid">
    <div class="topbar-left">
      <button class="btn menu-btn" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebarNav" aria-controls="sidebarNav" title="Apri menu">
        <span class="menu-icon-round">
          <svg xmlns="http://www.w3.org/2000/svg" width="17" height="17" viewBox="0 0 16 16"><path fill="currentColor" d="M1.5 12h13v1.5h-13zm0-4h13v1.5h-13zm0-4h13v1.5h-13z"/></svg>
        </span>
        <span class="fw-semibold">Menu</span>
      </button>
      <a class="navbar-brand m-0 fw-bold" href="index.php"><?= e($brand) ?></a>
    </div>

    <div class="topbar-center">
      <div class="caserma-center text-truncate" title="<?= e($activeName) ?>">
        Distaccamento: <span class="name"><?= e($activeName) ?></span>
      </div>
    </div>

    <div class="topbar-right">
      <div class="dropdown">
        <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
          <?php
            if (!empty($_SESSION['user'])) {
              echo e($displayName).' ('.e($role).e($badge).')';
            } else {
              echo 'Accedi';
            }
          ?>
        </button>
        <ul class="dropdown-menu dropdown-menu-end">
          <?php if (!empty($_SESSION['user'])): ?>
            <li class="dropdown-header small text-muted"><?= e($currUsername ?: $displayName) ?></li>
            <li><a class="dropdown-item" href="logout.php">Logout</a></li>
          <?php else: ?>
            <li><a class="dropdown-item" href="login.php">Login</a></li>
          <?php endif; ?>
        </ul>
      </div>
    </div>
  </div>
</nav>

<!-- SUBNAV -->
<div class="subnav">
  <div class="container-fluid py-2">
    <div class="d-flex flex-wrap gap-2">
      <?php foreach ($nav as $file => [$label, $perm]): ?>
        <a class="btn <?= $isActive($file) ? 'btn-primary active' : 'btn-outline-primary' ?> btn-sm"
           href="<?= e($file) ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- SIDEBAR -->
<div class="offcanvas offcanvas-start sidebar" tabindex="-1" id="sidebarNav" aria-labelledby="sidebarNavLabel" data-bs-scroll="true">
  <div class="offcanvas-header sidebar-header">
    <div>
      <div class="d-flex align-items-center gap-2">
        <svg width="16" height="16" viewBox="0 0 24 24"><path fill="#0d6efd" d="M4 4h16v2H4zm0 4h16v2H4zm0 4h12v2H4zM4 19h12v2H4z"/></svg>
        <div>
          <div class="fw-bold">AllertoGest — Operativo</div>
          <div class="text-muted" style="font-size:.83rem">Gestione Addestramenti Volontari</div>
        </div>
      </div>
      <div class="mt-2">
        <span class="caserma-pill">Distaccamento: <?= e($activeName) ?></span>
      </div>
    </div>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Chiudi"></button>
  </div>

  <div class="offcanvas-body d-flex flex-column">
    <div class="mb-2" style="font-size:.75rem; letter-spacing:.06em; text-transform:uppercase; color:#6c757d;">Navigazione</div>
    <?php foreach ($nav as $file => [$label, $perm]): ?>
      <a class="nav-btn <?= $isActive($file) ? 'active' : '' ?>" href="<?= e($file) ?>">
        <?= e($label) ?>
      </a>
    <?php endforeach; ?>

    <div class="mt-auto">
      <div class="text-center p-3 bg-white border rounded-3">
        <div class="fw-semibold mb-1"><?= e($displayName) ?></div>
        <div class="text-muted small mb-2"><?= $isCapo ? 'Capo Distaccamento' : (!empty($_SESSION['user']) ? 'Utente' : 'Ospite') ?></div>
        <?php if (!empty($_SESSION['user'])): ?>
          <a class="btn btn-outline-danger btn-sm" href="logout.php">Logout</a>
        <?php else: ?>
          <a class="btn btn-primary btn-sm" href="login.php">Login</a>
        <?php endif; ?>
      </div>
      <div class="text-center text-muted small mt-3">© <?= date('Y') ?> — <?= e($brand) ?></div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function(){
  const el = document.getElementById('sidebarNav');
  if (!el) return;
  const off = bootstrap.Offcanvas.getOrCreateInstance(el, { backdrop:true, scroll:true });
  const saved = localStorage.getItem('sidebarOpen');
  if (saved === '1') { off.show(); document.body.classList.add('with-sidebar-open'); }
  el.addEventListener('shown.bs.offcanvas', ()=>{ localStorage.setItem('sidebarOpen','1'); document.body.classList.add('with-sidebar-open'); });
  el.addEventListener('hidden.bs.offcanvas', ()=>{ localStorage.setItem('sidebarOpen','0'); document.body.classList.remove('with-sidebar-open'); });
})();
async function switchTenant(slug){
  if (!slug) return false;
  try{
    const body = new URLSearchParams({ slug });
    const r = await fetch('switch_tenant.php', {
      method:'POST',
      headers:{'X-Requested-With':'XMLHttpRequest','Content-Type':'application/x-www-form-urlencoded'},
      body, credentials:'same-origin', cache:'no-store'
    });
    if (!r.ok){ alert('Errore attivazione: ' + await r.text()); return false; }
    location.reload();
  }catch(e){ alert('Errore imprevisto nel cambio distaccamento.'); }
  return false;
}
</script>

<?php if (!empty($_GET['_dbg'])): ?>
<div class="container mt-2">
  <div class="alert alert-warning p-2">
    <b>DEBUG</b> — user: <code><?= e($currUsername ?: '(vuoto)') ?></code> •
    slug: <code><?= e($activeSlug) ?></code> •
    capo_detect: <b><?= $isCapo ? 'TRUE' : 'FALSE' ?></b>
  </div>
</div>
<?php endif; ?>
