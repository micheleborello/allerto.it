<?php
ob_start();
require __DIR__.'/auth.php';
require __DIR__.'/tenant_bootstrap.php'; // per leggere lo slug attivo se serve
require_tenant_user(); // blocca chi non è loggato per questa caserma
// dettaglio.php — dettaglio mensile per singolo vigile

require __DIR__.'/storage.php';
require __DIR__.'/utils.php';
include __DIR__.'/_menu.php'; 




$vigileId = isset($_GET['vigile']) ? (int)$_GET['vigile'] : 0;
$meseStr  = $_GET['mese'] ?? date('Y-m'); // YYYY-MM

if ($vigileId <= 0 || !preg_match('/^\d{4}-\d{2}$/', $meseStr)) {
  http_response_code(400);
  die('Parametri non validi');
}

$vigili = load_json(VIGILI_JSON);
$map = [];
foreach ($vigili as $v) $map[(int)$v['id']] = $v;
if (!isset($map[$vigileId])) { http_response_code(404); die('Vigile non trovato'); }
$vigile = $map[$vigileId];

$add = load_json(ADDESTR_JSON);

// filtra per vigile e mese (usiamo la data di INIZIO: campo 'data' in formato YYYY-MM-DD)
$prefix = substr($meseStr, 0, 7);
$sessioni = array_values(array_filter($add, function($a) use ($vigileId, $prefix) {
  if ((int)($a['vigile_id'] ?? 0) !== $vigileId) return false;
  $d = (string)($a['data'] ?? '');
  return substr($d, 0, 7) === $prefix;
}));

// ordina per data, poi per orario di inizio (considera sia *_dt che HH:MM)
usort($sessioni, function($x,$y){
  $dx = $x['data'] ?? '';
  $dy = $y['data'] ?? '';
  if ($dx !== $dy) return strcmp($dx, $dy);
  $sx = $x['inizio_dt'] ?? (($x['data'] ?? '').'T'.substr((string)($x['inizio'] ?? '00:00'),0,5).':00');
  $sy = $y['inizio_dt'] ?? (($y['data'] ?? '').'T'.substr((string)($y['inizio'] ?? '00:00'),0,5).':00');
  return strcmp($sx, $sy);
});

// --- FILTRO ATTIVITÀ ---
$attRichiesta = isset($_GET['attivita']) ? trim((string)$_GET['attivita']) : '';

// Attività disponibili (per il menu) PRIMA del filtro
$attivitaDisponibili = [];
foreach ($sessioni as $s) {
  $att = trim((string)($s['attivita'] ?? ''));
  if ($att !== '') $attivitaDisponibili[$att] = true;
}
$attivitaDisponibili = array_keys($attivitaDisponibili);
sort($attivitaDisponibili);

// Applica filtro se richiesto
if ($attRichiesta !== '') {
  $sessioni = array_values(array_filter($sessioni, function($s) use ($attRichiesta) {
    return trim((string)($s['attivita'] ?? '')) === $attRichiesta;
  }));
}

// raggruppa per giorno (dopo filtro)
$perGiorno = [];
$totalMin = 0;
foreach ($sessioni as $s) {
  $giorno = (string)($s['data'] ?? '');
  $perGiorno[$giorno][] = $s;
  $totalMin += (int)($s['minuti'] ?? 0);
}
ksort($perGiorno); // ordina i giorni
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Dettaglio <?php echo htmlspecialchars($vigile['cognome'].' '.$vigile['nome']); ?> — <?php echo htmlspecialchars($meseStr); ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4">
  <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
    <h1 class="mb-0">
      Dettaglio mensile — <?php echo htmlspecialchars($vigile['cognome'].' '.$vigile['nome']); ?>
      <?php if (!empty($vigile['grado'])): ?>
        <small class="text-muted">[<?php echo htmlspecialchars($vigile['grado']); ?>]</small>
      <?php endif; ?>
    </h1>
    <div class="text-muted">Mese: <strong><?php echo htmlspecialchars($meseStr); ?></strong></div>
  </div>

  <div class="mt-2">
    <a class="btn btn-outline-secondary btn-sm" href="riepilogo.php?tipo=mensile&mese=<?php echo urlencode($meseStr); ?>">← Torna al riepilogo mensile</a>
    <a class="btn btn-outline-dark btn-sm" href="?vigile=<?php echo $vigileId; ?>&mese=<?php echo urlencode($meseStr); ?>&export=csv">Esporta CSV</a>

 <form class="row g-2 align-items-end mt-3" method="get">
  <input type="hidden" name="vigile" value="<?php echo (int)$vigileId; ?>">
  <input type="hidden" name="mese" value="<?php echo htmlspecialchars($meseStr); ?>">
  <div class="col-md-6">
    <label class="form-label">Filtra per attività svolta</label>
    <select name="attivita" class="form-select">
      <option value="">— Tutte —</option>
      <?php foreach ($attivitaDisponibili as $opt): ?>
        <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo ($opt===$attRichiesta?'selected':''); ?>>
          <?php echo htmlspecialchars($opt); ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-6 d-flex gap-2">
    <button class="btn btn-primary">Applica filtro</button>
    <?php if ($attRichiesta !== ''): ?>
      <a class="btn btn-outline-secondary" href="?vigile=<?php echo (int)$vigileId; ?>&mese=<?php echo urlencode($meseStr); ?>">Pulisci filtro</a>
    <?php endif; ?>
  </div>
</form>
</div>


  <div class="alert alert-info mt-3">
    Totale mese: <strong><?php echo h_ore_min($totalMin); ?></strong> (<?php echo (int)$totalMin; ?> min)
  </div>

  <?php if (empty($sessioni)): ?>
    <div class="alert alert-warning">Nessun addestramento effettuato per questo mese.</div>
  <?php else: ?>

    <?php foreach ($perGiorno as $giorno => $lista): 
      $minDay = 0; foreach ($lista as $s) $minDay += (int)($s['minuti'] ?? 0);
    ?>
    <div class="card mb-3 shadow-sm">
      <div class="card-header d-flex justify-content-between align-items-center">
        <div>
          <strong><?php echo htmlspecialchars($giorno); ?></strong>
        </div>
        <span class="badge text-bg-primary">Totale giorno: <?php echo h_ore_min($minDay); ?></span>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-sm mb-0 align-middle">
            <thead class="table-light">
              <tr>
                <th>Inizio</th>
                <th>Fine</th>
                <th>Durata</th>
                <th>Attività</th>
                <th>Note</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($lista as $s):
                // mostra HH:MM, ma se esistono *_dt e il giorno cambia, indica il giorno di fine
                $inizio = $s['inizio'] ?? substr((string)($s['inizio_dt'] ?? ''), 11, 5);
                $fine   = $s['fine']   ?? substr((string)($s['fine_dt']   ?? ''), 11, 5);
                $fineExtra = '';
                if (!empty($s['inizio_dt']) && !empty($s['fine_dt'])) {
                  $gStart = substr($s['inizio_dt'], 0, 10);
                  $gEnd   = substr($s['fine_dt'],   0, 10);
                  if ($gEnd !== $gStart) $fineExtra = ' <span class="badge text-bg-secondary">→ '.$gEnd.'</span>';
                }
                $dur = h_ore_min((int)($s['minuti'] ?? 0));
              ?>
              <tr>
                <td><?php echo htmlspecialchars($inizio); ?></td>
                <td><?php echo htmlspecialchars($fine); ?><?php echo $fineExtra; ?></td>
                <td><?php echo $dur; ?></td>
                <td><?php echo htmlspecialchars($s['attivita'] ?? ''); ?></td>
                <td><?php echo htmlspecialchars($s['note'] ?? ''); ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <?php endforeach; ?>

  <?php endif; ?>

</div>
</body>
</html>
<?php
// Export CSV
if (isset($_GET['export']) && $_GET['export']==='csv') {
  header('Content-Type: text/csv; charset=utf-8');
  $fname = 'dettaglio_'.$vigileId.'_'.$meseStr.($attRichiesta!=='' ? '_att_'.preg_replace('/\s+/', '_', $attRichiesta) : '').'.csv';
header('Content-Disposition: attachment; filename="'.$fname.'"');
  $out = fopen('php://output', 'w');
  fputcsv($out, ['Giorno','Inizio','Fine','Minuti','Durata(h:mm)','Attività','Note']);
  foreach ($sessioni as $s) {
    $giorno = (string)($s['data'] ?? '');
    $inizio = $s['inizio'] ?? substr((string)($s['inizio_dt'] ?? ''), 11, 5);
    $fine   = $s['fine']   ?? substr((string)($s['fine_dt']   ?? ''), 11, 5);
    $min    = (int)($s['minuti'] ?? 0);
    fputcsv($out, [$giorno, $inizio, $fine, $min, h_ore_min($min), ($s['attivita'] ?? ''), ($s['note'] ?? '')]);
  }
  fclose($out);
  exit;
}