<?php
// qr_checkin.php — registra check-in QR (orario preciso) e crea record addestramento se assente
// URL: qr_checkin.php?s=SESSION_ID&v=VIGILE_ID&t=SLUG

ob_start();
require __DIR__.'/auth.php';
require __DIR__.'/tenant_bootstrap.php'; // può impostare DATA_DIR in base a $_SESSION['tenant_slug']
require __DIR__.'/storage.php';
require __DIR__.'/utils.php';

// 1) Parametri
$session_id = (int)($_GET['s'] ?? 0);
$vigile_id  = (int)($_GET['v'] ?? 0);
$slug       = preg_replace('/[^a-z0-9_-]/i','', (string)($_GET['t'] ?? ''));

// 2) Se slug indicato, attivalo nella session (per scegliere il DATA_DIR corretto)
if ($slug !== '') {
  $_SESSION['tenant_slug']  = $slug;
  $_SESSION['CASERMA_SLUG'] = $slug;
}

// 3) Sanity
if ($session_id <= 0 || $vigile_id <= 0) {
  http_response_code(400);
  echo "Parametri mancanti.";
  exit;
}

// 4) Carica dati
$sessioni = load_sessioni();
$session  = null;
foreach ($sessioni as $s) {
  if ((int)$s['id'] === $session_id) { $session = $s; break; }
}
if (!$session) {
  http_response_code(404);
  echo "Sessione non trovata.";
  exit;
}
$tenant_session = (string)($session['tenant_slug'] ?? '');

// Se è stato passato uno slug e non coincide, blocca
if ($slug !== '' && $tenant_session !== '' && strcasecmp($slug, $tenant_session) !== 0) {
  http_response_code(403);
  echo "Distaccamento non valido per la sessione.";
  exit;
}

$vigili = load_vigili();
$vigiliById = [];
foreach ($vigili as $v) { $vigiliById[(int)($v['id']??0)] = $v; }
if (!isset($vigiliById[$vigile_id])) {
  http_response_code(404);
  echo "Vigile non trovato.";
  exit;
}

// 5) Registra check-in (sempre) e, se mancasse, crea il record in addestramenti per il vigile/sessione
$checkins = load_checkins();
$addestr  = load_addestramenti();

$now = date('Y-m-d H:i:s');

// Check-in log (consentiamo multipli: poi il report mostra il primo/ultimo)
$checkins[] = [
  'session_id' => $session_id,
  'vigile_id'  => $vigile_id,
  'source'     => 'qr',
  'ts'         => $now,
  'by'         => 'qr',
];

// Se non esiste già un record in addestramenti per (session_id, vigile_id), crealo
$exists = false;
foreach ($addestr as $r) {
  if ((int)($r['session_id'] ?? 0) === $session_id && (int)($r['vigile_id'] ?? 0) === $vigile_id) {
    $exists = true; break;
  }
}
if (!$exists) {
  $addestr[] = [
    'session_id' => $session_id,
    'vigile_id'  => $vigile_id,
    'data'       => (string)($session['data'] ?? ''),
    'inizio'     => (string)($session['inizio'] ?? ''),
    'fine'       => (string)($session['fine'] ?? ''),
    'inizio_dt'  => (string)($session['inizio_dt'] ?? ''),
    'fine_dt'    => (string)($session['fine_dt'] ?? ''),
    'minuti'     => (int)($session['minuti'] ?? 0),
    'attivita'   => (string)($session['attivita'] ?? ''),
    'recupero'   => (int)($session['recupero'] ?? 0),
    'note'       => (string)($session['note'] ?? ''),
    'created_at' => $now,
  ];
}

save_checkins($checkins);
save_addestramenti($addestr);

// 6) Risposta semplice
?>
<!doctype html>
<html lang="it">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<title>Check-in registrato</title></head>
<body class="bg-light">
<div class="container py-5">
  <div class="alert alert-success">
    ✅ Check-in registrato alle <strong><?= htmlspecialchars($now) ?></strong>.<br>
    Sessione #<?= (int)$session_id ?> — Vigile ID <?= (int)$vigile_id ?>.
  </div>
  <p class="text-muted small mb-0">Puoi chiudere questa pagina.</p>
</div>
</body>
</html>