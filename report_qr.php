<?php
// report_qr.php — Report orari check-in (QR e manuali) per mese o per sessione
ob_start();
require __DIR__.'/auth.php';
require __DIR__.'/tenant_bootstrap.php';
require_tenant_user();
require __DIR__.'/storage.php';
require_perm('view:index'); // lettura report

require_mfa();
$vigili = load_vigili(); $vigById=[];
foreach ($vigili as $v) { $vigById[(int)$v['id']] = $v; }

$sessioni = load_sessioni(); $sessById=[];
foreach ($sessioni as $s) { $sessById[(int)$s['id']] = $s; }

$checkins = load_checkins();

// Filtri
$mese = isset($_GET['mese']) && preg_match('/^\d{4}-\d{2}$/', $_GET['mese']) ? $_GET['mese'] : '';
$sid  = (int)($_GET['sid'] ?? 0);

// Filtra checkins
$rows = [];
foreach ($checkins as $r) {
  $sidRow = (int)($r['session_id'] ?? 0);
  $vid    = (int)($r['vigile_id'] ?? 0);
  $ts     = (string)($r['ts'] ?? '');
  if ($sid && $sidRow !== $sid) continue;
  if ($mese !== '') {
    if (!isset($sessById[$sidRow])) continue;
    $d = (string)($sessById[$sidRow]['data'] ?? '');
    if (substr($d,0,7) !== $mese) continue;
  }
  $rows[] = $r;
}

// Raggruppa per sessione
$bySession = [];
foreach ($rows as $r) {
  $sidRow = (int)$r['session_id'];
  $bySession[$sidRow][] = $r;
}

// HTML
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Report check-in QR</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4">
  <h1 class="mb-3">Report check-in QR</h1>

  <form class="row g-2 mb-3" method="get">
    <div class="col-auto">
      <label class="form-label">Mese</label>
      <input type="month" class="form-control" name="mese" value="<?= htmlspecialchars($mese) ?>">
    </div>
    <div class="col-auto">
      <label class="form-label">Oppure Sessione #</label>
      <input type="number" class="form-control" name="sid" value="<?= $sid>0 ? (int)$sid : '' ?>">
    </div>
    <div class="col-auto align-self-end">
      <button class="btn btn-primary">Filtra</button>
      <a class="btn btn-outline-secondary" href="report_qr.php">Azzera</a>
    </div>
  </form>

  <?php if (empty($bySession)): ?>
    <div class="alert alert-info">Nessun check-in trovato con i filtri selezionati.</div>
  <?php endif; ?>

  <?php foreach ($bySession as $sidRow => $list): 
    $sess = $sessById[$sidRow] ?? [];
    $tit  = 'Sessione #'.$sidRow.' — '.htmlspecialchars(($sess['data']??'').' '.$sess['inizio'].'–'.$sess['fine'].' — '.$sess['attivita'] ?? '');
// calcola per vigile primo/ultimo check-in
    $perVig = [];
    foreach ($list as $r) {
      $vid = (int)$r['vigile_id']; $ts = (string)$r['ts'];
      if (!isset($perVig[$vid])) $perVig[$vid] = ['first'=>null,'last'=>null,'all'=>[]];
      $perVig[$vid]['all'][] = $ts;
      if ($perVig[$vid]['first']===null || $ts < $perVig[$vid]['first']) $perVig[$vid]['first']=$ts;
      if ($perVig[$vid]['last']===null  || $ts > $perVig[$vid]['last'])  $perVig[$vid]['last']=$ts;
    }
  ?>
    <div class="card mb-3 shadow-sm">
      <div class="card-header">
        <strong><?= $tit ?></strong>
      </div>
      <div class="card-body">
        <div class="table-responsive">
          <table class="table table-sm align-middle">
            <thead>
              <tr>
                <th>Vigile</th>
                <th>Primo check-in</th>
                <th>Ultimo check-in</th>
                <th>Dettagli</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($perVig as $vid => $info):
                $v = $vigById[$vid] ?? [];
                $nome = trim(($v['cognome'] ?? '?').' '.($v['nome'] ?? ''));
              ?>
              <tr>
                <td><?= htmlspecialchars($nome) ?> (ID <?= (int)$vid ?>)</td>
                <td><?= htmlspecialchars($info['first'] ?? '—') ?></td>
                <td><?= htmlspecialchars($info['last']  ?? '—') ?></td>
                <td class="small">
                  <?php foreach ($info['all'] as $i=>$ts): ?>
                    <span class="badge text-bg-secondary me-1"><?= htmlspecialchars($ts) ?></span>
                  <?php endforeach; ?>
                </td>
              </tr>
              <?php endforeach; ?>
              <?php if (empty($perVig)): ?>
                <tr><td colspan="4" class="text-muted">Nessun check-in registrato per questa sessione.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        <a class="btn btn-sm btn-outline-primary" href="addestramenti.php?sid=<?= (int)$sidRow ?>">Apri dettagli</a>
      </div>
    </div>
  <?php endforeach; ?>
</div>
</body>
</html>