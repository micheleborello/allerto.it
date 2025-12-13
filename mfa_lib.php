<?php
// mfa_lib.php — OTP via email + configurazione semplice

// ====== CONFIGURAZIONE RAPIDA ======
if (!defined('MFA_ENABLED'))            define('MFA_ENABLED', true);                // disattiva MFA ovunque se false
if (!defined('MFA_REQUIRE_SUPERADMIN')) define('MFA_REQUIRE_SUPERADMIN', false);    // se false, i superadmin saltano MFA

// Mittente email (usa un dominio reale del tuo sito)
if (!defined('MFA_FROM_EMAIL')) define('MFA_FROM_EMAIL', 'no-reply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
if (!defined('MFA_FROM_NAME'))  define('MFA_FROM_NAME',  'AllertoGest');

// TTL codice OTP (secondi)
if (!defined('MFA_OTP_TTL')) define('MFA_OTP_TTL', 600); // 10 minuti

// In DEV/EMERGENZA: mostra il codice nella pagina (false in produzione!)
if (!defined('MFA_DEV_SHOW_CODE')) define('MFA_DEV_SHOW_CODE', false);

// ====== LOGICA: devo chiedere MFA a questo utente? ======
function mfa_should_require(array $ctx): bool {
  if (!MFA_ENABLED) return false;
  $type = $ctx['type'] ?? '';
  if ($type === 'superadmin' && !MFA_REQUIRE_SUPERADMIN) return false;
  return true;
}

// ====== Invio email OTP (con envelope sender e log) ======
function mfa_send_email(string $to, string $subject, string $body): bool {
  $fromName  = MFA_FROM_NAME;
  $fromEmail = MFA_FROM_EMAIL;

  $headers = [];
  $headers[] = 'MIME-Version: 1.0';
  $headers[] = 'Content-Type: text/plain; charset=UTF-8';
  $headers[] = 'From: ' . sprintf('"%s" <%s>', addslashes($fromName), $fromEmail);
  $headers[] = 'Reply-To: ' . $fromEmail;
  $headers[] = 'Date: ' . date('r');
  $headers[] = 'Message-ID: <' . uniqid() . '@' . preg_replace('/[:\/]/','-', ($_SERVER['HTTP_HOST'] ?? 'localhost')) . '>';
  $headers[] = 'X-Mailer: PHP/' . phpversion();

  // Alcuni hosting richiedono envelope sender (-f)
  $ok = @mail(
    $to,
    '=?UTF-8?B?'.base64_encode($subject).'?=',
    $body,
    implode("\r\n", $headers),
    '-f' . $fromEmail
  );

  // Fallback: logga sempre il codice in un file leggibile
  $logDir = __DIR__ . '/data';
  if (!is_dir($logDir)) @mkdir($logDir, 0775, true);
  $logFile = $logDir . '/_otp_log.txt';
  $line = sprintf("[%s] TO=%s | %s\n%s\n\n", date('Y-m-d H:i:s'), $to, $subject, $body);
  @file_put_contents($logFile, $line, FILE_APPEND);
  @chmod($logFile, 0664);

  return (bool)$ok;
}

// ====== Utility OTP ======
function mfa_generate_otp(): string {
  return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}
function mfa_store_code(string $code): void {
  if (session_status() === PHP_SESSION_NONE) session_start();
  $_SESSION['email_otp'] = $code;
  $_SESSION['email_otp_gen'] = time();
  $_SESSION['email_otp_attempts'] = 0;
}
function mfa_has_valid_code(): bool {
  if (session_status() === PHP_SESSION_NONE) session_start();
  $real = (string)($_SESSION['email_otp'] ?? '');
  $gen  = (int)($_SESSION['email_otp_gen'] ?? 0);
  if ($real === '') return false;
  return ((time() - $gen) <= MFA_OTP_TTL);
}
function mfa_is_code_valid(string $code): bool {
  if ($code === '' || !preg_match('/^\d{6}$/', $code)) return false;
  if (!mfa_has_valid_code()) return false;
  return hash_equals((string)$_SESSION['email_otp'], $code);
}