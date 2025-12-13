<?php
// report_mensile_pdf.php — PDF mensile per tutti i vigili (A4), un vigile = una pagina
require __DIR__.'/storage.php';
require __DIR__.'/utils.php';
require __DIR__.'/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

$meseStr = $_GET['mese'] ?? date('Y-m');           // YYYY-MM
$includiVuoti = isset($_GET['includi_vuoti']);      // opzionale
if (!preg_match('/^\d{4}-\d{2}$/', $meseStr)) { http_response_code(400); die('Parametro mese invalido'); }

$vigili = array_values(array_filter(load_json(VIGILI_JSON), fn($v)=> (int)($v['attivo'] ?? 1)===1));
usort($vigili, fn($a,$b)=>strcmp($a['cognome'].' '.$a['nome'], $b['cognome'].' '.$b['nome']));
$add = load_json(ADDESTR_JSON);

$prefix = substr($meseStr,0,7); // YYYY-MM

// Logo (base64 per evitare permessi path)
$logoPath = __DIR__.'/assets/logo.png';
$logoHtml = '';
if (is_file($logoPath)) {
  $b64 = base64_encode(file_get_contents($logoPath));
  $logoHtml = '<img src="data:image/png;base64,'.$b64.'" style="height:40px;">';
}

// Raggruppa per vigile -> elenco sessioni del mese
$perVigile = []; // id => [sessioni]
foreach ($add as $r) {
  $vid = (int)($r['vigile_id'] ?? 0);
  $d   = (string)($r['data'] ?? '');
  if (substr($d, 0, 7) !== $prefix) continue;
  $perVigile[$vid][] = $r;
}
// Ordina sessioni per giorno+ora
foreach ($perVigile as &$lista) {
  usort($lista, function($x,$y){
    $dx = (string)($x['data'] ?? '');
    $dy = (string)($y['data'] ?? '');
    if ($dx !== $dy) return strcmp($dx, $dy);
    $sx = $x['inizio_dt'] ?? (($x['data'] ?? '').'T'.substr((string)($x['inizio'] ?? '00:00'),0,5).':00');
    $sy = $y['inizio_dt'] ?? (($y['data'] ?? '').'T'.substr((string)($y['inizio'] ?? '00:00'),0,5).':00');
    return strcmp($sx, $sy);
  });
}
unset($lista);

// Helpers
$fmtIT = function(?string $ymd): string {
  if (!$ymd || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) return '';
  return substr($ymd,8,2).'/'.substr($ymd,5,2).'/'.substr($ymd,0,4);
};

ob_start();
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<style>
  @page { size: A4; margin: 18mm 15mm 25mm 15mm; } /* spazio extra in basso per firme */
  body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 11px; color: #111; }
  .header { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; }
  .title { font-size: 16px; font-weight: bold; }
  .sub { color:#555; }
  .hr { border-top: 1px solid #aaa; margin: 6px 0 10px; }
  table { width: 100%; border-collapse: collapse; }
  th, td { border: 1px solid #ccc; padding: 6px 8px; vertical-align: middle; }
  th { background: #f2f2f2; text-align: left; }
  .right { text-align: right; }
  .center { text-align: center; }
  .totale { margin-top: 8px; font-weight: bold; }
  .firma-wrap { position: fixed; bottom: 12mm; left: 15mm; right: 15mm; }
  .firme { display: flex; justify-content: space-between; gap: 16px; margin-top: 12px; }
  .firma { width: 48%; }
  .linea { border-bottom: 1px solid #444; height: 28px; }
  .firma-label { font-size: 10px; color:#444; margin-top: 3px; }
  .footer { position: fixed; bottom: 5mm; left: 0; right: 0; text-align: center; font-size: 9px; color: #555; }
  .page-break { page-break-after: always; }
</style>
</head>
<body>
<?php
$printedAny = false;
foreach ($vigili as $v) {
  $vid = (int)$v['id'];
  $lista = $perVigile[$vid] ?? [];
  if (!$includiVuoti && empty($lista)) continue; // salta pagine vuote

  $printedAny = true;
  $nomeFull = htmlspecialchars($v['cognome'].' '.$v['nome']);
  $gradoTxt = htmlspecialchars($v['grado'] ?? '');
  $totMin = 0; foreach ($lista as $r) $totMin += (int)($r['minuti'] ?? 0);
  ?>
  <div class="header">
    <div><?= $logoHtml ?></div>
    <div>
      <div class="title">Report mensile addestramento</div>
      <div class="sub">Mese: <strong><?= htmlspecialchars($meseStr) ?></strong> — Vigile: <strong><?= $nomeFull ?></strong>
        <?= $gradoTxt ? ' <span class="sub">['.$gradoTxt.']</span>' : '' ?>
      </div>
    </div>
  </div>
  <div class="hr"></div>

  <table>
    <thead>
      <tr>
        <th style="width:22%;">Giorno</th>
        <th style="width:20%;">Dalle</th>
        <th style="width:20%;">Alle</th>
        <th style="width:18%;" class="right">Totale</th>
        <th>Attività</th>
      </tr>
    </thead>
    <tbody>
    <?php if (empty($lista)): ?>
      <tr><td colspan="5" class="center" style="color:#666;">Nessun addestramento registrato.</td></tr>
    <?php else:
      foreach ($lista as $r):
        $giorno = $fmtIT((string)($r['data'] ?? ''));
        // orari: usa *_dt se presenti, altrimenti HH:MM legacy
        $iHH = $r['inizio'] ?? substr((string)($r['inizio_dt'] ?? ''),11,5);
        $fHH = $r['fine']   ?? substr((string)($r['fine_dt']   ?? ''),11,5);
        // Se fine_dt è in altro giorno, lo indico
        $fineExtra = '';
        if (!empty($r['inizio_dt']) && !empty($r['fine_dt'])) {
          $gStart = substr($r['inizio_dt'],0,10);
          $gEnd   = substr($r['fine_dt'],0,10);
          if ($gEnd !== $gStart) $fineExtra = ' → '.$fmtIT($gEnd);
        }
        $min = (int)($r['minuti'] ?? 0);
      ?>
      <tr>
        <td><?= htmlspecialchars($giorno) ?></td>
        <td><?= htmlspecialchars($iHH) ?></td>
        <td><?= htmlspecialchars($fHH.$fineExtra) ?></td>
        <td class="right"><?= htmlspecialchars(h_ore_min($min)) ?></td>
        <td><?= htmlspecialchars($r['attivita'] ?? '') ?></td>
      </tr>
      <?php endforeach;
    endif; ?>
    </tbody>
  </table>

  <div class="totale">Totale mese: <?= htmlspecialchars(h_ore_min($totMin)) ?> (<?= (int)$totMin ?> min)</div>

  <div class="firma-wrap">
    <div class="firme">
      <div class="firma">
        <div class="linea"></div>
        <div class="firma-label">Firma del Vigile</div>
      </div>
      <div class="firma">
        <div class="linea"></div>
        <div class="firma-label">Firma del Capo Distaccamento</div>
      </div>
    </div>
  </div>

  <div class="footer">Report generato automaticamente — <?= date('d/m/Y H:i') ?></div>

  <div class="page-break"></div>
  <?php
}
if (!$printedAny) {
  echo '<p style="color:#666">Nessun vigile con addestramenti nel mese selezionato. Aggiungi <code>&includi_vuoti=1</code> per stampare anche pagine vuote.</p>';
}
?>
</body>
</html>
<?php
$html = ob_get_clean();

$options = new Options();
$options->set('isRemoteEnabled', true);
$options->set('isHtml5ParserEnabled', true);

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$dompdf->stream('report_mensile_'.$meseStr.'.pdf', ['Attachment' => true]);
