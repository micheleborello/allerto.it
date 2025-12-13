<?php
// dettaglio_annuale_tcpdf.php — PDF dettaglio annuale per singolo vigile

ob_start();

require_once __DIR__.'/auth.php';
require_once __DIR__.'/tenant_bootstrap.php';
require_tenant_user();

require_mfa();

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__.'/storage.php';
require_once __DIR__.'/utils.php';
require_once __DIR__.'/lib/tcpdf/tcpdf.php';

/* -------------------- INPUT -------------------- */
$vigileId = isset($_GET['vigile']) ? (int)$_GET['vigile'] : 0;
$anno     = isset($_GET['anno'])   ? (int)$_GET['anno']   : (int)date('Y');
$attRichiesta = trim((string)($_GET['attivita'] ?? ''));

if ($vigileId <= 0 || $anno < 2000 || $anno > 2100) {
  http_response_code(400); die('Parametri non validi');
}

/* -------------------- TENANT E PERCORSI -------------------- */
$slug = function_exists('tenant_active_slug')
  ? (string)tenant_active_slug()
  : ((string)($_SESSION['tenant_slug'] ?? 'default'));

$dataDir = __DIR__ . '/data/' . $slug;
$vigiliPath = $dataDir . '/vigili.json';
$addestrPath = $dataDir . '/addestramenti.json';

/* --- helper JSON fallback (solo se servono) --- */
if (!function_exists('_read_json_safe')) {
  function _read_json_safe(string $abs): array {
    if (!is_file($abs)) return [];
    $s = @file_get_contents($abs);
    if ($s === false) return [];
    // rimuovi BOM e virgole finali tolleranti
    $s = preg_replace('/^\xEF\xBB\xBF/', '', $s);
    $s = preg_replace('/,\s*([\}\]])/m', '$1', $s);
    $j = json_decode($s, true);
    return is_array($j) ? $j : [];
  }
}

/* -------------------- NOME CASERMA (robusto) -------------------- */
$casermaName = $slug;
$caserme = [];
if (function_exists('load_caserme')) {
  try { $tmp = load_caserme(); if (is_array($tmp)) $caserme = $tmp; } catch (\Throwable $e) {}
}
if (empty($caserme)) {
  $try = [
    $dataDir.'/caserme.json',
    __DIR__.'/data/caserme.json',
  ];
  foreach ($try as $p) {
    if (is_file($p)) {
      $arr = _read_json_safe($p);
      if ($arr) { $caserme = $arr; break; }
    }
  }
}
if ($caserme) {
  foreach ($caserme as $c) {
    if (($c['slug'] ?? '') === $slug) {
      $casermaName = (string)($c['name'] ?? $c['label'] ?? $slug);
      break;
    }
  }
}

/* -------------------- DATI VIGILI E ADDESTRAMENTI -------------------- */
if (function_exists('get_vigili')) {
  $vigili = get_vigili($slug) ?: [];
} else {
  $vigili = _read_json_safe($vigiliPath);
}

if (function_exists('get_addestramenti')) {
  $add = get_addestramenti($slug) ?: [];
} else {
  $add = _read_json_safe($addestrPath);
}

/* -------------------- VALIDAZIONE VIGILE -------------------- */
$byId = [];
foreach ($vigili as $v) { $byId[(int)($v['id'] ?? 0)] = $v; }
if (empty($byId[$vigileId])) { http_response_code(404); die('Vigile non trovato'); }
$vigile = $byId[$vigileId];

/* -------------------- FILTRI -------------------- */
// filtra per vigile e anno (su 'data' YYYY-MM-DD)
$sessioni = array_values(array_filter($add, function($a) use ($vigileId, $anno){
  if ((int)($a['vigile_id'] ?? 0) !== $vigileId) return false;
  $d = (string)($a['data'] ?? '');
  return substr($d, 0, 4) === (string)$anno;
}));

// filtro attività opzionale
if ($attRichiesta !== '') {
  $sessioni = array_values(array_filter($sessioni, function($s) use ($attRichiesta) {
    return trim((string)($s['attivita'] ?? '')) === $attRichiesta;
  }));
}

/* -------------------- ORDINAMENTO -------------------- */
usort($sessioni, function($x,$y){
  $dx = (string)($x['data'] ?? ''); $dy = (string)($y['data'] ?? '');
  if ($dx !== $dy) return strcmp($dx, $dy);
  $sx = $x['inizio_dt'] ?? (($x['data'] ?? '').'T'.substr((string)($x['inizio'] ?? '00:00'),0,5).':00');
  $sy = $y['inizio_dt'] ?? (($y['data'] ?? '').'T'.substr((string)($y['inizio'] ?? '00:00'),0,5).':00');
  return strcmp($sx, $sy);
});

/* -------------------- RAGGRUPPAMENTI -------------------- */
$perMese = []; // ['YYYY-MM' => ['YYYY-MM-DD' => [sessioni...]]]
$totAnno = 0;
$primaAttivitaAnn = null;

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

/* -------------------- QUOTA (5h/mese cumulabili) -------------------- */
const QUOTA_MENSILE_MIN = 5 * 60; // 5 ore

$ing = $vigile['data_ingresso'] ?? $vigile['ingresso'] ?? $primaAttivitaAnn;
$ingMonth = $ing ? substr($ing, 0, 7) : sprintf('%04d-01', $anno);

// mesi dell'anno
$mesiAnno = [];
for ($m=1; $m<=12; $m++) $mesiAnno[] = sprintf('%04d-%02d', $anno, $m);

// mesi rilevanti
$startMonth   = max($ingMonth, sprintf('%04d-01', $anno));
$mesiRilevanti = array_values(array_filter($mesiAnno, fn($ym)=> $ym >= $startMonth));
$nMesiQuota   = count($mesiRilevanti);

$expectedMin  = $nMesiQuota * QUOTA_MENSILE_MIN;
$isOk         = ($nMesiQuota > 0) && ($totAnno >= $expectedMin);

/* -------------------- UTILS -------------------- */
$ore_hhmm = function(int $min): string { return sprintf('%d.%02d', intdiv($min,60), $min%60); };

$mesiIT = [1=>'GENNAIO',2=>'FEBBRAIO',3=>'MARZO',4=>'APRILE',5=>'MAGGIO',6=>'GIUGNO',
           7=>'LUGLIO',8=>'AGOSTO',9=>'SETTEMBRE',10=>'OTTOBRE',11=>'NOVEMBRE',12=>'DICEMBRE'];

/* -------------------- TCPDF -------------------- */
class PDF extends TCPDF {
  public string $titolo1 = 'Comando Provinciale Vigili del Fuoco Torino';
  public string $titolo2 = 'Dettaglio annuale addestramenti';
  public string $distacc = '';
  public string $riga4   = '';
  public string $riga5   = ''; // es. filtro attività

  public function Header() {
    $this->SetY(10);
    $this->SetFont('helvetica','B',12);
    $this->Cell(0, 0, $this->titolo1, 0, 1, 'C');

    $this->Ln(2);
    $this->SetFont('helvetica','',11);
    $this->Cell(0, 0, $this->titolo2, 0, 1, 'C');

    if ($this->distacc !== '') {
      $this->Ln(2);
      $this->SetFont('helvetica','',10);
      $this->Cell(0, 0, $this->distacc, 0, 1, 'C');
    }
    if ($this->riga4 !== '') {
      $this->Ln(2);
      $this->SetFont('helvetica','B',11);
      $this->Cell(0, 0, $this->riga4, 0, 1, 'C');
    }
    if ($this->riga5 !== '') {
      $this->Ln(1);
      $this->SetFont('helvetica','',9);
      $this->Cell(0, 0, $this->riga5, 0, 1, 'C');
    }

    $this->Ln(8);
    $this->SetLineWidth(0.3);
    $m = $this->getMargins();
    $this->Line($m['left'], $this->GetY(), $this->getPageWidth() - $m['right'], $this->GetY());
    $this->Ln(6);
  }

  public function Footer() {
    $m = $this->getMargins();
    $W = $this->getPageWidth();

    // Firma a destra
    $this->SetY(-28);
    $y = $this->GetY() + 8;
    $rightW = ($W - $m['left'] - $m['right'])/2 - 4;
    $xRight = $W - $m['right'] - $rightW;
    $this->SetLineWidth(0.2);
    $this->Line($xRight, $y, $W - $m['right'], $y);
    $this->SetFont('helvetica','',9);
    $this->SetXY($xRight, $y + 1);
    $this->Cell($rightW, 6, 'Firma del Capo Distaccamento', 0, 0, 'R');

    // Numero pagina
    $this->SetY(-15);
    $this->Cell(0, 10, 'Pagina '.$this->getAliasNumPage().' / '.$this->getAliasNbPages(), 0, 0, 'C');
  }
}

$cn = trim(($vigile['cognome'] ?? '').' '.($vigile['nome'] ?? ''));
$grado = trim((string)($vigile['grado'] ?? ''));
$intest = $cn . ($grado ? ' — '.$grado : '');

$pdf = new PDF('P','mm','A4', true, 'UTF-8', false);
$pdf->distacc = 'Distaccamento di '.$casermaName;
$pdf->riga4   = 'Vigile: '.$intest.' — Anno '.$anno;
$pdf->riga5   = ($attRichiesta!=='') ? ('Filtro attività: “'.$attRichiesta.'”') : '';

$pdf->SetCreator('Sistema Ore Addestramento');
$pdf->SetAuthor('Sistema');
$pdf->SetTitle('Dettaglio annuale '.$anno.' — '.$cn);
$pdf->SetMargins(15, 48, 15);
$pdf->SetHeaderMargin(8);
$pdf->SetAutoPageBreak(true, 35);
$pdf->setPrintHeader(true);
$pdf->setPrintFooter(true);
$pdf->SetFont('helvetica','',10);

$pdf->AddPage();

/* -------------------- BOX RIEPILOGO -------------------- */
$boxHtml = '
<table cellpadding="4" cellspacing="0" border="1" width="100%" style="margin-bottom:6px;">
  <tr style="background:#f8f9fa;">
    <td width="50%"><b>Totale anno</b></td>
    <td width="25%" align="right"><b>Minuti</b></td>
    <td width="25%" align="right"><b>Ore</b></td>
  </tr>
  <tr>
    <td>'.htmlspecialchars($cn, ENT_QUOTES, 'UTF-8').'</td>
    <td align="right">'.(int)$totAnno.'</td>
    <td align="right"><b>'.$ore_hhmm($totAnno).'</b></td>
  </tr>
  <tr>
    <td><b>Quota attesa</b> (5h × '.$nMesiQuota.' mesi)</td>
    <td align="right">'.(int)$expectedMin.'</td>
    <td align="right"><b>'.$ore_hhmm($expectedMin).'</b></td>
  </tr>
  <tr>
    <td colspan="3" align="center"><b>Stato: </b>'.
      ($isOk ? '<span style="color:#0a0; font-weight:bold;">OPERATIVO</span>' :
                '<span style="color:#a00; font-weight:bold;">NON OPERATIVO</span>')
    .'</td>
  </tr>
</table>';
$pdf->writeHTML($boxHtml, true, false, true, false, '');

/* -------------------- DETTAGLIO PER MESE -------------------- */
if (empty($perMese)) {
  $pdf->Ln(2);
  $pdf->SetFont('helvetica','',10);
  $pdf->MultiCell(0, 0, 'Nessun addestramento effettuato nell\'anno selezionato.', 0, 'L', false, 1);
} else {
  ksort($perMese);

  foreach ($perMese as $ym => $perGiorno) {
    // titolo mese + totale
    $parts = explode('-', $ym);
    $m = (int)($parts[1] ?? 0);
    $titMese = ($m >=1 && $m <= 12) ? $mesiIT[$m] : $ym;

    $totMese = 0;
    foreach ($perGiorno as $g => $lista) foreach ($lista as $s) $totMese += (int)($s['minuti'] ?? 0);

    $pdf->Ln(1);
    $pdf->SetFont('helvetica','B',11);
    $pdf->Cell(0, 0, $titMese.' '.$anno.' — Totale mese: '.$ore_hhmm($totMese), 0, 1, 'L');
    $pdf->Ln(1);

    // tabella mese
    $rows = '';
    foreach ($perGiorno as $giorno => $lista) {
      foreach ($lista as $s) {
        $inizio = $s['inizio'] ?? substr((string)($s['inizio_dt'] ?? ''), 11, 5);
        $fine   = $s['fine']   ?? substr((string)($s['fine_dt']   ?? ''), 11, 5);
        $min    = (int)($s['minuti'] ?? 0);
        $att    = htmlspecialchars((string)($s['attivita'] ?? ''), ENT_QUOTES, 'UTF-8');
        $note   = htmlspecialchars((string)($s['note']     ?? ''), ENT_QUOTES, 'UTF-8');

        // badge “→ giorno fine” se span su giorno successivo
        $fineExtra = '';
        if (!empty($s['inizio_dt']) && !empty($s['fine_dt'])) {
          $gStart = substr($s['inizio_dt'], 0, 10);
          $gEnd   = substr($s['fine_dt'],   0, 10);
          if ($gEnd !== $gStart) $fineExtra = ' <span style="font-size:85%; color:#555;">→ '.$gEnd.'</span>';
        }

        $rows .= '<tr>
          <td width="18%" align="left">'.htmlspecialchars($giorno, ENT_QUOTES, 'UTF-8').'</td>
          <td width="12%" align="center">'.htmlspecialchars((string)$inizio, ENT_QUOTES, 'UTF-8').'</td>
          <td width="12%" align="center">'.htmlspecialchars((string)$fine,   ENT_QUOTES, 'UTF-8').$fineExtra.'</td>
          <td width="12%" align="right">'.$ore_hhmm($min).'</td>
          <td width="21%" align="left">'.$att.'</td>
          <td width="25%" align="left">'.$note.'</td>
        </tr>';
      }
    }

    $tbl = '
    <table cellpadding="4" cellspacing="0" border="1" width="100%" style="margin-bottom:6px;">
      <thead>
        <tr style="background:#f2f2f2; font-weight:bold;">
          <th width="18%" align="left">Giorno</th>
          <th width="12%" align="center">Inizio</th>
          <th width="12%" align="center">Fine</th>
          <th width="12%" align="right">Durata</th>
          <th width="21%" align="left">Attività</th>
          <th width="25%" align="left">Note</th>
        </tr>
      </thead>
      <tbody>'.$rows.'</tbody>
      <tfoot>
        <tr>
          <td colspan="3" align="right" style="font-weight:bold;">Totale mese</td>
          <td align="right" style="font-weight:bold;">'.$ore_hhmm($totMese).'</td>
          <td colspan="2"></td>
        </tr>
      </tfoot>
    </table>';

    $pdf->writeHTML($tbl, true, false, true, false, '');
  }
}

if (ob_get_length()) { @ob_end_clean(); }
$pdf->Output('dettaglio_annuale_'.$vigileId.'_'.$anno.'.pdf', 'I');