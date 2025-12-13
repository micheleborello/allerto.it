<?php
ob_start();

require __DIR__.'/auth.php';
require_superadmin(); // solo superadmin

ini_set('display_errors', 1);
error_reporting(E_ALL);

require __DIR__.'/tenant_bootstrap.php';
require __DIR__.'/storage.php';

/* =========================
   Base path dati (allineata a DATA_DIR)
   ========================= */
$__DATA_BASE = (defined('DATA_DIR') && DATA_DIR)
  ? dirname(DATA_DIR)           // es. /home/.../allerto.it/data
  : (__DIR__ . '/data');        // fallback

if (!defined('DATA_CASERME_BASE')) define('DATA_CASERME_BASE', $__DATA_BASE);
if (!defined('SA_USERS_PATH'))     define('SA_USERS_PATH', DATA_CASERME_BASE . '/superadmin_users.json');

/* =========================
   SHIM caserme (se mancanti)
   ========================= */
if (!function_exists('load_caserme')) {
  function load_caserme(): array {
    $base = DATA_CASERME_BASE;
    $cfg  = $base . '/caserme.json';
    $out  = [];
    if (is_file($cfg)) {
      $raw = @file_get_contents($cfg);
      $arr = $raw ? json_decode($raw, true) : null;
      if (is_array($arr)) {
        foreach ($arr as $r) {
          $slug = preg_replace('/[^a-z0-9_-]/i', '', (string)($r['slug'] ?? ''));
          $name = trim((string)($r['name'] ?? ''));
          if ($slug !== '' && is_dir($base.'/'.$slug)) {
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
    $base = DATA_CASERME_BASE;
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

/* =========================
   JSON helpers
   ========================= */
function json_load_relaxed_local(string $path, $def = []) {
  if (!is_file($path)) return $def;
  $s = @file_get_contents($path);
  if ($s === false) return $def;

  // Rimuove solo BOM e virgole finali prima di ] o }
  $s = preg_replace('/^\xEF\xBB\xBF/', '', $s);              // BOM UTF-8
  $s = preg_replace('/,\s*([\}\]])/m', '$1', $s);            // virgole di troppo
  // NON rimuovere // o /* ... */ perché possono comparire dentro stringhe (es. bcrypt)

  $data = json_decode($s, true);
  return is_array($data) ? $data : $def;
}

function json_save_pretty(string $path, $arr): void {
  $dir = dirname($path);
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  $json = json_encode($arr, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
  @file_put_contents($path, $json);
  @chmod($path, 0664);
}

/* =========================
   Superadmin helpers
   ========================= */
function load_sa_users(): array { return json_load_relaxed_local(SA_USERS_PATH, []); }
function save_sa_users(array $arr): void {
  $out=[]; $seen=[];
  foreach ($arr as $u) {
    $un = strtolower(trim((string)($u['username'] ?? '')));
    $ph = (string)($u['password_hash'] ?? '');
    if ($un==='' || $ph==='') continue;
    if (isset($seen[$un])) continue;
    $seen[$un]=1;
    $out[] = ['username'=>$un, 'password_hash'=>$ph];
  }
  usort($out, fn($a,$b)=> strcasecmp($a['username'],$b['username']));
  json_save_pretty(SA_USERS_PATH, $out);
}
function sa_users_count(array $arr): int { $n=0; foreach ($arr as $u) if (!empty($u['username'])) $n++; return $n; }

/* =========================
   Tenant users helpers
   ========================= */
function tenant_users_path(string $slug): string {
  $slug = preg_replace('/[^a-z0-9_-]/i','',$slug);
  return DATA_CASERME_BASE . "/{$slug}/users.json";
}
function vigili_path(string $slug): string {
  $slug = preg_replace('/[^a-z0-9_-]/i','',$slug);
  return DATA_CASERME_BASE . "/{$slug}/vigili.json";
}
function load_tenant_users(string $slug): array {
  if ($slug==='') return [];
  $p = tenant_users_path($slug);
  $rows = json_load_relaxed_local($p, []);
  $out=[]; $seen=[];
  foreach ($rows as $u) {
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
function save_tenant_users(string $slug, array $arr): void {
  if ($slug==='') return;
  $p = tenant_users_path($slug);
  $existing = load_tenant_users($slug);
  $byUser = [];
  foreach ($existing as $u) { $byUser[$u['username']] = $u; }
  $out=[]; $seen=[];
  foreach ($arr as $u) {
    $un = strtolower(trim((string)($u['username'] ?? '')));
    if ($un==='' || isset($seen[$un])) continue;
    $seen[$un]=1;
    $old = $byUser[$un] ?? [];
    $out[] = [
      'username'      => $un,
      'password_hash' => (string)($u['password_hash'] ?? ($old['password_hash'] ?? '')),
      'is_capo'       => !empty($u['is_capo']) ? 1 : (!empty($old['is_capo']) ? 1 : 0),
      'perms'         => is_array($u['perms'] ?? null) ? array_values(array_unique(array_map('strval',$u['perms']))) : ($old['perms'] ?? []),
    ];
  }
  usort($out, fn($a,$b)=>strcasecmp($a['username'],$b['username']));
  json_save_pretty($p, $out);
}
function set_tenant_capo(string $slug, string $username): void {
  $username = strtolower(trim($username));
  $rows = load_tenant_users($slug);
  foreach ($rows as &$u) $u['is_capo'] = ($u['username'] === $username) ? 1 : 0;
  unset($u);
  save_tenant_users($slug, $rows);
}

/* ===== Username helper da vigili ===== */
function username_from_vigile(array $v, array $taken): string {
  $nome    = strtolower(trim((string)($v['nome'] ?? '')));
  $cognome = strtolower(trim((string)($v['cognome'] ?? '')));
  if (function_exists('iconv')) {
    $nome    = iconv('UTF-8','ASCII//TRANSLIT',$nome);
    $cognome = iconv('UTF-8','ASCII//TRANSLIT',$cognome);
  }
  $base = preg_replace('/[^a-z0-9]+/','', $cognome).'.'.preg_replace('/[^a-z0-9]+/','', $nome);
  if ($base==='.') $base = 'user';
  $u = $base; $i=1;
  while (isset($taken[$u])) { $u = $base.$i; $i++; }
  return $u;
}

/* =========================
   Stato iniziale
   ========================= */
$err = $_GET['err'] ?? '';
$msg = $_GET['msg'] ?? '';

$caserme = load_caserme();
$bySlug  = [];
foreach ($caserme as $r) { $bySlug[$r['slug']] = $r; }

/* slug attivo */
$active  = $_SESSION['tenant_slug'] ?? $_SESSION['CASERMA_SLUG'] ?? '';
if ($active === '' && function_exists('tenant_active_slug')) {
  $active = (string) tenant_active_slug();
}
$active = preg_replace('/[^a-z0-9_-]/i','', $active);

$action = $_POST['action'] ?? '';

/* =========================
   POST azioni
   ========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $handled = false;

    // Distaccamenti
    if ($action === 'create') {
      $handled = true;
      $name = trim($_POST['name'] ?? '');
      $slug = strtolower(trim($_POST['slug'] ?? ''));
      $slug = preg_replace('/[^a-z0-9_\-]/', '-', $slug);
      if ($name === '' || $slug === '') throw new Exception('Nome e slug obbligatori.');
      if (isset($bySlug[$slug])) throw new Exception('Slug già in uso.');
      $dir = DATA_CASERME_BASE.'/'.$slug;
      if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
      foreach ([
        'vigili.json'=>"[]",
        'addestramenti.json'=>"[]",
        'infortuni.json'=>"[]",
        'personale.json'=>"{}",
        'attivita.json'=>"[]",
        'users.json'=>"[]"
      ] as $fn=>$init) {
        $fp = $dir.'/'.$fn; if (!is_file($fp)) @file_put_contents($fp,$init);
      }
      $caserme[] = ['slug'=>$slug, 'name'=>$name, 'created_at'=>date('Y-m-d H:i:s')];
      save_caserme($caserme);
      header('Location: caserme.php?msg=created'); exit;
    }
    if ($action === 'rename') {
      $handled = true;
      $slug = $_POST['slug'] ?? ''; if (!isset($bySlug[$slug])) throw new Exception('Distaccamento non trovato.');
      $name = trim($_POST['name'] ?? ''); if ($name === '') throw new Exception('Nome non valido.');
      foreach ($caserme as &$r) { if ($r['slug']===$slug) { $r['name']=$name; break; } }
      unset($r);
      save_caserme($caserme);
      header('Location: caserme.php?msg=renamed'); exit;
    }
    if ($action === 'delete') {
      $handled = true;
      $slug = $_POST['slug'] ?? ''; if (!isset($bySlug[$slug])) throw new Exception('Distaccamento non trovato.');
      $deleteData = isset($_POST['delete_data']);
      $caserme = array_values(array_filter($caserme, fn($r)=>$r['slug']!==$slug));
      save_caserme($caserme);
      if ($deleteData) {
        $dir = DATA_CASERME_BASE.'/'.$slug;
        @rename($dir, $dir.'__deleted_'.date('Ymd_His'));
      }
      if (isset($_SESSION['CASERMA_SLUG']) && $_SESSION['CASERMA_SLUG']===$slug) $_SESSION['CASERMA_SLUG'] = null;
      header('Location: caserme.php?msg=deleted'); exit;
    }
    if ($action === 'activate') {
      $handled = true;
      $slug = $_POST['slug'] ?? ''; if (!isset($bySlug[$slug])) throw new Exception('Distaccamento non trovato.');
      $_SESSION['CASERMA_SLUG'] = $slug; $_SESSION['tenant_slug']  = $slug;
      header('Location: caserme.php?msg=activated'); exit;
    }

    // Superadmin
    if ($action === 'sa_create') {
      $handled = true;
      $username = strtolower(trim((string)($_POST['username'] ?? '')));
      $password = (string)($_POST['password'] ?? '');
      if ($username==='' || $password==='') throw new Exception('Compila username e password.');
      if (!preg_match('/^[a-z0-9._\-@]+$/', $username)) throw new Exception('Username non valido.');
      $users = load_sa_users();
      foreach ($users as $u) if (strcasecmp($u['username'],$username)===0) throw new Exception('Username superadmin già esistente.');
      $users[] = ['username'=>$username, 'password_hash'=>password_hash($password, PASSWORD_DEFAULT)];
      save_sa_users($users);
      header('Location: caserme.php?msg=sa_created'); exit;
    }
    if ($action === 'sa_pwd') {
      $handled = true;
      $username = strtolower(trim((string)($_POST['username'] ?? '')));
      $password = (string)($_POST['password'] ?? '');
      if ($username==='' || $password==='') throw new Exception('Dati non validi.');
      $users = load_sa_users(); $found=false;
      foreach ($users as &$u) { if (strcasecmp($u['username'],$username)===0) { $u['password_hash']=password_hash($password, PASSWORD_DEFAULT); $found=true; break; } }
      unset($u);
      if (!$found) throw new Exception('Utente superadmin non trovato.');
      save_sa_users($users);
      header('Location: caserme.php?msg=sa_pwd'); exit;
    }
    if ($action === 'sa_rename') {
      $handled = true;
      $old = strtolower(trim((string)($_POST['old'] ?? ''))); $new = strtolower(trim((string)($_POST['new'] ?? '')));
      if ($old==='' || $new==='') throw new Exception('Dati non validi.');
      if (!preg_match('/^[a-z0-9._\-@]+$/', $new)) throw new Exception('Nuovo username non valido.');
      $users = load_sa_users();
      foreach ($users as $u) if (strcasecmp($u['username'],$new)===0) throw new Exception('Esiste già un superadmin con quel username.');
      $found=false; foreach ($users as &$u) { if (strcasecmp($u['username'],$old)===0) { $u['username']=$new; $found=true; break; } }
      unset($u);
      if (!$found) throw new Exception('Utente superadmin non trovato.');
      save_sa_users($users);
      header('Location: caserme.php?msg=sa_renamed'); exit;
    }
    if ($action === 'sa_delete') {
      $handled = true;
      $username = strtolower(trim((string)($_POST['username'] ?? '')));
      $users = load_sa_users();
      $users = array_values(array_filter($users, fn($u)=> strcasecmp($u['username'],$username)!==0));
      if (sa_users_count($users) <= 0) throw new Exception('Impossibile eliminare l’ultimo superadmin.');
      save_sa_users($users);
      header('Location: caserme.php?msg=sa_deleted'); exit;
    }

    // Tenant users
    $active = $_SESSION['tenant_slug'] ?? $_SESSION['CASERMA_SLUG'] ?? $active;
    if ($active === '' && function_exists('tenant_active_slug')) {
      $active = (string) tenant_active_slug();
    }
    $active = preg_replace('/[^a-z0-9_-]/i','', $active);

    if ($action === 't_create') {
      $handled = true;
      if ($active==='') throw new Exception('Nessun distaccamento attivo.');
      $username = strtolower(trim((string)($_POST['username'] ?? '')));
      $password = (string)($_POST['password'] ?? '');
      if ($username==='' || $password==='') throw new Exception('Compila username e password.');
      if (!preg_match('/^[a-z0-9._\-@]+$/', $username)) throw new Exception('Username non valido.');
      $users = load_tenant_users($active);
      foreach ($users as $u) if (strcasecmp($u['username'],$username)===0) throw new Exception('Username già esistente per questo distaccamento.');
      $users[] = ['username'=>$username, 'password_hash'=>password_hash($password, PASSWORD_DEFAULT), 'is_capo'=>0, 'perms'=>[]];
      save_tenant_users($active, $users);
      header('Location: caserme.php?msg=t_created'); exit;
    }
    if ($action === 't_pwd') {
      $handled = true;
      if ($active==='') throw new Exception('Nessun distaccamento attivo.');
      $username = strtolower(trim((string)($_POST['username'] ?? ''))); $password = (string)($_POST['password'] ?? '');
      if ($username==='' || $password==='') throw new Exception('Dati non validi.');
      $users = load_tenant_users($active); $found=false;
      foreach ($users as &$u) { if (strcasecmp($u['username'],$username)===0) { $u['password_hash']=password_hash($password, PASSWORD_DEFAULT); $found=true; break; } }
      unset($u);
      if (!$found) throw new Exception('Utente non trovato.');
      save_tenant_users($active, $users);
      header('Location: caserme.php?msg=t_pwd'); exit;
    }
    if ($action === 't_rename') {
      $handled = true;
      if ($active==='') throw new Exception('Nessun distaccamento attivo.');
      $old = strtolower(trim((string)($_POST['old'] ?? ''))); $new = strtolower(trim((string)($_POST['new'] ?? '')));
      if ($old==='' || $new==='') throw new Exception('Dati non validi.');
      if (!preg_match('/^[a-z0-9._\-@]+$/', $new)) throw new Exception('Nuovo username non valido.');
      $users = load_tenant_users($active);
      foreach ($users as $u) if (strcasecmp($u['username'],$new)===0) throw new Exception('Esiste già un utente con quel username.');
      $found=false; foreach ($users as &$u) { if (strcasecmp($u['username'],$old)===0) { $u['username']=$new; $found=true; break; } }
      unset($u);
      if (!$found) throw new Exception('Utente non trovato.');
      save_tenant_users($active, $users);
      header('Location: caserme.php?msg=t_renamed'); exit;
    }
    if ($action === 't_delete') {
      $handled = true;
      if ($active==='') throw new Exception('Nessun distaccamento attivo.');
      $username = strtolower(trim((string)($_POST['username'] ?? '')));
      $users = load_tenant_users($active);
      $users = array_values(array_filter($users, fn($u)=> strcasecmp($u['username'],$username)!==0));
      save_tenant_users($active, $users);
      header('Location: caserme.php?msg=t_deleted'); exit;
    }
    if ($action === 't_set_capo') {
      $handled = true;
      if ($active==='') throw new Exception('Nessun distaccamento attivo.');
      $username = strtolower(trim((string)($_POST['username'] ?? '')));
      if ($username==='') throw new Exception('Seleziona un utente.');
      set_tenant_capo($active, $username);
      header('Location: caserme.php?msg=t_capo'); exit;
    }

    // Crea utente da vigile (singolo)
    if ($action === 't_create_from_vigile') {
      $handled = true;
      if ($active==='') throw new Exception('Nessun distaccamento attivo.');
      $vid = (int)($_POST['vid'] ?? 0);
      $username = strtolower(trim((string)($_POST['username'] ?? '')));
      $password = (string)($_POST['password'] ?? '');
      if ($vid<=0 || $username==='' || $password==='') throw new Exception('Dati non validi.');
      if (!preg_match('/^[a-z0-9._\-@]+$/', $username)) throw new Exception('Username non valido.');
      $users = load_tenant_users($active);
      foreach ($users as $u) if (strcasecmp($u['username'],$username)===0) throw new Exception('Username già in uso.');
      $users[] = ['username'=>$username, 'password_hash'=>password_hash($password, PASSWORD_DEFAULT), 'is_capo'=>0, 'perms'=>[]];
      save_tenant_users($active, $users);
      header('Location: caserme.php?msg=t_created'); exit;
    }

    // Creazione massiva da vigili mancanti
    if ($action === 't_bulk_from_vigili') {
      $handled = true;
      if ($active==='') throw new Exception('Nessun distaccamento attivo.');
      $default_pwd = (string)($_POST['default_password'] ?? '');
      if ($default_pwd==='') throw new Exception('Password di default mancante.');
      $vigili = json_load_relaxed_local(vigili_path($active), []);
      $users  = load_tenant_users($active);
      $taken = [];
      foreach ($users as $u) $taken[$u['username']] = 1;
      $userSet = [];
      foreach ($users as $u) $userSet[$u['username']] = 1;

      foreach ($vigili as $v) {
        $nome = trim((string)($v['nome'] ?? '')); $cogn = trim((string)($v['cognome'] ?? ''));
        if ($nome==='' && $cogn==='') continue;
        $uname = username_from_vigile($v, $taken);
        if (isset($userSet[$uname])) continue;
        $users[] = ['username'=>$uname, 'password_hash'=>password_hash($default_pwd, PASSWORD_DEFAULT), 'is_capo'=>0, 'perms'=>[]];
        $taken[$uname] = 1; $userSet[$uname] = 1;
      }
      save_tenant_users($active, $users);
      header('Location: caserme.php?msg=t_created'); exit;
    }

    if (!$handled) {
      throw new Exception('Azione non valida.');
    }

  } catch (Throwable $e) {
    header('Location: caserme.php?err='.rawurlencode($e->getMessage())); exit;
  }
}

/* =========================
   View data
   ========================= */
$active  = $_SESSION['tenant_slug'] ?? $_SESSION['CASERMA_SLUG'] ?? $active;
if ($active === '' && function_exists('tenant_active_slug')) {
  $active = (string) tenant_active_slug();
}
$active = preg_replace('/[^a-z0-9_-]/i','', $active);

$saUsers = load_sa_users();
$tenUsers = $active ? load_tenant_users($active) : [];
$vigili  = $active ? json_load_relaxed_local(vigili_path($active), []) : [];

/* set utenti esistenti */
$existing = [];
foreach ($tenUsers as $u) $existing[strtolower($u['username'])]=1;

/* proponi elenco vigili (username suggeriti) */
$missing = [];
$taken = [];
foreach ($tenUsers as $u) $taken[$u['username']] = 1;
usort($vigili, fn($a,$b)=> strcasecmp(($a['cognome']??'').' '.($a['nome']??''), ($b['cognome']??'').' '.($b['nome']??'')));
foreach ($vigili as $v) {
  $uname = username_from_vigile($v, $taken);
  $missing[] = [
    'id' => (int)($v['id'] ?? 0),
    'nome' => trim(($v['cognome'] ?? '').' '.($v['nome'] ?? '')),
    'suggest' => $uname,
  ];
  $taken[$uname]=1;
}
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Impostazioni — Distaccamenti & Utenti</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4">

  <div class="d-flex align-items-center justify-content-between">
    <h1 class="mb-0">Impostazioni</h1>

    <!-- Pulsante "Aggiorna da SIPEC" (POST + conferma) -->
    <form method="post" action="sipec_sync.php" class="d-inline"
          onsubmit="return confirm('Aggiornare TUTTI i distaccamenti da SIPEC? L\'operazione può richiedere alcuni minuti.');">
      <button class="btn btn-warning">Aggiorna da SIPEC</button>
    </form>

    <a class="btn btn-outline-secondary" href="index.php">← Torna</a>
  </div>

  <!-- Diagnostica -->
  <div class="alert alert-info mt-3 small">
    <b>Diagnosi:</b><br>
    Base dati: <code><?= htmlspecialchars(DATA_CASERME_BASE) ?></code><br>
    Distaccamento attivo: <code><?= htmlspecialchars($active ?: '(nessuno)') ?></code><br>
    File utenti: <code><?= htmlspecialchars($active ? tenant_users_path($active) : '-') ?></code> —
    trovati <?= $active ? count($tenUsers) : 0 ?> utenti<br>
    File vigili: <code><?= htmlspecialchars($active ? vigili_path($active) : '-') ?></code> —
    trovati <?= $active ? count($vigili) : 0 ?> vigili
  </div>

  <?php if ($err): ?><div class="alert alert-danger"><?= htmlspecialchars($err) ?></div><?php endif; ?>
  <?php if ($msg): ?>
    <div class="alert alert-success">
      <?php
        echo match($msg) {
          'created'     => 'Distaccamento creato.',
          'deleted'     => 'Distaccamento eliminato.',
          'renamed'     => 'Nome aggiornato.',
          'activated'   => 'Distaccamento attivato.',
          'sa_created'  => 'Superadmin creato.',
          'sa_pwd'      => 'Password superadmin aggiornata.',
          'sa_deleted'  => 'Superadmin eliminato.',
          'sa_renamed'  => 'Superadmin rinominato.',
          't_created'   => 'Utenti aggiornati/creati.',
          't_pwd'       => 'Password utente aggiornata.',
          't_deleted'   => 'Utente eliminato.',
          't_renamed'   => 'Utente rinominato.',
          't_capo'      => 'Capo distaccamento impostato.',
          default       => 'OK.'
        };
      ?>
    </div>
  <?php endif; ?>

  <div class="row g-3 mt-1">
    <!-- SINISTRA: Distaccamenti -->
    <div class="col-lg-5">
      <div class="card shadow-sm">
        <div class="card-body">
          <h5 class="card-title">Nuovo Distaccamento</h5>
          <form method="post" class="row g-2">
            <input type="hidden" name="action" value="create">
            <div class="col-12"><label class="form-label">Nome</label><input name="name" class="form-control" required></div>
            <div class="col-12">
              <label class="form-label">Slug (minuscolo, senza spazi)</label>
              <input name="slug" class="form-control" placeholder="es. nole, alpignano" required>
              <div class="form-text">Usato come cartella dati separata (sotto <code><?= htmlspecialchars(DATA_CASERME_BASE) ?></code>).</div>
            </div>
            <div class="col-12 d-grid"><button class="btn btn-primary">Crea Distaccamento</button></div>
          </form>
        </div>
      </div>

      <div class="card shadow-sm mt-3">
        <div class="card-body">
          <h5 class="card-title">Elenco Distaccamenti</h5>
          <table class="table table-sm align-middle">
            <thead><tr><th>#</th><th>Nome</th><th>Slug</th><th>Stato</th><th class="text-end">Azioni</th></tr></thead>
            <tbody>
              <?php if (empty($caserme)): ?>
                <tr><td colspan="5" class="text-muted">Nessun Distaccamento definito.</td></tr>
              <?php else: foreach ($caserme as $i=>$r): ?>
                <tr>
                  <td><?= $i+1 ?></td>
                  <td><?= htmlspecialchars($r['name']) ?></td>
                  <td><code><?= htmlspecialchars($r['slug']) ?></code></td>
                  <td><?= (($active??'')===$r['slug']) ? '<span class="badge text-bg-success">ATTIVA</span>' : '—' ?></td>
                  <td class="text-end">
                    <form method="post" class="d-inline">
                      <input type="hidden" name="action" value="activate">
                      <input type="hidden" name="slug" value="<?= htmlspecialchars($r['slug']) ?>">
                      <button class="btn btn-sm btn-outline-primary" <?= (($active??'')===$r['slug'])?'disabled':''; ?>>Attiva</button>
                    </form>
                    <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#mRename" data-slug="<?= htmlspecialchars($r['slug']) ?>" data-name="<?= htmlspecialchars($r['name']) ?>">Rinomina</button>
                    <form method="post" class="d-inline" onsubmit="return confirm('Eliminare &quot;<?= htmlspecialchars($r['name']) ?>&quot;?');">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="slug" value="<?= htmlspecialchars($r['slug']) ?>">
                      <label class="me-1 small"><input type="checkbox" name="delete_data"> elimina dati</label>
                      <button class="btn btn-sm btn-outline-danger">Elimina</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- DESTRA: Gestione Utenti -->
    <div class="col-lg-7">
      <!-- Superadmin -->
      <div class="card shadow-sm">
        <div class="card-body">
          <h5 class="card-title">Utenti Superadmin</h5>

          <form method="post" class="row g-2 mb-3">
            <input type="hidden" name="action" value="sa_create">
            <div class="col-md-4"><label class="form-label">Username</label><input name="username" class="form-control" required></div>
            <div class="col-md-5"><label class="form-label">Password</label><input type="password" name="password" class="form-control" required></div>
            <div class="col-md-3 align-self-end"><button class="btn btn-primary w-100">Crea</button></div>
          </form>

          <table class="table table-sm align-middle">
            <thead><tr><th>#</th><th>Username</th><th class="text-end">Azioni</th></tr></thead>
            <tbody>
              <?php if (empty($saUsers)): ?>
                <tr><td colspan="3" class="text-muted">Nessun superadmin.</td></tr>
              <?php else: foreach ($saUsers as $i=>$u): ?>
                <tr>
                  <td><?= $i+1 ?></td>
                  <td><code><?= htmlspecialchars($u['username']) ?></code></td>
                  <td class="text-end">
                    <button class="btn btn-sm btn-outline-secondary btn-sa-rename" data-username="<?= htmlspecialchars($u['username'],ENT_QUOTES) ?>" data-bs-toggle="modal" data-bs-target="#mSaRename">Rinomina</button>
                    <button class="btn btn-sm btn-outline-primary btn-sa-pwd" data-username="<?= htmlspecialchars($u['username'],ENT_QUOTES) ?>" data-bs-toggle="modal" data-bs-target="#mSaPwd">Password</button>
                    <form method="post" class="d-inline" onsubmit="return confirm('Eliminare &quot;<?= htmlspecialchars($u['username']) ?>&quot;?');">
                      <input type="hidden" name="action" value="sa_delete">
                      <input type="hidden" name="username" value="<?= htmlspecialchars($u['username'],ENT_QUOTES) ?>">
                      <button class="btn btn-sm btn-outline-danger">Elimina</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Utenti del Distaccamento attivo -->
      <div class="card shadow-sm mt-3">
        <div class="card-body">
          <h5 class="card-title">
            Utenti del Distaccamento
            <?= $active ? '<span class="badge text-bg-secondary">'.htmlspecialchars($active).'</span>' : '<span class="text-muted">(nessuno attivo)</span>' ?>
          </h5>

          <form method="post" class="row g-2 mb-3">
            <input type="hidden" name="action" value="t_create">
            <div class="col-md-4"><label class="form-label">Username</label><input name="username" class="form-control" required <?= $active?'':'disabled' ?>></div>
            <div class="col-md-5"><label class="form-label">Password</label><input type="password" name="password" class="form-control" required <?= $active?'':'disabled' ?>></div>
            <div class="col-md-3 align-self-end"><button class="btn btn-primary w-100" <?= $active?'':'disabled' ?>>Crea</button></div>
          </form>

          <table class="table table-sm align-middle">
            <thead><tr><th>#</th><th>Username</th><th>Capo</th><th class="text-end">Azioni</th></tr></thead>
            <tbody>
              <?php if (!$active): ?>
                <tr><td colspan="4" class="text-muted">Attiva un distaccamento nella tabella a sinistra.</td></tr>
              <?php elseif (empty($tenUsers)): ?>
                <tr><td colspan="4" class="text-muted">Nessun utente per questo distaccamento.</td></tr>
              <?php else: foreach ($tenUsers as $i=>$u): $isCapo = !empty($u['is_capo']); ?>
                <tr>
                  <td><?= $i+1 ?></td>
                  <td><code><?= htmlspecialchars($u['username']) ?></code> <?= $isCapo ? ' <span class="badge text-bg-warning">Capo</span>' : '' ?></td>
                  <td>
                    <form method="post" class="d-inline">
                      <input type="hidden" name="action" value="t_set_capo">
                      <input type="hidden" name="username" value="<?= htmlspecialchars($u['username'],ENT_QUOTES) ?>">
                      <div class="form-check">
                        <input class="form-check-input" type="radio" name="capo_sel" onclick="this.form.submit()" <?= $isCapo?'checked':''; ?> <?= $active?'':'disabled' ?>>
                      </div>
                    </form>
                  </td>
                  <td class="text-end">
                    <button class="btn btn-sm btn-outline-secondary btn-t-rename" data-username="<?= htmlspecialchars($u['username'],ENT_QUOTES) ?>" data-bs-toggle="modal" data-bs-target="#mTRename" <?= $active?'':'disabled' ?>>Rinomina</button>
                    <button class="btn btn-sm btn-outline-primary btn-t-pwd" data-username="<?= htmlspecialchars($u['username'],ENT_QUOTES) ?>" data-bs-toggle="modal" data-bs-target="#mTPwd" <?= $active?'':'disabled' ?>>Password</button>
                    <form method="post" class="d-inline" onsubmit="return confirm('Eliminare &quot;<?= htmlspecialchars($u['username']) ?>&quot;?');">
                      <input type="hidden" name="action" value="t_delete">
                      <input type="hidden" name="username" value="<?= htmlspecialchars($u['username'],ENT_QUOTES) ?>">
                      <button class="btn btn-sm btn-outline-danger" <?= $active?'':'disabled' ?>>Elimina</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Vigili -> crea account -->
      <div class="card shadow-sm mt-3">
        <div class="card-body">
          <h5 class="card-title">Vigili (da <code>vigili.json</code>) — crea account</h5>

          <?php if (!$active): ?>
            <div class="alert alert-warning">Attiva prima un distaccamento.</div>
          <?php elseif (empty($vigili)): ?>
            <div class="text-muted">Nessun nominativo in <code>data/<?= htmlspecialchars($active) ?>/vigili.json</code>.</div>
          <?php else: ?>
            <!-- bulk -->
            <form method="post" class="row g-2 mb-2">
              <input type="hidden" name="action" value="t_bulk_from_vigili">
              <div class="col-md-6">
                <label class="form-label">Password di default (per creazione massiva)</label>
                <input type="text" class="form-control" name="default_password" placeholder="es. CambialaSubito!" required>
              </div>
              <div class="col-md-3 align-self-end">
                <button class="btn btn-outline-primary w-100">Crea tutti i mancanti</button>
              </div>
            </form>

            <div class="table-responsive">
              <table class="table table-sm align-middle">
                <thead><tr><th>#</th><th>Vigile</th><th>Username proposto</th><th>Password</th><th>Azioni</th></tr></thead>
                <tbody>
                  <?php $ix=0; foreach ($missing as $m): ?>
                    <tr>
                      <td><?= ++$ix ?></td>
                      <td><?= htmlspecialchars($m['nome'] ?: ('ID '.$m['id'])) ?></td>
                      <td>
                        <form method="post" class="d-flex gap-2">
                          <input type="hidden" name="action" value="t_create_from_vigile">
                          <input type="hidden" name="vid" value="<?= (int)$m['id'] ?>">
                          <input name="username" class="form-control form-control-sm" value="<?= htmlspecialchars($m['suggest']) ?>" required>
                      </td>
                      <td><input type="text" name="password" class="form-control form-control-sm" placeholder="Password iniziale" required></td>
                      <td>
                          <button class="btn btn-sm btn-success">Crea</button>
                        </form>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>

    </div>
  </div>
</div>

<!-- Modali -->
<div class="modal fade" id="mRename" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <input type="hidden" name="action" value="rename">
      <input type="hidden" name="slug" id="rnSlug">
      <div class="modal-header"><h5 class="modal-title">Rinomina caserma</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body"><label class="form-label">Nuovo nome</label><input class="form-control" name="name" id="rnName" required></div>
      <div class="modal-footer"><button class="btn btn-primary">Salva</button></div>
    </form>
  </div>
</div>

<div class="modal fade" id="mSaPwd" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <input type="hidden" name="action" value="sa_pwd">
      <input type="hidden" name="username" id="saPwdUser">
      <div class="modal-header"><h5 class="modal-title">Cambia password — <span id="saPwdLbl"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body"><label class="form-label">Nuova password</label><input type="password" class="form-control" name="password" required></div>
      <div class="modal-footer"><button class="btn btn-primary">Salva</button></div>
    </form>
  </div>
</div>

<div class="modal fade" id="mSaRename" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <input type="hidden" name="action" value="sa_rename">
      <input type="hidden" name="old" id="saOldUser">
      <div class="modal-header"><h5 class="modal-title">Rinomina superadmin</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body"><label class="form-label">Nuovo username</label><input type="text" class="form-control" name="new" id="saNewUser" required></div>
      <div class="modal-footer"><button class="btn btn-primary">Salva</button></div>
    </form>
  </div>
</div>

<div class="modal fade" id="mTPwd" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <input type="hidden" name="action" value="t_pwd">
      <input type="hidden" name="username" id="tPwdUser">
      <div class="modal-header"><h5 class="modal-title">Cambia password — <span id="tPwdLbl"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body"><label class="form-label">Nuova password</label><input type="password" class="form-control" name="password" required></div>
      <div class="modal-footer"><button class="btn btn-primary">Salva</button></div>
    </form>
  </div>
</div>

<div class="modal fade" id="mTRename" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <input type="hidden" name="action" value="t_rename">
      <input type="hidden" name="old" id="tOldUser">
      <div class="modal-header"><h5 class="modal-title">Rinomina utente</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body"><label class="form-label">Nuovo username</label><input type="text" class="form-control" name="new" id="tNewUser" required></div>
      <div class="modal-footer"><button class="btn btn-primary">Salva</button></div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.getElementById('mRename')?.addEventListener('show.bs.modal', (ev)=>{
  const btn = ev.relatedTarget;
  document.getElementById('rnSlug').value = btn?.dataset?.slug || '';
  document.getElementById('rnName').value = btn?.dataset?.name || '';
});
document.querySelectorAll('.btn-sa-pwd').forEach(b=>{
  b.addEventListener('click', ()=>{
    document.getElementById('saPwdUser').value = b.dataset.username || '';
    document.getElementById('saPwdLbl').textContent = b.dataset.username || '';
  });
});
document.querySelectorAll('.btn-sa-rename').forEach(b=>{
  b.addEventListener('click', ()=>{
    document.getElementById('saOldUser').value = b.dataset.username || '';
    document.getElementById('saNewUser').value = b.dataset.username || '';
  });
});
document.querySelectorAll('.btn-t-pwd').forEach(b=>{
  b.addEventListener('click', ()=>{
    document.getElementById('tPwdUser').value = b.dataset.username || '';
    document.getElementById('tPwdLbl').textContent = b.dataset.username || '';
  });
});
document.querySelectorAll('.btn-t-rename').forEach(b=>{
  b.addEventListener('click', ()=>{
    document.getElementById('tOldUser').value = b.dataset.username || '';
    document.getElementById('tNewUser').value = b.dataset.username || '';
  });
});
</script>
</body>
</html>