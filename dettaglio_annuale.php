<?php
// dettaglio_annuale.php — dettaglio annuale per singolo vigile (regola 5h/mese cumulabili)

ini_set('display_errors', 1);
error_reporting(E_ALL);

// --- Bootstrap / auth
ob_start();
require __DIR__.'/auth.php';
require __DIR__.'/tenant_bootstrap.php'; // definisce DATA_DIR/tenant (se previsto)
if (function_exists('require_tenant_user')) { require_tenant_user(); }
if (function_exists('require_mfa')) { require_mfa(); }
@include __DIR__.'/storage.php';
require __DIR__.'/utils.php';

// ---- helper path + json fallback (se storage.php non fornisce load_json)
if (!defined('DATA_DIR')) {
  // fallback: data/<slug> o data/default
  $tenant = $_SESSION['tenant_slug'] ?? $_SESSION['CASERMA_SLUG'] ?? 'default';
  define('DATA_DIR', __DIR__.'/data/'.$tenant);
}
function _path($rel){ $rel=ltrim($rel,'/'); return rtrim(DATA_DIR,'/').'/'.$rel; }
function _json_load_relaxed($abs){
  if (!is_file($abs)) return [];
  $s=@file_get_contents($abs); if($s===false) return [];
  $s=preg_replace('/^\xEF\xBB\xBF/','',$s);
  $s=preg_replace('/,\s*([\}\]])/m','$1',$s);
  $j=json_decode($s,true);
  return is_array($j)?$j:[];
}
if (!function_exists('load_json')) {
  function load_json($abs){ return _json_load_relaxed($abs); }
}
if (!function_exists('h_ore_min')) {
  function h_ore_min($min){ $min=(int)$min; $h=intdiv($min,60); $m=$min%60; return sprintf('%d:%02d',$h,$m); }
}

// ---- parametri
$vigileId = isset($_GET['vigile']) ? (int)$_GET['vigile'] : 0;
$anno     = isset($_GET['anno'])   ? (int)$_GET['anno']   : (int)date('Y');
$attRichiesta = isset($_GET['attivita']) ? trim((string)$_GET['attivita']) : '';
if ($vigileId <= 0 || $anno < 2000 || $anno > 2100) {
  http_response_code(400); die('Parametri non validi');
}

// ---- sorgenti dati: usa costanti se ci sono, altrimenti data/<slug>/...
$VIGILI_JSON  = defined('VIGILI_JSON')  ? VIGILI_JSON  : _path('vigili.json');
$ADDESTR_JSON = defined('ADDESTR_JSON') ? ADDESTR_JSON : _path('addestramenti.json');

// ---- carica dati base
$vigili = sanitize_vigili_list(load_json($VIGILI_JSON));
$map = [];
foreach ($vigili as $v) { $map[(int)($v['id'] ?? 0)] = $v; }
if (!isset($map[$vigileId])) { http_response_code(404); die('Vigile non trovato'); }
$vigile = $map[$vigileId];

$add = load_json($ADDESTR_JSON);

// ---- filtra per vigile+anno (data d’inizio 'data' YYYY-MM-DD)
$sessioni = array_values(array_filter($add, function($a) use ($vigileId, $anno){
  if ((int)($a['vigile_id'] ?? 0) !== $vigileId) return false;
  $d = (string)($a['data'] ?? '');
  return substr($d, 0, 4) === (string)$anno;
}));

// ---- elenco attività (per filtro dropdown, prima del filtro)
$attivitaDisponibili = [];
foreach ($sessioni as $s) {
  $att = trim((string)($s['attivita'] ?? ''));
  if ($att !== '') $attivitaDisponibili[$att] = true;
}
$attivitaDisponibili = array_keys($attivitaDisponibili);
sort($attivitaDisponibili);

// ---- applica filtro attività
if ($attRichiesta !== '') {
  $sessioni = array_values(array_filter($sessioni, function($s) use ($attRichiesta) {
    return trim((string)($s['attivita'] ?? '')) === $attRichiesta;
  }));
}

// ---- ordina per data e ora inizio
usort($sessioni, function($x,$y){
  $dx = (string)($x['data'] ?? ''); $dy = (string)($y['data'] ?? '');
  if ($dx !== $dy) return strcmp($dx, $dy);
  $sx = $x['inizio_dt'] ?? (($x['data'] ?? '').'T'.substr((string)($x['inizio'] ?? '00:00'),0,5).':00');
  $sy = $y['inizio_dt'] ?? (($y['data'] ?? '').'T'.substr((string)($y['inizio'] ?? '00:00'),0,5).':00');
  return strcmp($sx, $sy);
});

// ---- raggruppa per mese->giorno + totali
$perMese = [];       // ['YYYY-MM' => ['YYYY-MM-DD' => [sessioni...]]]
$totAnno = 0;
$primaAttivitaAnn = null; // 'YYYY-MM-DD' della prima attività nell'anno
foreach ($sessioni as $s) {
  $giorno = (string)($s['data'] ?? '');
  $mese   = substr($giorno, 0, 7);
  $perMese[$mese][$giorno][] = $s;

  $min = (int)($s['minuti'] ?? 0);
  $totAnno += $min;

  if ($giorno && ($primaAttivitaAnn === null || $giorno < $primaAttivitaAnn)) {
    $primaAttivitaAnn = $giorno;
  }
}

// ---- regola 5h/mese cumulabili
$QUOTA_MENSILE_MIN = 5 * 60; // minuti

// mese ingresso: anagrafico -> altrimenti prima attività nell'anno -> altrimenti gennaio
$ing = $vigile['data_ingresso'] ?? $vigile['ingresso'] ?? $primaAttivitaAnn;
$ingMonth = $ing ? substr($ing, 0, 7) : sprintf('%04d-01', $anno);

// elenco mesi anno
$mesiAnno = [];
for ($m = 1; $m <= 12; $m++) $mesiAnno[] = sprintf('%04d-%02d', $anno, $m);

// mesi rilevanti quota
$startMonth = max($ingMonth, sprintf('%04d-01', $anno));
$mesiRilevanti = array_values(array_filter($mesiAnno, fn($ym) => $ym >= $startMonth));
$nMesiQuota = count($mesiRilevanti);

// quota attesa e stato
$expectedMin = $nMesiQuota * $QUOTA_MENSILE_MIN;
$okOperativo = ($nMesiQuota > 0) && ($totAnno >= $expectedMin);

// =================== EXPORT CSV (PRIMA DI QUALSIASI OUTPUT!) ===================
if (isset($_GET['export']) && $_GET['export']==='csv') {
  // buffer clean per evitare “headers already sent”
  if (ob_get_length()) { ob_clean(); }

  header('Content-Type: text/csv; charset=utf-8');
  $suffix = ($attRichiesta!=='' ? '_att_'.preg_replace('/\s+/', '_', $attRichiesta) : '');
  $fname = 'dettaglio_annuale_'.$vigileId.'_'.$anno.$suffix.'.csv';
  header('Content-Disposition: attachment; filename="'.$fname.'"');
  $out = fopen('php://output', 'w');

  // testata con riepilogo quota
  fputcsv($out, ['Anno', 'Vigile', 'Totale(min)', 'Totale(h:mm)', 'Mesi rilevanti', 'Quota(min)', 'Quota(h:mm)']);
  fputcsv($out, [
    $anno,
    trim(($vigile['cognome'] ?? '').' '.($vigile['nome'] ?? '')),
    $totAnno,
    h_ore_min($totAnno),
    $nMesiQuota,
    $expectedMin,
    h_ore_min($expectedMin),
  ]);
  fputcsv($out, []); // separatore

  // dettaglio sessioni
  fputcsv($out, ['Mese','Giorno','Inizio','Fine','Minuti','Durata(h:mm)','Attività','Note']);
  ksort($perMese);
  foreach ($perMese as $mese => $perGiorno) {
    ksort($perGiorno);
    foreach ($perGiorno as $giorno => $lista) {
      foreach ($lista as $s) {
        $inizio = $s['inizio'] ?? substr((string)($s['inizio_dt'] ?? ''), 11, 5);
        $fine   = $s['fine']   ?? substr((string)($s['fine_dt']   ?? ''), 11, 5);
        $min    = (int)($s['minuti'] ?? 0);
        fputcsv($out, [$mese, $giorno, $inizio, $fine, $min, h_ore_min($min), ($s['attivita'] ?? ''), ($s['note'] ?? '')]);
      }
    }
  }
  fclose($out);
  exit;
}
// =================== /EXPORT CSV ===================

include __DIR__.'/_menu.php'; // OK da qui in poi possiamo stampare HTML

?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Dettaglio annuale — <?php echo htmlspecialchars(($vigile['cognome'] ?? '').' '.($vigile['nome'] ?? '')); ?> (<?php echo (int)$anno; ?>)</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4">

  <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
    <h1 class="mb-0">
      Dettaglio annuale — <?php echo htmlspecialchars(($vigile['cognome'] ?? '').' '.($vigile['nome'] ?? '')); ?>
      <?php if (!empty($vigile['grado'])): ?>
        <small class="text-muted">[<?php echo htmlspecialchars($vigile['grado']); ?>]</small>
      <?php endif; ?>
    </h1>
    <div class="text-muted">Anno: <strong><?php echo (int)$anno; ?></strong></div>
  </div>

  <div class="mt-2 d-flex gap-2">
    <a class="btn btn-outline-secondary btn-sm" href="riepilogo.php?tipo=annuale&anno=<?php echo (int)$anno; ?>">← Torna al riepilogo annuale</a>

    <form action="dettaglio_annuale_tcpdf.php" method="get" target="_blank" class="d-inline">
      <input type="hidden" name="vigile" value="<?php echo (int)$vigileId; ?>">
      <input type="hidden" name="anno" value="<?php echo (int)$anno; ?>">
      <?php if ($attRichiesta !== ''): ?>
        <input type="hidden" name="attivita" value="<?php echo htmlspecialchars($attRichiesta, ENT_QUOTES); ?>">
      <?php endif; ?>
      <button type="submit" class="btn btn-danger btn-sm fw-semibold">PDF dettaglio</button>
    </form>

    <a class="btn btn-outline-dark btn-sm" href="?vigile=<?php echo (int)$vigileId; ?>&anno=<?php echo (int)$anno; ?>&export=csv<?php echo ($attRichiesta!=='' ? '&attivita='.urlencode($attRichiesta) : ''); ?>">Esporta CSV</a>
  </div>

  <!-- filtro attività -->
  <form class="row g-2 align-items-end mt-3" method="get">
    <input type="hidden" name="vigile" value="<?php echo (int)$vigileId; ?>">
    <input type="hidden" name="anno" value="<?php echo (int)$anno; ?>">
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
        <a class="btn btn-outline-secondary" href="?vigile=<?php echo (int)$vigileId; ?>&anno=<?php echo (int)$anno; ?>">Pulisci filtro</a>
      <?php endif; ?>
    </div>
  </form>

  <?php
    // pannello stato 5h/mese
    $badge = $okOperativo ? 'success' : 'danger';
  ?>
  <div class="alert alert-<?php echo $badge; ?> mt-3 d-flex justify-content-between align-items-center">
    <div>
      Totale anno: <strong><?php echo h_ore_min($totAnno); ?></strong>
      (<?php echo (int)$totAnno; ?> min)
      &nbsp;—&nbsp;
      Quota attesa: <strong><?php echo h_ore_min($expectedMin); ?></strong>
      <?php if ($nMesiQuota === 0): ?>
        <span class="text-muted">(nessun mese rilevante nell'anno)</span>
      <?php else: ?>
        <span class="text-muted">(5h × <?php echo (int)$nMesiQuota; ?> mesi da <?php echo htmlspecialchars($startMonth); ?> a <?php echo (int)$anno; ?>-12)</span>
      <?php endif; ?>
    </div>
    <div>
      <?php if ($okOperativo): ?>
        <span class="badge text-bg-success">OPERATIVO</span>
      <?php else: ?>
        <span class="badge text-bg-danger">NON OPERATIVO</span>
      <?php endif; ?>
    </div>
  </div>

  <?php if (empty($sessioni)): ?>
    <div class="alert alert-warning">Nessun addestramento effettuato nell'anno selezionato.</div>
  <?php else: ?>
    <?php
      // stampa mensile
      ksort($perMese);
      foreach ($perMese as $mese => $perGiorno):
        $totMese = 0;
        foreach ($perGiorno as $g => $lista) foreach ($lista as $s) $totMese += (int)($s['minuti'] ?? 0);
    ?>
    <div class="card mb-3 shadow-sm">
      <div class="card-header d-flex justify-content-between align-items-center">
        <div><strong><?php echo htmlspecialchars($mese); ?></strong></div>
        <span class="badge text-bg-primary">Totale mese: <?php echo h_ore_min($totMese); ?></span>
      </div>
      <div class="card-body p-0">
        <?php ksort($perGiorno); ?>
        <?php foreach ($perGiorno as $giorno => $lista):
          $minDay = 0; foreach ($lista as $s) $minDay += (int)($s['minuti'] ?? 0);
        ?>
        <div class="table-responsive">
          <table class="table table-sm mb-0 align-middle">
            <thead class="table-light">
              <tr>
                <th class="w-25">Giorno: <?php echo htmlspecialchars($giorno); ?></th>
                <th>Inizio</th>
                <th>Fine</th>
                <th>Durata</th>
                <th>Attività</th>
                <th>Note</th>
                <th class="text-end"><span class="badge text-bg-secondary">Totale giorno: <?php echo h_ore_min($minDay); ?></span></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($lista as $s):
                $inizio = $s['inizio'] ?? substr((string)($s['inizio_dt'] ?? ''), 11, 5);
                $fine   = $s['fine']   ?? substr((string)($s['fine_dt']   ?? ''), 11, 5);
                $fineExtra = '';
                if (!empty($s['inizio_dt']) && !empty($s['fine_dt'])) {
                  $gStart = substr($s['inizio_dt'], 0, 10);
                  $gEnd   = substr($s['fine_dt'],   0, 10);
                  if ($gEnd !== $gStart) $fineExtra = ' <span class="badge text-bg-secondary">→ '.$gEnd.'</span>';
                }
                $min = (int)($s['minuti'] ?? 0);
                $dur = h_ore_min($min);
              ?>
              <tr>
                <td></td>
                <td><?php echo htmlspecialchars($inizio); ?></td>
                <td><?php echo htmlspecialchars($fine); ?><?php echo $fineExtra; ?></td>
                <td><?php echo $dur; ?></td>
                <td><?php echo htmlspecialchars($s['attivita'] ?? ''); ?></td>
                <td><?php echo htmlspecialchars($s['note'] ?? ''); ?></td>
                <td></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>

</div>
</body>
</html>
