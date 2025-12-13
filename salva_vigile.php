<?php
ob_start();
require __DIR__.'/auth.php';
require __DIR__.'/tenant_bootstrap.php'; // per leggere lo slug attivo se serve
require_tenant_user(); // blocca chi non è loggato per questa caserma
// salva_vigile.php (opzionale se tieni ancora questo endpoint)

require __DIR__.'/storage.php';



$cognome = trim(preg_replace('/\s+/', ' ', $_POST['cognome'] ?? ''));
$nome    = trim(preg_replace('/\s+/', ' ', $_POST['nome'] ?? ''));
$grado   = strtoupper(trim((string)($_POST['grado'] ?? '')));

if ($cognome === '' || $nome === '') {
  header('Location: index.php?err=campi'); exit;
}

$allowed = ['FTAV','CRV','CSV','VIG'];
if ($grado !== '' && !in_array($grado, $allowed, true)) {
  header('Location: index.php?err=grado'); exit;
}

$vigili = load_json(VIGILI_JSON);

// Dup su nome+cognome
foreach ($vigili as $v) {
  if (mb_strtolower($v['cognome'].' '.$v['nome']) === mb_strtolower($cognome.' '.$nome)) {
    header('Location: index.php?err=dup'); exit;
  }
}

$id = next_id($vigili);
$vigili[] = [
  'id'         => $id,
  'cognome'    => $cognome,
  'nome'       => $nome,
  'grado'      => ($grado !== '' ? $grado : null),
  'attivo'     => 1,
  'created_at' => date('Y-m-d H:i:s'),
];
save_json_atomic(VIGILI_JSON, $vigili);

header('Location: index.php', true, 303);
exit;