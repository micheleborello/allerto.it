<?php
// vol_login.php — login volontari con username+password (tenant-aware + mapping robusto)
ob_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

require __DIR__.'/tenant_bootstrap.php';
require __DIR__.'/storage.php';

if (session_status() === PHP_SESSION_NONE) @session_start();

/* ======= Helpers local ======= */
if (!function_exists('normalize_ascii')) {
  function normalize_ascii(string $s): string {
    if (function_exists('iconv')) {
      $s = iconv('UTF-8','ASCII//TRANSLIT',$s);
    }
    // solo [a-z0-9], niente spazi o simboli
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/','', $s);
    return $s ?: '';
  }
}
if (!function_exists('suggest_username_from_vigile')) {
  function suggest_username_from_vigile(array $v): string {
    $nome = normalize_ascii((string)($v['nome'] ?? ''));
    $cogn = normalize_ascii((string)($v['cognome'] ?? ''));
    $base = ($cogn !== '' ? $cogn : 'user').'.'.($nome !== '' ? $nome : 'user');
    return $base;
  }
}

/* ======= SLUG / tenant ======= */
$slug = isset($_GET['slug']) ? preg_replace('/[^a-z0-9_-]/i','', (string)$_GET['slug']) : '';
if ($slug === '') {
  $slug = isset($_SESSION['tenant_slug']) ? preg_replace('/[^a-z0-9_-]/i','', (string)$_SESSION['tenant_slug']) : '';
}
if ($slug === '' && function_exists('tenant_active_slug')) {
  $slug = preg_replace('/[^a-z0-9_-]/i','', (string)tenant_active_slug());
}
if ($slug === '') { $slug = 'default'; } // fallback

/* ======= Destinazione post-login (default: check-in) ======= */
$next = isset($_GET['next']) ? (string)$_GET['next'] : ('checkin.php?slug='.$slug);

/* ======= Percorsi coerenti con il resto ======= */
$dataDir    = __DIR__.'/data/'.$slug;
$usersPath  = $dataDir.'/users.json';
$vigiliPath = $dataDir.'/vigili.json';

/* ======= CSRF ======= */
if (empty($_SESSION['_csrf_vol_login'])) $_SESSION['_csrf_vol_login'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['_csrf_vol_login'];

$err = '';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  try {
    if (!hash_equals($csrf, $_POST['_csrf'] ?? '')) throw new Exception('CSRF non valido.');

    $username = strtolower(trim((string)($_POST['username'] ?? '')));
    $password = (string)($_POST['password'] ?? '');
    $goto     = (string)($_POST['goto'] ?? ''); // "profile" = vai al profilo

    if ($username === '' || $password === '') throw new Exception('Inserisci username e password.');
    if (!is_file($usersPath)) throw new Exception('Archivio utenti non trovato per il distaccamento selezionato.');

    $usersRaw = @file_get_contents($usersPath);
    $usersArr = json_decode($usersRaw, true);
    if (!is_array($usersArr) || count($usersArr)===0) throw new Exception('Nessun utente configurato.');

    // --- trova utente
    $found = null;
    foreach ($usersArr as $u) {
      if (strtolower((string)($u['username'] ?? '')) === $username) { $found = $u; break; }
    }
    if (!$found) throw new Exception('Utente non trovato.');

    $hash = (string)($found['password_hash'] ?? '');
    if ($hash === '' || !password_verify($password, $hash)) {
      throw new Exception('Password errata.');
    }

    // --- collega info vigile (ID, nome, cognome) con logica robusta
    $vol = [
      'username' => $username,
      'slug'     => $slug,
      'perms'    => is_array($found['perms'] ?? []) ? $found['perms'] : [],
    ];

    // carica anagrafe (se presente) per tentare match
    $vigArr = [];
    if (is_file($vigiliPath)) {
      $vigRaw = @file_get_contents($vigiliPath);
      $tmpArr = json_decode($vigRaw, true);
      if (is_array($tmpArr)) $vigArr = $tmpArr;
    }

    $match = null;

    // 1) match diretto su users.json: vigile_id
    $maybeVid = (int)($found['vigile_id'] ?? 0);
    if ($maybeVid > 0 && $vigArr) {
      foreach ($vigArr as $v) {
        if ((int)($v['id'] ?? 0) === $maybeVid) { $match = $v; break; }
      }
    }

    // 2) se non trovato: match su campo username nel vigile (se esiste)
    if (!$match && $vigArr) {
      foreach ($vigArr as $v) {
        if (isset($v['username']) && strtolower((string)$v['username']) === $username) {
          $match = $v; break;
        }
      }
    }

    // 3) se ancora nulla: match su "cognome.nome" normalizzato (come nelle impostazioni)
    if (!$match && $vigArr) {
      foreach ($vigArr as $v) {
        $sug = suggest_username_from_vigile($v);
        if ($sug === $username) { $match = $v; break; }
      }
    }

    if ($match) {
      $vol['id']      = (int)($match['id'] ?? 0);
      $vol['cognome'] = (string)($match['cognome'] ?? '');
      $vol['nome']    = (string)($match['nome'] ?? '');
    }
    // NB: se non riusciamo a collegare il vigile, il login va comunque a buon fine:
    //     sarà il check-in a segnalare che non trova un vigile attivo legato all’utente.

    $_SESSION['vol_user']    = $vol;
    $_SESSION['tenant_slug'] = $slug;

    // “Accedi e vai al mio profilo”
    if ($goto === 'profile') {
      $next = 'dashboard_vigile.php';
    }

    header('Location: '.$next, true, 303);
    exit;

  } catch(Throwable $e) { $err = $e->getMessage(); }
}

// UI
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Login Volontari</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>body{background:#f7f9fc}</style>
</head>
<body class="d-flex align-items-center justify-content-center" style="min-height:100vh;">
<div class="container" style="max-width:420px">
  <div class="card shadow-sm">
    <div class="card-body">
      <h1 class="h5 mb-3">Accesso Volontari — Distaccamento <code><?= htmlspecialchars($slug) ?></code></h1>
      <?php if ($err): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($err) ?></div><?php endif; ?>

      <form method="post" class="vstack gap-2">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="next"  value="<?= htmlspecialchars($next) ?>">
        <div>
          <label class="form-label">Username</label>
          <input name="username" class="form-control" autocomplete="username" required>
        </div>
        <div>
          <label class="form-label">Password</label>
          <input name="password" type="password" class="form-control" autocomplete="current-password" required>
        </div>

        <div class="d-grid gap-2 mt-2">
          <button class="btn btn-primary" name="goto" value="">Accedi</button>
          <button class="btn btn-outline-primary" name="goto" value="profile">Accedi e vai al mio profilo</button>
        </div>
      </form>

      <div class="mt-3 small d-flex justify-content-between">
        <a href="vol_pwd_reset.php?slug=<?= urlencode($slug) ?>&next=<?= urlencode($next) ?>">Password dimenticata / Primo accesso</a>
        <a href="dashboard_vigile.php">Vai alla mia scheda</a>
      </div>
    </div>
  </div>
</div>
</body>
</html>