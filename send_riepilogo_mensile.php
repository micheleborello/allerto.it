<?php
// send_riepilogo_mensile.php — invia via email il PDF ufficiale prodotto da report_mensile_tcpdf.php

ob_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

require __DIR__.'/auth.php';
require __DIR__.'/tenant_bootstrap.php';
require_tenant_user();

require_mfa();
require __DIR__.'/lib/mailer_native.php';
if (function_exists('require_perm')) { require_perm('export:pdf'); }

// ---- INPUT ----
$meseStr  = $_POST['mese']    ?? '';
$toStr    = $_POST['to']      ?? '';
$subject  = trim((string)($_POST['subject'] ?? ''));
$bodyText = (string)($_POST['body'] ?? '');

if (!preg_match('/^\d{4}-\d{2}$/', $meseStr)) {
  http_response_code(400); die('Mese non valido');
}

$toEmails = mn_sanitize_emails($toStr);
if (empty($toEmails)) {
  http_response_code(400); die('Nessun destinatario valido');
}

// Valori di fallback sensati
$casermaName = function_exists('tenant_active_name') ? tenant_active_name() : 'Distaccamento';
if ($subject === '') {
  $subject = 'Riepilogo mensile '.$meseStr.' — '.$casermaName;
}
if ($bodyText === '') {
  $bodyText = "In allegato il PDF del riepilogo mensile degli addestramenti per il mese $meseStr.\n\nDistaccamento: $casermaName";
}

// ---- Genera i BYTES dal report_mensile_tcpdf.php ----
// Forziamo la modalità 'S' (string) e impostiamo i GET come da richiesta
define('REPORT_SEND_MODE', 'S');
$_GET = [
  'mese' => $meseStr,
  // includi anche chi ha 0 ore, come il bottone "PDF mensile" in riepilogo
  'includi_vuoti' => '1',
];

$prevLevel = ob_get_level();
ob_start();
include __DIR__.'/report_mensile_tcpdf.php';
$pdfBytes = ob_get_clean();
while (ob_get_level() > $prevLevel) { ob_end_clean(); }

// Controllo minimo che sia un PDF plausibile
if ($pdfBytes === '' || $pdfBytes === false || strlen($pdfBytes) < 1000 || strpos($pdfBytes, '%PDF') !== 0) {
  ?>
  <!doctype html>
  <html lang="it"><head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <title>Invio PDF — Errore</title>
  </head><body class="bg-light">
  <div class="container py-4">
    <div class="alert alert-danger">
      <strong>Errore:</strong> generazione PDF fallita o vuota per il mese <code><?php echo htmlspecialchars($meseStr); ?></code>.
    </div>
    <a class="btn btn-secondary" href="riepilogo.php?tipo=mensile&mese=<?php echo urlencode($meseStr); ?>">Torna al riepilogo</a>
  </div></body></html>
  <?php
  exit;
}

// ---- Nome file allegato: addestramenti-<slug>-<mese-it>-<anno>.pdf ----
$anno = (int)substr($meseStr, 0, 4);
$mNum = (int)substr($meseStr, 5, 2);
$mesiIt = [
  1=>'gennaio', 2=>'febbraio', 3=>'marzo', 4=>'aprile', 5=>'maggio', 6=>'giugno',
  7=>'luglio', 8=>'agosto', 9=>'settembre', 10=>'ottobre', 11=>'novembre', 12=>'dicembre'
];
$meseIt = $mesiIt[$mNum] ?? $meseStr;

$slug = function_exists('tenant_active_slug') ? (string)tenant_active_slug() : 'default';
$slug = strtolower(preg_replace('/[^a-z0-9_-]/i','', $slug)); // sicuro nel filename

$fileName = sprintf('addestramenti-%s-%s-%d.pdf', $slug, $meseIt, $anno);

// ---- Invio email ----
$res = mn_send_mail_pdf($toEmails, $subject, $bodyText, $fileName, $pdfBytes);

?>
<!doctype html>
<html lang="it"><head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <title>Invio PDF — Esito</title>
</head><body class="bg-light">
<div class="container py-4">
  <?php if ($res['ok']): ?>
    <div class="alert alert-success">
      Email inviata correttamente a: <code><?php echo htmlspecialchars(implode(', ', $toEmails)); ?></code>.<br>
      Allegato: <code><?php echo htmlspecialchars($fileName); ?></code>
    </div>
  <?php else: ?>
    <div class="alert alert-danger">
      <strong>Invio non riuscito.</strong><br>
      <?php echo htmlspecialchars($res['error'] ?: 'mail() ha restituito false'); ?>
    </div>
  <?php endif; ?>
  <a class="btn btn-primary" href="riepilogo.php?tipo=mensile&mese=<?php echo urlencode($meseStr); ?>">Torna al riepilogo</a>
</div>
</body></html>