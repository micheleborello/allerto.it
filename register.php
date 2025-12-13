<?php
// register.php — registrazione account vigile (email+password) per Distaccamento

ob_start();
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__.'/tenant_bootstrap.php'; // load_caserme(), ecc.
require_once __DIR__.'/accounts_lib.php';

$caserme = load_caserme(); // [{slug,name}, …]
$err = '';
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $slug     = $_POST['slug'] ?? '';
  $vigileId = (int)($_POST['vigile_id'] ?? 0);
  $pin      = trim((string)($_POST['pin'] ?? ''));
  $email    = trim((string)($_POST['email'] ?? ''));
  $pass     = (string)($_POST['password'] ?? '');
  $pass2    = (string)($_POST['password2'] ?? '');

  if ($slug==='') { $err = 'Seleziona il Distaccamento.'; }
  elseif ($vigileId<=0) { $err = 'Inserisci un ID vigile valido.'; }
  elseif ($pin==='') { $err = 'Inserisci il PIN.'; }
  elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $err = 'Email non valida.'; }
  elseif (strlen($pass) < 6) { $err = 'Password troppo corta (min 6 caratteri).'; }
  elseif (!hash_equals($pass, $pass2)) { $err = 'Le password non coincidono.'; }
  else {
    $chk = acct_verify_vigile_pin($slug, $vigileId, $pin);
    if (!$chk['ok']) {
      $err = $chk['err'] ?? 'Dati non validi.';
    } else {
      $res = acct_create($slug, $vigileId, $email, $pass);
      if (!$res['ok']) {
        $err = $res['err'] ?? 'Impossibile creare l’account.';
      } else {
        $msg = 'Registrazione completata! Ora puoi accedere con la tua email e password.';
      }
    }
  }
}
?>
<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <title>Registrazione Vigile</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4" style="max-width:720px">
  <h1 class="h3 mb-3">Registrazione Vigile</h1>
  <p class="text-muted">Crea il tuo account per accedere e vedere i tuoi resoconti. Ti serviranno l’<strong>ID Vigile</strong> e il <strong>PIN</strong> impostato dal Capo.</p>

  <?php if ($err): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($err) ?></div>
  <?php endif; ?>
  <?php if ($msg): ?>
    <div class="alert alert-success">
      <?= htmlspecialchars($msg) ?> <a href="login.php" class="alert-link">Vai al login</a>.
    </div>
  <?php endif; ?>

  <div class="card shadow-sm">
    <div class="card-body">
      <form method="post" class="row g-3">
        <div class="col-12">
          <label class="form-label">Distaccamento</label>
          <select name="slug" class="form-select" required>
            <option value="" disabled selected>— scegli —</option>
            <?php foreach ($caserme as $c): ?>
              <option value="<?= htmlspecialchars($c['slug']) ?>">
                <?= htmlspecialchars(($c['name'] ?? $c['slug'])." ({$c['slug']})") ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-4">
          <label class="form-label">ID Vigile</label>
          <input type="number" class="form-control" name="vigile_id" required>
        </div>
        <div class="col-md-4">
          <label class="form-label">PIN</label>
          <input type="password" class="form-control" name="pin" inputmode="numeric" required>
        </div>
        <div class="col-12"><hr></div>

        <div class="col-md-6">
          <label class="form-label">Email</label>
          <input type="email" class="form-control" name="email" required>
        </div>
        <div class="col-md-3">
          <label class="form-label">Password</label>
          <input type="password" class="form-control" name="password" minlength="6" required>
        </div>
        <div class="col-md-3">
          <label class="form-label">Ripeti Password</label>
          <input type="password" class="form-control" name="password2" minlength="6" required>
        </div>

        <div class="col-12 d-grid">
          <button class="btn btn-primary">Registra</button>
        </div>
      </form>
    </div>
  </div>

  <div class="mt-3">
    <a href="login.php" class="btn btn-link">← Torna al login</a>
  </div>
</div>
</body>
</html>