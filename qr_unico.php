<?php
// qr_unico.php — check-in con PIN personale (QR unico per sessione)

require __DIR__.'/auth.php';              // NON richiede login: il QR è pubblico
require __DIR__.'/tenant_bootstrap.php'; // per DATA_DIR del distaccamento attivo
require __DIR__.'/storage.php';

// Parametri
$sid = (int)($_GET['s'] ?? 0);
if ($sid <= 0) { http_response_code(400); exit('Sessione non valida.'); }

// carica dati
$sessioni   = load_sessioni();
$sessione   = null;
foreach ($sessioni as $s) { if ((int)($s['id']??0) === $sid) { $sessione = $s; break; } }
if (!$sessione) { http_response_code(404); exit('Sessione inesistente.'); }

$vigili   = load_vigili();
usort($vigili, fn($a,$b)=>strcmp(($a['cognome']??'').' '.($a['nome']??''), ($b['cognome']??'').' '.($b['nome']??'')));
$pins     = load_pins();

$err = ''; $ok = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $vid = (int)($_POST['vigile_id'] ?? 0);
  $pin = (string)($_POST['pin'] ?? '');

  // valida vigile attivo
  $v = null;
  foreach ($vigili as $vv) { if ((int)($vv['id']??0) === $vid) { $v = $vv; break; } }
  if (!$v || (int)($v['attivo'] ?? 1) !== 1) { $err = 'Vigile non valido o non attivo.'; }
  else {
    // verifica PIN
    $hash = (string)($pins[(string)$vid] ?? '');
    if ($hash === '' || !password_verify($pin, $hash)) {
      $err = 'PIN errato.';
    } else {
      // ok → registra
      $add = load_addestramenti();
      $exists = false;
      foreach ($add as $r) {
        if ((int)($r['session_id']??0) === $sid && (int)($r['vigile_id']??0) === $vid) { $exists = true; break; }
      }
      if (!$exists) {
        $add[] = [
          'session_id' => $sid,
          'vigile_id'  => $vid,
          'data'       => (string)($sessione['data'] ?? ''),
          'inizio'     => (string)($sessione['inizio'] ?? ''),
          'fine'       => (string)($sessione['fine'] ?? ''),
          'inizio_dt'  => (string)($sessione['inizio_dt'] ?? ''),
          'fine_dt'    => (string)($sessione['fine_dt'] ?? ''),
          'minuti'     => (int)($sessione['minuti'] ?? 0),
          'attivita'   => (string)($sessione['attivita'] ?? ''),
          'recupero'   => (int)($sessione['recupero'] ?? 0),
          'note'       => (string)($sessione['note'] ?? ''),
          'created_at' => date('Y-m-d H:i:s'),
          'source'     => 'qr',
        ];
        save_addestramenti($add);
      }

      // logga check-in con timestamp
      $ck = load_checkins();
      $ck[] = [
        'session_id' => $sid,
        'vigile_id'  => $vid,
        'ts'         => date('Y-m-d H:i:s'),
        'source'     => 'qr',
        'ip'         => $_SERVER['REMOTE_ADDR'] ?? '',
        'ua'         => $_SERVER['HTTP_USER_AGENT'] ?? '',
      ];
      save_checkins($ck);

      $ok = 'Presenza registrata — grazie!';
    }
  }
}
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Check-in Addestramento</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4" style="max-width:680px">
  <div class="card shadow-sm">
    <div class="card-body">
      <h1 class="h4 mb-2">Check-in Addestramento</h1>
      <div class="text-muted mb-3">
        <div><strong>Sessione #<?= (int)$sid ?></strong></div>
        <div><?= htmlspecialchars(($sessione['data']??'').' '.$sessione['inizio'].'–'.$sessione['fine']) ?></div>
        <div><?= htmlspecialchars($sessione['attivita'] ?? '') ?></div>
      </div>

      <?php if ($ok): ?>
        <div class="alert alert-success"><?= htmlspecialchars($ok) ?></div>
      <?php elseif ($err): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($err) ?></div>
      <?php endif; ?>

      <form method="post" class="vstack gap-3">
        <div>
          <label class="form-label">Chi sei?</label>
          <select class="form-select" name="vigile_id" required>
            <option value="" disabled selected>— seleziona il tuo nome —</option>
            <?php foreach ($vigili as $v):
              if ((int)($v['attivo'] ?? 1) !== 1) continue;
              $id=(int)$v['id'];
              $lbl=trim(($v['cognome']??'').' '.($v['nome']??''));
            ?>
              <option value="<?=$id?>"><?= htmlspecialchars($lbl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="form-label">PIN</label>
          <input type="password" class="form-control" name="pin" inputmode="numeric" autocomplete="one-time-code" minlength="4" maxlength="12" required>
          <div class="form-text">Il tuo PIN personale. Se non lo conosci, chiedi al Capo.</div>
        </div>
        <div class="d-grid"><button class="btn btn-primary">Conferma presenza</button></div>
      </form>

      <hr class="my-3">
      <div class="text-muted small">Per motivi di verifica salviamo anche data/ora, IP e dispositivo del check-in.</div>
    </div>
  </div>
</div>
</body>
</html>