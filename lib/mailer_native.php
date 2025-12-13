<?php
// lib/mailer_native.php — invio email con allegato PDF usando mail()
// Versione “deliverability-friendly” per Gmail: From/Return-Path coerenti,
// Date/Message-ID, line endings CRLF, invio 1-1 destinatario.

// === CONFIGURAZIONE MITTENTE (PERSONALIZZA) ===
// Mittente principale (visualizzato) e dominio
if (!defined('MAIL_FROM_EMAIL'))  define('MAIL_FROM_EMAIL',  'mail@allerto.it');
if (!defined('MAIL_FROM_NAME'))   define('MAIL_FROM_NAME',   'Allerto');
if (!defined('MAIL_FROM_DOMAIN')) define('MAIL_FROM_DOMAIN', 'allerto.it');

// Envelope sender (Return-Path). Idealmente = MAIL_FROM_EMAIL
// Deve essere una casella valida del tuo dominio.
if (!defined('MAIL_RETURN_PATH')) define('MAIL_RETURN_PATH', 'mail@allerto.it');


// ====== Helpers ======
function _mn_crlf_join(array $lines): string {
  // Mail RFC richiede CRLF
  return implode("\r\n", $lines);
}

function _mn_encode_header(string $text): string {
  if (function_exists('mb_encode_mimeheader')) {
    return mb_encode_mimeheader($text, 'UTF-8', 'B', "\r\n");
  }
  return '=?UTF-8?B?'.base64_encode($text).'?=';
}

function _mn_sanitize_header_value(string $s): string {
  return str_replace(["\r", "\n"], [' ', ' '], trim($s));
}

function mn_sanitize_emails(string $s): array {
  $parts = preg_split('/[,\s;]+/', $s, -1, PREG_SPLIT_NO_EMPTY);
  $ok = [];
  foreach ($parts as $p) {
    $p = trim($p);
    if ($p !== '' && filter_var($p, FILTER_VALIDATE_EMAIL)) $ok[] = $p;
  }
  return array_values(array_unique($ok));
}

// Mittente dinamico opzionale basato sul tenant (se usi tenant_*())
function _mn_from_for_tenant(): array {
  $email = MAIL_FROM_EMAIL;
  $name  = MAIL_FROM_NAME;

  if (function_exists('tenant_active_slug') && function_exists('tenant_active_name')) {
    $slug = (string) tenant_active_slug();
    $slugNorm = strtolower(preg_replace('/[^a-z0-9_-]/i', '', $slug));
    $caserma = trim((string) tenant_active_name());
    if ($caserma !== '') $name = 'Distaccamento di '.$caserma;

    // se vuoi usare un local-part dedicato basato sullo slug, scommenta:
    // if ($slugNorm !== '' && $slugNorm !== 'default') {
    //   $local = 'distaccamento.'.preg_replace('/[^a-z0-9]+/i', '', $slugNorm);
    //   if ($local !== '') { $email = $local.'@'.MAIL_FROM_DOMAIN; }
    // }
  }
  return [_mn_sanitize_header_value($email), _mn_sanitize_header_value($name)];
}

function _mn_message_id(string $domain): string {
  // <random.timestamp@domain>
  $rand = bin2hex(random_bytes(8));
  return sprintf('<%s.%d@%s>', $rand, time(), preg_replace('/[^a-z0-9\.\-]/i','', $domain));
}


// ====== Invio con PDF allegato ======
/**
 * @param array  $toEmails array di email validate
 * @param string $subject  oggetto
 * @param string $bodyText corpo in testo semplice (UTF-8)
 * @param string $fileName nome file allegato (es. "report.pdf")
 * @param string $fileBytes contenuto binario del PDF
 * @return array [ok=>bool, error=>string]
 */
function mn_send_mail_pdf(array $toEmails, string $subject, string $bodyText, string $fileName, string $fileBytes): array {
  if (empty($toEmails)) return ['ok'=>false, 'error'=>'Nessun destinatario valido'];

  [$fromEmail, $fromName] = _mn_from_for_tenant();
  $fromDisp = _mn_encode_header($fromName) . ' <' . $fromEmail . '>';
  $encodedSubject = _mn_encode_header($subject);

  // Boundary e date
  $boundary = '=_MN_'.bin2hex(random_bytes(12));
  $dateRFC2822 = date('r'); // Date header
  $msgId = _mn_message_id(MAIL_FROM_DOMAIN);

  // Header base
  $headers = [
    'From: ' . $fromDisp,
    'Reply-To: ' . $fromEmail,
    'Date: ' . $dateRFC2822,
    'Message-ID: ' . $msgId,
    'MIME-Version: 1.0',
    'Content-Type: multipart/mixed; boundary="'.$boundary.'"',
  ];

  $safeFilename = str_replace('"','', $fileName);

  // Corpo MIME (CRLF)
  $bodyParts = [];

  // Parte testo
  $bodyParts[] =
    '--'.$boundary."\r\n".
    "Content-Type: text/plain; charset=UTF-8\r\n".
    "Content-Transfer-Encoding: 8bit\r\n\r\n".
    $bodyText."\r\n";

  // Allegato PDF
  $bodyParts[] =
    '--'.$boundary."\r\n".
    'Content-Type: application/pdf; name="'.$safeFilename."\"\r\n".
    "Content-Transfer-Encoding: base64\r\n".
    'Content-Disposition: attachment; filename="'.$safeFilename."\"\r\n\r\n".
    chunk_split(base64_encode($fileBytes), 76, "\r\n")."\r\n";

  // Chiusura boundary
  $bodyParts[] = '--'.$boundary.'--';

  $body = implode('', $bodyParts);

  // Forza envelope sender / Return-Path (importante per Gmail & DMARC)
  // Nota: solo alcuni ambienti consentono -f; se il tuo hosting lo blocca, ignora silently.
  $extraParams = '';
  if (MAIL_RETURN_PATH && filter_var(MAIL_RETURN_PATH, FILTER_VALIDATE_EMAIL)) {
    $extraParams = '-f'.MAIL_RETURN_PATH;
  }

  $allOk = true; $lastErr = '';
  $headerStr = _mn_crlf_join($headers);

  foreach ($toEmails as $to) {
    $ok = @mail($to, $encodedSubject, $body, $headerStr, $extraParams);
    if (!$ok) { $allOk = false; $lastErr = 'mail() ha restituito false per '.$to; }
  }
  return ['ok'=>$allOk, 'error'=>$allOk ? '' : $lastErr];
}