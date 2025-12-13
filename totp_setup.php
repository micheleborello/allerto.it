<?php
// totp_setup.php — prima attivazione TOTP per l’utente loggato (post-password)
if (session_status() === PHP_SESSION_NONE) { @session_start(); }
require_once __DIR__.'/tenant_bootstrap.php';
require_once __DIR__.'/mfa_lib.php';

$next = $_GET['next'] ?? 'index.php';
$ctx  = $_SESSION['mfa_ctx'] ?? null;
if (!$ctx || empty($ctx['username']) || empty($ctx['type'])) {
  header('Location: login.php?next='.urlencode($next)); exit;
}

// Se già c'è un segreto salvato → vai direttamente a verifica
if (mfa_get_secret($ctx) !== '') {
  header('Location: totp_verify.php?next='.urlencode($next)); exit;
}

// Genera UNA VOLTA il segreto “pendente” e tienilo in sessione finché non viene confermato
if (empty($_SESSION['totp_pending_secret'])) {
  $_SESSION['totp_pending_secret'] = mfa_generate_secret();
}
$secret = $_SESSION['totp_pending_secret'];
$label  = mfa_label_from_ctx($ctx);
$issuer = MFA_ISSUER;
$otpauth = mfa_otpauth_uri($secret, $label, $issuer, MFA_DIGITS, MFA_PERIOD);

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $code = trim((string)($_POST['code'] ?? ''));
  if (mfa_verify_code($secret, $code, MFA_WINDOW, MFA_PERIOD)) {
    // Persisti e passa alla verifica “normale”
    mfa_set_secret($ctx, $secret);
    unset($_SESSION['totp_pending_secret']);
    header('Location: totp_verify.php?next='.urlencode($next));
    exit;
  } else {
    $err = 'Codice non valido. Riprova.';
  }
}
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Attiva verifica in 2 passaggi</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4" style="max-width:640px">
  <h1 class="h3">Protezione aggiuntiva</h1>
  <p class="text-muted">Configura l’autenticazione a 2 fattori (TOTP) per l’account <strong><?= htmlspecialchars($label) ?></strong>.</p>

  <?php if ($err): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($err) ?></div>
  <?php endif; ?>

  <div class="card shadow-sm mb-3">
    <div class="card-body">
      <ol class="mb-0">
        <li>Apri Google Authenticator, Microsoft Authenticator o Authy.</li>
        <li>Scansiona il QR qui sotto <span class="text-muted">(oppure inserisci manualmente la chiave)</span>.</li>
      </ol>
      <div class="text-center my-3">
        <img alt="QR" width="240" height="240"
             src="https://chart.googleapis.com/chart?cht=qr&chs=240x240&chl=<?= urlencode($otpauth) ?>">
      </div>
      <div class="bg-light border rounded p-2">
        <div class="small text-muted">Chiave manuale (Base32):</div>
        <div class="fs-5 fw-semibold"><?= htmlspecialchars($secret) ?></div>
        <div class="small text-muted mt-1">Issuer: <?= htmlspecialchars($issuer) ?> — Digits: <?= (int)MFA_DIGITS ?> — Periodo: <?= (int)MFA_PERIOD ?>s</div>
      </div>
    </div>
  </div>

  <form method="post" class="card shadow-sm">
    <div class="card-body">
      <label class="form-label">Inserisci il codice a 6 cifre</label>
      <input type="text" inputmode="numeric" pattern="\d{6}" maxlength="6" class="form-control" name="code" autofocus required>
      <div class="form-text">Il codice cambia ogni <?= (int)MFA_PERIOD ?> secondi.</div>
    </div>
    <div class="card-footer d-flex gap-2">
      <button class="btn btn-primary">Conferma e attiva</button>
      <a class="btn btn-outline-secondary" href="logout.php">Annulla</a>
    </div>
  </form>
</div>
</body>
</html>