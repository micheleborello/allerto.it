<?php
// totp_verify.php — Verifica OTP via email + possibilità di registrare l’email
// NO BOM e nessun output prima di <?php

require __DIR__.'/auth.php';
require_once __DIR__.'/mfa_lib.php'; // funzioni MFA/OTP + config

if (session_status() === PHP_SESSION_NONE) session_start();

// --- CSRF ---
if (empty($_SESSION['csrf_otp'])) $_SESSION['csrf_otp'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['csrf_otp'];

// --- Parametri navigazione ---
$next = $_POST['next'] ?? ($_GET['next'] ?? 'index.php');

// --- Contesto MFA impostato dal login ---
$ctx  = $_SESSION['mfa_ctx'] ?? null;
$user = $_SESSION['user']    ?? null;

if (!$user || !$ctx || empty($ctx['type'])) {
  header('Location: login.php'); exit;
}

// Se per questo utente NON serve MFA (es. superadmin) → passa oltre
if ((function_exists('mfa_should_require') && !mfa_should_require($ctx))
    || (function_exists('auth_is_superadmin') && auth_is_superadmin() && defined('MFA_REQUIRE_SUPERADMIN') && !MFA_REQUIRE_SUPERADMIN)) {
  $_SESSION['mfa_ok'] = 1;
  header('Location: '.$next);
  exit;
}

// Helper JSON rilassato
if (!function_exists('json_load_relaxed')) {
  function json_load_relaxed(string $p){
    if (!is_file($p)) return [];
    $s = @file_get_contents($p);
    if ($s === false) return [];
    $s = preg_replace('/^\xEF\xBB\xBF/', '', $s);
    $s = preg_replace('#/\*.*?\*/#s', '', $s);
    $s = preg_replace('#//.*$#m',    '', $s);
    $s = preg_replace('/,\s*([\}\]])/', '$1', $s);
    $j = json_decode($s, true);
    return is_array($j) ? $j : [];
  }
}
// Salvataggio atomico
if (!function_exists('save_json_atomic')) {
  function save_json_atomic(string $path, $data): void {
    $dir = dirname($path);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $json = json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
    if ($json === false) throw new RuntimeException('json_encode fallita: '.basename($path));
    $tmp = $path.'.tmp_'.uniqid('', true);
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) { @unlink($tmp); throw new RuntimeException('Scrittura tmp fallita'); }
    @chmod($tmp, 0664);
    if (!@rename($tmp, $path)) { @unlink($tmp); throw new RuntimeException('Rename atomico fallito'); }
  }
}

// File utenti
function path_superadmin_users(): string { return __DIR__.'/data/superadmin_users.json'; }
function path_tenant_users(string $slug): string { return __DIR__.'/data/'.$slug.'/users.json'; }

// Leggi/salva e-mail
function get_user_email(array $ctx): string {
  if (($ctx['type'] ?? '') === 'superadmin') {
    $rows = json_load_relaxed(path_superadmin_users());
    foreach ($rows as $r) {
      if (strcasecmp((string)($r['username'] ?? ''), (string)$ctx['username']) === 0) {
        return trim((string)($r['email'] ?? ''));
      }
    }
  } elseif (($ctx['type'] ?? '') === 'tenant') {
    $slug = preg_replace('/[^a-z0-9_-]/i','', (string)($ctx['slug'] ?? ''));
    if ($slug !== '') {
      $rows = json_load_relaxed(path_tenant_users($slug));
      foreach ($rows as $r) {
        if (strcasecmp((string)($r['username'] ?? ''), (string)$ctx['username']) === 0) {
          return trim((string)($r['email'] ?? ''));
        }
      }
    }
  }
  return '';
}
function set_user_email(array $ctx, string $email): bool {
  $email = trim($email);
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return false;

  if (($ctx['type'] ?? '') === 'superadmin') {
    $p = path_superadmin_users();
    $rows = json_load_relaxed($p);
    $ok = false;
    foreach ($rows as &$r) {
      if (strcasecmp((string)($r['username'] ?? ''), (string)$ctx['username']) === 0) {
        $r['email'] = $email; $ok = true; break;
      }
    } unset($r);
    if ($ok) { save_json_atomic($p, $rows); return true; }
  } elseif (($ctx['type'] ?? '') === 'tenant') {
    $slug = preg_replace('/[^a-z0-9_-]/i','', (string)($ctx['slug'] ?? ''));
    if ($slug !== '') {
      $p = path_tenant_users($slug);
      $rows = json_load_relaxed($p);
      $ok = false;
      foreach ($rows as &$r) {
        if (strcasecmp((string)($r['username'] ?? ''), (string)$ctx['username']) === 0) {
          $r['email'] = $email; $ok = true; break;
        }
      } unset($r);
      if ($ok) { save_json_atomic($p, $rows); return true; }
    }
  }
  return false;
}

// Maschera email
function mask_email(string $email): string {
  if ($email === '') return '';
  [$local, $dom] = explode('@', $email, 2) + ['', ''];
  $m = (strlen($local) <= 2) ? substr($local,0,1).'*' : substr($local,0,1).str_repeat('*', max(1, strlen($local)-2)).substr($local,-1);
  return $m.($dom!==''?'@'.$dom:'');
}

// Invio OTP (usa mfa_lib e log)
function send_email_otp(string $to, string $code, string $subject='Codice di verifica AllertoGest'): bool {
  $body = "Il tuo codice di verifica è: ".$code."\nValido per 10 minuti.\n";
  if (function_exists('mfa_send_email')) return (bool)mfa_send_email($to, $subject, $body);

  // fallback se mfa_send_email non esiste (non dovrebbe succedere)
  $headers = "Content-Type: text/plain; charset=UTF-8\r\n";
  $headers .= "X-Mailer: PHP/".phpversion()."\r\n";
  $ok = @mail($to, $subject, $body, $headers);

  $logDir = __DIR__ . '/data';
  if (!is_dir($logDir)) @mkdir($logDir, 0775, true);
  $logFile = $logDir . '/_otp_log.txt';
  $line = sprintf("[%s] TO=%s | %s\n%s\n\n", date('Y-m-d H:i:s'), $to, $subject, $body);
  @file_put_contents($logFile, $line, FILE_APPEND);
  @chmod($logFile, 0664);

  return $ok;
}

$info  = '';
$err   = '';
$email = get_user_email($ctx);

// === AUTO-INVIO SU GET: se c’è un’email e NON c’è un codice valido in sessione, genera e invia subito ===
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $email !== '' && !mfa_has_valid_code()) {
  $code = mfa_generate_otp();
  mfa_store_code($code);
  if (!send_email_otp($email, $code)) {
    $err = 'Invio email fallito. Controlla la configurazione di posta del server.';
  } else {
    $info = 'Codice inviato a '.htmlspecialchars(mask_email($email)).'. Controlla la casella (o il file <code>data/_otp_log.txt</code>).';
  }
}

// --- Gestione POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hash_equals($CSRF, $_POST['csrf'] ?? '')) { http_response_code(400); exit('Bad CSRF'); }

  $action = $_POST['action'] ?? '';

  // 1) Imposta/aggiorna email
  if ($action === 'set_email') {
    $new = trim((string)($_POST['email'] ?? ''));
    if (!filter_var($new, FILTER_VALIDATE_EMAIL)) {
      $err = 'Inserisci un indirizzo email valido.';
    } else {
      if (!set_user_email($ctx, $new)) {
        $err = 'Impossibile salvare l’email (utente non trovato o file inaccessibile).';
      } else {
        $email = $new;
        $code = mfa_generate_otp();
        mfa_store_code($code);
        if (!send_email_otp($email, $code)) {
          $err = 'Invio email fallito. Controlla la configurazione di posta del server.';
        } else {
          $info = 'Codice inviato a '.htmlspecialchars(mask_email($email)).'.';
        }
      }
    }
  }

  // 2) Richiedi/invia un nuovo OTP all’email esistente
  if ($action === 'request_code') {
    if ($email === '') {
      $err = 'Nessuna email registrata. Inseriscila qui sotto.';
    } else {
      $code = mfa_generate_otp();
      mfa_store_code($code);
      if (!send_email_otp($email, $code)) {
        $err = 'Invio email fallito. Controlla la configurazione di posta del server.';
      } else {
        $info = 'Nuovo codice inviato a '.htmlspecialchars(mask_email($email)).'.';
      }
    }
  }

  // 3) Verifica codice
  if ($action === 'verify_code') {
    $code = trim((string)($_POST['code'] ?? ''));
    if (!mfa_is_code_valid($code)) {
      if (!preg_match('/^\d{6}$/', $code))      $err = 'Codice non valido.';
      else if (!mfa_has_valid_code())           $err = 'Codice scaduto. Richiedine uno nuovo.';
      else                                      $err = 'Codice errato. Riprova o richiedi un nuovo codice.';
    } else {
      $_SESSION['mfa_ok'] = 1;
      unset($_SESSION['email_otp'], $_SESSION['email_otp_gen'], $_SESSION['email_otp_attempts']);
      header('Location: '.$next); exit;
    }
  }
}

// Per UI
$hasEmail = ($email !== '');
$masked   = $hasEmail ? mask_email($email) : '';
$dev_show = (defined('MFA_DEV_SHOW_CODE') && MFA_DEV_SHOW_CODE && !empty($_SESSION['email_otp'])) ? $_SESSION['email_otp'] : '';
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Verifica email — AllertoGest</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  body{background:#f7f9fc;}
  .card{box-shadow:0 8px 24px rgba(0,0,0,.06); border:0; border-radius:1rem;}
</style>
</head>
<body class="d-flex align-items-center justify-content-center" style="min-height:100vh;">
<div class="container" style="max-width:520px">
  <div class="card p-4">
    <h1 class="h4 mb-3">Verifica in due passaggi</h1>
    <p class="text-muted mb-4">Per proteggere l’accesso, ti inviamo un codice di verifica via email.</p>

    <?php if (!empty($err)): ?>
      <div class="alert alert-danger py-2"><?= htmlspecialchars($err) ?></div>
    <?php endif; ?>
    <?php if (!empty($info)): ?>
      <div class="alert alert-success py-2"><?= $info /* già safe */ ?></div>
    <?php endif; ?>

    <?php if (!$hasEmail): ?>
      <!-- NESSUNA EMAIL: registra email -->
      <form method="post" class="mb-3">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF) ?>">
        <input type="hidden" name="next" value="<?= htmlspecialchars($next) ?>">
        <input type="hidden" name="action" value="set_email">
        <div class="mb-2">
          <label class="form-label">Inserisci la tua email</label>
          <input type="email" name="email" class="form-control" required placeholder="nome@esempio.it" autocomplete="email">
        </div>
        <button class="btn btn-primary w-100">Salva email e invia codice</button>
      </form>
      <div class="small text-muted">L’indirizzo verrà salvato nel tuo profilo per i futuri accessi.</div>

    <?php else: ?>
      <!-- EMAIL PRESENTE: richiesta e verifica OTP -->
      <div class="mb-3">
        <div class="alert alert-info py-2 mb-2">
          Email registrata: <strong><?= htmlspecialchars($masked) ?></strong>
        </div>
        <form method="post" class="d-flex gap-2">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF) ?>">
          <input type="hidden" name="next" value="<?= htmlspecialchars($next) ?>">
          <input type="hidden" name="action" value="request_code">
          <button class="btn btn-outline-primary flex-fill">Invia / Reinvia codice</button>
        </form>
      </div>

      <form method="post">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF) ?>">
        <input type="hidden" name="next" value="<?= htmlspecialchars($next) ?>">
        <input type="hidden" name="action" value="verify_code">
        <div class="mb-2">
          <label class="form-label">Codice a 6 cifre</label>
          <input type="text" name="code" class="form-control" inputmode="numeric" pattern="\d{6}" maxlength="6" placeholder="••••••" required>
        </div>
        <button class="btn btn-success w-100">Verifica e continua</button>
      </form>

      <hr class="my-4">
      <details>
        <summary class="small">Hai cambiato indirizzo? Aggiorna email</summary>
        <form method="post" class="mt-2">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF) ?>">
          <input type="hidden" name="next" value="<?= htmlspecialchars($next) ?>">
          <input type="hidden" name="action" value="set_email">
          <div class="input-group">
            <input type="email" name="email" class="form-control" placeholder="nuova-email@esempio.it" required>
            <button class="btn btn-outline-secondary">Aggiorna & invia codice</button>
          </div>
        </form>
      </details>
    <?php endif; ?>

    <?php if ($dev_show !== ''): ?>
      <div class="alert alert-warning mt-3">
        <strong>DEV:</strong> codice OTP corrente = <code><?= htmlspecialchars($dev_show) ?></code>
      </div>
    <?php endif; ?>
  </div>

  <p class="text-center text-muted small mt-3">
    <a href="logout.php" class="text-decoration-none">Esci</a>
  </p>
</div>
</body>
</html>
