<?php
// login.php — NO BOM, nessuno spazio prima di <?php
ob_start();
require __DIR__.'/auth.php'; // gestione utenti/permessi (avvia sessione)

// ------------------------------------------------------------------
// Elenco distaccamenti
// ------------------------------------------------------------------
function read_json_relaxed($file){
    if (!is_file($file)) return [];
    $s = @file_get_contents($file);
    if ($s === false) return [];
    $s = preg_replace('/^\xEF\xBB\xBF/', '', $s);
    $s = preg_replace('/,\s*([\}\]])/', '$1', $s);
    $j = json_decode($s, true);
    return is_array($j) ? $j : [];
}
function load_distaccamenti_for_login(): array {
    $fromCfg = read_json_relaxed(__DIR__.'/caserme.json');
    $out = [];
    if (is_array($fromCfg) && !empty($fromCfg)) {
        foreach ($fromCfg as $r) {
            $slug = preg_replace('/[^a-z0-9_-]/i','', (string)($r['slug'] ?? ''));
            $name = trim((string)($r['name'] ?? ''));
            if ($slug==='') continue;
            if (is_dir(__DIR__.'/data/'.$slug)) {
                $out[$slug] = ['slug'=>$slug, 'name'=> ($name!=='' ? $name : $slug)];
            }
        }
    }
    foreach (glob(__DIR__.'/data/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $slug = basename($dir);
        if (!preg_match('/^[a-z0-9_-]+$/i', $slug)) continue;
        if (is_file($dir.'/users.json')) {
            if (!isset($out[$slug])) $out[$slug] = ['slug'=>$slug, 'name'=>$slug];
        }
    }
    uasort($out, fn($a,$b)=> strcasecmp($a['name'],$b['name']));
    return array_values($out);
}
$distaccamenti = load_distaccamenti_for_login();

// ===== Sessione/CSRF =====
if (session_status() === PHP_SESSION_NONE) session_start();
$err  = '';
$next = $_POST['next'] ?? ($_GET['next'] ?? 'index.php');
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));

// Normalizza username in formato nome.cognome (solo tenant)
function normalize_username_nome_cognome(string $u): string {
    $u = strtolower(trim($u));
    // sostituisci spazi/underscore con punto
    $u = preg_replace('/[\\s_]+/', '.', $u);
    // togli caratteri non alfanumerici/punto
    $u = preg_replace('/[^a-z0-9\\.]+/', '', $u);
    // compat: inverti se scritto "cognome nome" con spazio -> già sopra produce cognome.nome
    $u = preg_replace('/\\.\\.+/', '.', $u);          // punti multipli -> uno
    $u = trim($u, '.');                               // niente punti ai bordi
    return $u;
}

// ===== Anti-bruteforce semplice per sessione =====
$LOCK_SECONDS = 60;
$MAX_ERRORS   = 5;
$now = time();
$lockedUntil = (int)($_SESSION['login_lock_until'] ?? 0);
$locked = $lockedUntil > $now;

// ===== Preselezione slug da GET se presente =====
$prefSlug = '';
if (!empty($_GET['slug'])) {
    $prefSlug = preg_replace('/[^a-z0-9_-]/i','', (string)$_GET['slug']);
}

// Tentativo automatico: prova a fare login tenant su tutti i distaccamenti
function try_login_tenant_auto(string $username, string $password, array $distaccamenti): string {
    foreach ($distaccamenti as $d) {
        $slug = $d['slug'] ?? '';
        if ($slug === '') continue;
        if (auth_login_tenant($slug, $username, $password)) {
            return $slug;
        }
    }
    return '';
}

// ===== POST: Login =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(400); die('Bad CSRF');
    }
    if ($locked) {
        $err = 'Troppi tentativi. Attendi qualche secondo e riprova.';
    } else {
        $username_raw = trim((string)($_POST['username'] ?? ''));
        $password     = (string)($_POST['password'] ?? '');

        // helper: destinazione post-login per tenant
        $dest_for_tenant = function() use ($next) {
            // Dopo login utente porta di default alla dashboard vigile (salvo redirect esplicito)
            if ($next !== '') return $next;
            return 'dashboard_vigile.php';
        };

        // 1) tenta superadmin con username così com'è
        if ($username_raw !== '' && $password !== '' && auth_login_superadmin($username_raw, $password)) {
            $_SESSION['mfa_ok'] = 1;
            $_SESSION['login_fail'] = 0;
            $_SESSION['login_lock_until'] = 0;
            header('Location: ' . ($next ?: 'index.php')); exit;
        }

        // 2) tenta tenant su tutti i distaccamenti con username normalizzato nome.cognome
        $username = normalize_username_nome_cognome($username_raw);
        if ($username !== '' && $password !== '') {
            $usedSlug = try_login_tenant_auto($username, $password, $distaccamenti);
            if ($usedSlug !== '') {
                $_SESSION['mfa_ok'] = 1;
                $_SESSION['login_fail'] = 0;
                $_SESSION['login_lock_until'] = 0;
                header('Location: ' . $dest_for_tenant()); exit;
            }
        }
        $err = 'Credenziali non valide.';

        if ($err !== '') {
            $fails = (int)($_SESSION['login_fail'] ?? 0);
            $fails++;
            $_SESSION['login_fail'] = $fails;
            if ($fails >= $MAX_ERRORS) {
                $_SESSION['login_lock_until'] = $now + $LOCK_SECONDS;
            }
        }
    }
}
?>
<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <title>Accesso | AllertoGest Addestramenti</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body{min-height:100vh;background:linear-gradient(135deg,#0d6efd11,#0dcaf011);}
    .login-card{max-width:420px;margin:6vh auto;border:0;border-radius:1rem;box-shadow:0 10px 30px rgba(0,0,0,.08)}
    .brand-circle{width:56px;height:56px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:#0d6efd;color:#fff;margin:0 auto 12px;font-weight:700}
    .muted{color:#6c757d}
    .eye-btn{position:absolute;right:.75rem;top:50%;transform:translateY(-50%);border:0;background:transparent}
  </style>
</head>
<body>
  <div class="container">
    <div class="login-card card">
      <div class="card-body p-4 p-lg-5">
        <div class="text-center mb-3">
          <div class="brand-circle">VVF</div>
          <h1 class="h4 mb-0">AllertoGest — Accesso</h1>
          <div class="muted">Super Admin o Distaccamento</div>
        </div>

        <?php if (!empty($err)): ?>
          <div class="alert alert-danger" role="alert">
            <?= htmlspecialchars($err) ?>
          </div>
        <?php endif; ?>

        <?php if ($locked): ?>
          <div class="alert alert-warning" role="alert">
            Troppi tentativi: attesa in corso. Riprova fra <?= max(1, (int)(($_SESSION['login_lock_until'] ?? time()) - time())); ?> secondi.
          </div>
        <?php endif; ?>

        <?php
          $POST_username = $_POST['username'] ?? '';
        ?>

        <form method="post" novalidate>
          <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
          <input type="hidden" name="next" value="<?= htmlspecialchars($next) ?>">

          <div class="mb-3">
            <label class="form-label">Utente</label>
            <input type="text" class="form-control" name="username" value="<?= htmlspecialchars($POST_username) ?>" autocomplete="username" required>
          </div>

          <div class="mb-1 position-relative">
            <label class="form-label">Password</label>
            <input type="password" class="form-control" name="password" id="pwdInput" autocomplete="current-password" required>
            <button class="eye-btn" type="button" id="togglePwd" aria-label="Mostra password">👁</button>
          </div>

          <div class="d-grid gap-2 mt-3">
            <button class="btn btn-primary btn-lg" type="submit" <?= $locked?'disabled':'' ?>>Entra</button>
            <a class="btn btn-outline-secondary" href="https://allerto.it/test" target="_blank" rel="noopener">Vai a Test</a>
          </div>
        </form>

        <div class="d-flex justify-content-between align-items-center mt-3">
          <a class="small text-decoration-none" href="caserme.php">Gestione Distaccamento (solo Super Admin)</a>
          <a id="forgotLink" class="small" href="#">Password dimenticata / Primo accesso</a>
        </div>
      </div>
    </div>

    <p class="text-center muted small">
      © <?= date('Y') ?> AllertoGest — Vigili del Fuoco Volontari
    </p>
  </div>

  <script>
    const forgotLink = document.getElementById('forgotLink');

    function updateForgotLink(){
      const next = <?= json_encode($next, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;
      const url = 'vol_pwd_reset.php' + (next ? ('?next=' + encodeURIComponent(next)) : '');
      if (forgotLink) forgotLink.setAttribute('href', url);
    }

    updateForgotLink();

    (function(){
      const btn = document.getElementById('togglePwd');
      const inp = document.getElementById('pwdInput');
      if (!btn || !inp) return;
      btn.addEventListener('click', function(){
        const t = inp.getAttribute('type') === 'password' ? 'text' : 'password';
        inp.setAttribute('type', t);
      });
    })();
  </script>
</body>
</html>
