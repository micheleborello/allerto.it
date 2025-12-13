<?php
// send_riepilogo_mensile_mail.php — invia via email il PDF ufficiale prodotto da report_mensile_tcpdf.php

ob_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

require __DIR__.'/auth.php';
require __DIR__.'/tenant_bootstrap.php';
require_tenant_user();

require __DIR__.'/lib/mailer_native.php';

// Permessi allineati agli altri export
require_perm('export:pdf');

// ---- INPUT ----
$meseStr = $_POST['mese'] ?? '';
$destStr = $_POST['destinatari'] ?? '';
$includiVuoti = !empty($_POST['includi_vuoti']); // se spunti "includi vuoti" nel form

if (!preg_match('/^\d{4}-\d{2}$/', $meseStr)) {
  http_response_code(400); die('Mese non valido');
}

$destinatari = mn_sanitize_emails($destStr);
if (empty($destinatari)) {
  http_response_code(400); die('Nessun destinatario valido');
}

// Dati caserma per oggetto
$casermaName = tenant_active_name();

// ---- Genera i BYTES dal report_mensile_tcpdf.php ----
// Forziamo la modalità 'S' (string) e settiamo i GET come se fosse richiamato da browser
define('REPORT_SEND_MODE', 'S');
$_GET['mese'] = $meseStr;
if ($includiVuoti) {
  $_GET['includi_vuoti'] = '1';
  unset($_GET['solo_con_ore']);
} else {
  $_GET['solo_con_ore'] = '1';
  unset($_GET['includi_vuoti']);
}

$prevLevel = ob_get_level();
ob_start();
include __DIR__.'/report_mensile_tcpdf.php';
$pdfBytes = ob_get_clean();
// Se qualcosa ha già scritto output inaspettato, ripulisci fino al livello iniziale
while (ob_get_level() > $prevLevel) { ob_end_clean(); }

if ($pdfBytes === '' || $pdfBytes === false || strlen($pdfBytes) < 1000) {
  // Soglia minima “furba” per capire se abbiamo davvero un PDF
  $err = 'Generazione PDF fallita o vuota.';
  header('Location: riepilogo.php?tipo=mensile&mese='.urlencode($meseStr).'&mail_err='.urlencode($err));
  exit;
}

// ---- Invio email ----
$fileName = 'report_mensile_'.$meseStr.'.pdf';
$subject  = 'Report mensile addestramenti — '.$casermaName.' — '.$meseStr;
$bodyTxt  = "In allegato il report mensile degli addestramenti (formato ufficiale).\n".
            "Distaccamento: {$casermaName}\nMese: {$meseStr}\n\nMessaggio generato automaticamente.";

$res = mn_send_mail_pdf($destinatari, $subject, $bodyTxt, $fileName, $pdfBytes);

if ($res['ok']) {
  header('Location: riepilogo.php?tipo=mensile&mese='.urlencode($meseStr).'&mail_ok=1');
  exit;
}
$err = $res['error'] ?: 'Invio fallito (mail())';
header('Location: riepilogo.php?tipo=mensile&mese='.urlencode($meseStr).'&mail_err='.urlencode($err));
exit;