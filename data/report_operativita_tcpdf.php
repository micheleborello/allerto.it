<?php
ob_start();
require __DIR__.'/auth.php';
require __DIR__.'/tenant_bootstrap.php'; // tenant + DATA_DIR
require_tenant_user();                   // solo utenti del distaccamento

require_mfa();
ini_set('display_errors', 1);
error_reporting(E_ALL);

require __DIR__.'/storage.php';
require __DIR__.'/utils.php';
require __DIR__.'/lib/tcpdf/tcpdf.php';

/* -------------------- SHIM caserme (se mancanti) -------------------- */
if (!function_exists('load_caserme')) {
  function load_caserme(): array {
    $base = __DIR__ . '/data';
    $cfg  = $base . '/caserme.json';
    $out  = [];
    if (is_file($cfg)) {
      $raw = @file_get_contents($cfg);
      $arr = $raw ? json_decode($raw, true) : null;
      if (is_array($arr)) {
        foreach ($arr as $r) {
          $slug = preg_replace('/[^a-z0-9_-]/i', '', (string)($r['slug'] ?? ''));
          $name = trim((string)($r['name'] ?? ''));
          if ($slug === '') continue;
          if (is_dir($base.'/'.$slug)) {
            $out[$slug] = ['slug'=>$slug, 'name'=> ($name!=='' ? $name : $slug)];
          }
        }
      }
    }
    foreach (glob($base.'/*', GLOB_ONLYDIR) ?: [] as $dir) {
      $slug = basename($dir);
      if (!preg_match('/^[a-z0-9_-]+$/i', $slug)) continue;
      if (!isset($out[$slug]) && (is_file($dir.'/vigili.json') || is_file($dir.'/users.json'))) {
        $out[$slug] = ['slug'=>$slug, 'name'=>$slug];
      }
    }
    uasort($out, fn($a,$b)=>strcasecmp($a['name'],$b['name']));
    return array_values($out);
  }
}
if (!function_exists('save_caserme')) {
  function save_caserme(array $rows): void {
    $base = __DIR__ . '/data';
    @mkdir($base, 0775, true);
    $p = $base . '/caserme.json';
    $out = [];
    foreach ($rows as $r) {
      $slug = preg_replace('/[^a-z0-9_-]/i', '', (string)($r['slug'] ?? ''));
      $name = trim((string)($r['name'] ?? ''));
      if ($slug === '') continue;
      $out[] = ['slug'=>$slug, 'name'=> ($name!=='' ? $name : $slug)];
    }
    @file_put_contents($p, json_encode($out, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));
    @chmod($p, 0664);
  }
}

/* -------------------- Fallback costanti dati (se mancanti) -------------------- */
if (!defined('DATA_DIR')) {
  $slugTmp = function_exists('tenant_active_slug') ? tenant_active_slug() : ($_SESSION['tenant_slug'] ?? '');
  $slugTmp = preg_replace('/[^a-z0-9_-]/i', '', (string)$slugTmp);
  define('DATA_DIR', __DIR__ . '/data' . ($slugTmp ? '/'.$slugTmp : ''));
}
if (!defined('VIGILI_JSON'))    define('VIGILI_JSON',    DATA_DIR.'/vigili.json');
if (!defined('ADDESTR_JSON'))   define('ADDESTR_JSON',   DATA_DIR.'/addestramenti.json');
if (!defined('INFORTUNI_JSON')) define('INFORTUNI_JSON', DATA_DIR.'/infortuni.json');

/* -------------------- INPUT -------------------- */
$meseStr = $_GET['mese'] ?? date('Y-m');   // atteso: YYYY-MM
if (!preg_match('/^\d{4}-\d{2}$/', $meseStr)) {
  http_response_code(400);
  die('Parametro "mese" invalido');
}
const QUOTA_MENSILE_MIN = 5 * 60;          // 5h/mese

/* -------------------- DATI TENANT -------------------- */
$slug = function_exists('tenant_active_slug') ? tenant_active_slug() : ($_SESSION['tenant_slug'] ?? '');
$casermaName = ucfirst($slug ?: 'Default');
foreach (load_caserme() as $c) {
  if (($c['slug'] ?? '') === $slug) { $casermaName = $c['name'] ?? $casermaName; break; }
}

/* -------------------- DATI -------------------- */
$vigili = array_values(array_filter(load_json(VIGILI_JSON), fn($v)=> (int)($v['attivo'] ?? 1)===1));
usort($vigili, fn($a,$b)=>strcmp(($a['cognome']??'').' '.($a['nome']??''), ($b['cognome']??'').' '.($b['nome']??'')));

$add = load_json(ADDESTR_JSON);

// Infortuni per-tenant (usa helper se presente)
if (function_exists('load_infortuni')) {
  $infortuni = load_infortuni();
} else {
  $infortuni = load_json(INFORTUNI_JSON);
}
$infByVid = [];
if (is_array($infortuni)) {
  foreach ($infortuni as $r) {
    $vid = (int)($r['vigile_id'] ?? 0);
    $dal = (string)($r['dal'] ?? '');
    $al  = (string)($r['al'] ?? '');
    if ($vid && preg_match('/^\d{4}-\d{2}-\d{2}$/',$dal) && preg_match('/^\d{4}-\d{2}-\d{2}$/',$al) && $al >= $dal) {
      $infByVid[$vid][] = [$dal, $al];
    }
  }
}

/* -------------------- FINESTRA 12 MESI -------------------- */
$rifY  = (int)substr($meseStr,0,4);
$rifM  = (int)substr($meseStr,5,2);
$startRef   = DateTime::createFromFormat('Y-m-d', sprintf('%04d-%02d-01', $rifY, $rifM));
$winStart   = (clone $startRef)->modify('-1 year'); // incluso
$winEnd     = (clone $startRef)->modify('-1 day');  // incluso
$winStartStr = $winStart->format('Y-m-d');
$winEndStr   = $winEnd->format('Y-m-d');

// Limiti solo del mese selezionato (per badge)
$meseStart = (clone $startRef);
$meseEnd   = (clone $startRef)->modify('last day of this month');
$meseStartStr = $meseStart->format('Y-m-d');
$meseEndStr   = $meseEnd->format('Y-m-d');

// Elenco mesi YYYY-MM nella finestra
$mesiFinestra = [];
$cur = (clone $winStart)->modify('first day of this month');
$end = (clone $winEnd)->modify('first day of next month');
while ($cur < $end) {
  $mesiFinestra[] = $cur->format('Y-m');
  $cur->modify('+1 month');
}

/* -------------------- UTILS -------------------- */
$mesiIT = [1=>'GENNAIO',2=>'FEBBRAIO',3=>'MARZO',4=>'APRILE',5=>'MAGGIO',6=>'GIUGNO',7=>'LUGLIO',8=>'AGOSTO',9=>'SETTEMBRE',10=>'OTTOBRE',11=>'NOVEMBRE',12=>'DICEMBRE'];
$mTxt   = $mesiIT[$rifM] ?? '';
$yTxt   = (string)$rifY;
$fmtIT  = fn(string $ymd) => substr($ymd,8,2).'/'.substr($ymd,5,2).'/'.substr($ymd,0,4);
$ore_hhmm = fn(int $min)  => sprintf('%d.%02d', intdiv($min,60), $min%60);

// minuti robusti: usa 'minuti' se presente, altrimenti calcola da inizio/fine
$minutiRecord = function(array $r): int {
  if (isset($r['minuti'])) return (int)$r['minuti'];
  $inizioDT = $r['inizio_dt'] ?? (($r['data'] ?? '').'T'.substr((string)($r['inizio'] ?? '00:00'),0,5).':00');
  $fineDT   = $r['fine_dt']   ?? (($r['data'] ?? '').'T'.substr((string)($r['fine']   ?? '00:00'),0,5).':00');
  try { return minuti_da_intervallo_datetime($inizioDT, $fineDT); } catch(Throwable $e) { return 0; }
};

/* -------------------- ACCUMULI NELLA FINESTRA (NETTI) -------------------- */
// Accumuliamo separatamente non-recupero e recupero; poi usiamo NETTO = max(0, nonrec - rec)
$nr12 = []; // minuti non-recupero in finestra
$rc12 = []; // minuti recupero in finestra
$primaAttivita = []; // vid => prima data nella finestra

foreach ($add as $r) {
  $vid = (int)($r['vigile_id'] ?? 0);
  $d   = (string)($r['data'] ?? '');
  if (!$vid || !$d) continue;
  if ($d >= $winStartStr && $d <= $winEndStr) {
    $mins = $minutiRecord($r);
    if ((int)($r['recupero'] ?? 0) === 1) {
      $rc12[$vid] = ($rc12[$vid] ?? 0) + $mins;
    } else {
      $nr12[$vid] = ($nr12[$vid] ?? 0) + $mins;
    }
    if (!isset($primaAttivita[$vid]) || $d < $primaAttivita[$vid]) $primaAttivita[$vid] = $d;
  }
}

/* -------------------- MESI DI INFORTUNIO NELLA FINESTRA -------------------- */
// Regola: mese con anche un solo giorno di infortunio = esente quota
$mesiInfByVid = []; // vid => set ym
foreach ($infByVid as $vid => $ranges) {
  $set = [];
  foreach ($ranges as [$dal, $al]) {
    // intersezione con finestra
    if ($al < $winStartStr || $dal > $winEndStr) continue;
    $from = max($dal, $winStartStr);
    $to   = min($al,  $winEndStr);

    $p = DateTime::createFromFormat('Y-m-d', substr($from,0,7).'-01');
    $lastMonth = substr($to,0,7);
    while ($p->format('Y-m') <= $lastMonth) {
      $set[$p->format('Y-m')] = true;
      $p->modify('+1 month');
    }
  }
  $mesiInfByVid[$vid] = $set;
}

/* -------------------- TCPDF -------------------- */
class PDF extends TCPDF {
  public string $titolo1 = 'Comando Provinciale Vigili del Fuoco Torino';
  public string $titolo2 = 'Stato operatività';
  public string $titolo3 = 'Distaccamento';  // valorizzato dopo con il nome reale
  public string $meseAnno = '';              // es. "AGOSTO 2025"
  public string $finestraTxt = '';           // es. "Periodo: 01/08/2024 – 31/07/2025"

  public function Header() {
    $this->SetY(10);
    $this->SetFont('helvetica','B',12);
    $this->Cell(0, 0, $this->titolo1, 0, 1, 'C');

    $this->Ln(2);
    $this->SetFont('helvetica','',11);
    $this->Cell(0, 0, $this->titolo2, 0, 1, 'C');

    $this->Ln(2);
    $this->SetFont('helvetica','',10);
    $this->Cell(0, 0, $this->titolo3, 0, 1, 'C');

    if ($this->meseAnno !== '') {
      $this->Ln(2);
      $this->SetFont('helvetica','B',11);
      $this->Cell(0, 0, $this->meseAnno, 0, 1, 'C');
    }
    if ($this->finestraTxt !== '') {
      $this->Ln(2);
      $this->SetFont('helvetica','',9);
      $this->Cell(0, 0, $this->finestraTxt, 0, 1, 'C');
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

    // Firma Capo Distaccamento (metà destra)
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

$pdf = new PDF('P','mm','A4', true, 'UTF-8', false);
$pdf->meseAnno    = ($mTxt && $yTxt) ? ($mTxt.' '.$yTxt) : $meseStr;
$pdf->finestraTxt = 'Periodo: '.$fmtIT($winStartStr).' – '.$fmtIT($winEndStr);
$pdf->titolo3     = 'Distaccamento di '.$casermaName;

$pdf->SetCreator('Sistema Ore Addestramento');
$pdf->SetAuthor('Sistema');
$pdf->SetTitle('Stato operatività '.$meseStr.' — '.$casermaName);
$pdf->SetMargins(15, 48, 15);
$pdf->SetHeaderMargin(8);
$pdf->SetAutoPageBreak(true, 35);
$pdf->setPrintHeader(true);
$pdf->setPrintFooter(true);
$pdf->SetFont('helvetica','',10);

$pdf->AddPage();

/* -------------------- TABELLA -------------------- */
$rows = '';
foreach ($vigili as $v) {
  $vid     = (int)$v['id'];
  $grado   = htmlspecialchars(trim((string)($v['grado'] ?? '')), ENT_QUOTES, 'UTF-8');
  $cognome = htmlspecialchars((string)($v['cognome'] ?? ''), ENT_QUOTES, 'UTF-8');
  $nome    = htmlspecialchars((string)($v['nome'] ?? ''), ENT_QUOTES, 'UTF-8');

  // Badge INFORTUNIO se (solo) il mese selezionato interseca un periodo di infortunio
  $haInfNelMese = false;
  if (!empty($infByVid[$vid])) {
    foreach ($infByVid[$vid] as [$dal, $al]) {
      if ($al >= $meseStartStr && $dal <= $meseEndStr) { $haInfNelMese = true; break; }
    }
  }
  $badgeInf = $haInfNelMese
    ? ' <span style="color:#a00; font-weight:bold; font-size:85%;">(INFORTUNIO o SOSPENSIONE nel mese)</span>'
    : '';

  $etichetta = trim(($grado ? $grado.' ' : '').$cognome.' '.$nome).$badgeInf;

  // 1) Data di ingresso (se presente) oppure prima attività vista nella finestra
  $ing = $v['data_ingresso'] ?? $v['ingresso'] ?? ($primaAttivita[$vid] ?? null);
  $ingMonth = $ing ? substr($ing, 0, 7) : substr($winStartStr, 0, 7);

  // 2) Mesi della finestra per cui si pretende la quota (da ingresso in poi)
  $mesiRilevanti = array_filter($mesiFinestra, fn($ym) => $ym >= $ingMonth);

  // 3) Escludi mesi di infortunio nella finestra
  $setInf = $mesiInfByVid[$vid] ?? [];
  $mesiRichiesti = array_values(array_filter($mesiRilevanti, fn($ym) => empty($setInf[$ym])));
  $nMesiQuota = count($mesiRichiesti);

  // 4) Minuti NETTI nella finestra e quota attesa
  $nonrec = (int)($nr12[$vid] ?? 0);
  $recup  = (int)($rc12[$vid] ?? 0);
  $actualMin   = max(0, $nonrec - $recup);              // *** ORE NETTE ***
  $expectedMin = $nMesiQuota * QUOTA_MENSILE_MIN;

  // 5) Regola: operativo se copre la quota richiesta (scontati i mesi di infortunio)
  $isOperativo = ($nMesiQuota > 0) && ($actualMin >= $expectedMin);

  // Se nel MESE SELEZIONATO il vigile è in infortunio, NON operativo per il mese
  if ($haInfNelMese) {
    $isOperativo = false;
  }

  $hhmm  = $ore_hhmm($actualMin).' / '.$ore_hhmm($expectedMin);

  if ($isOperativo) {
    $statoHtml = '<span style="color:#0a0; font-weight:bold;">OPERATIVO</span>';
  } elseif ($haInfNelMese) {
    $statoHtml = '<span style="color:#ff8c00; font-weight:bold;">NON OPERATIVO</span>'; // arancione
  } else {
    $statoHtml = '<span style="color:#a00; font-weight:bold;">NON OPERATIVO</span>';
  }

  $rows .= '<tr>
    <td width="55%">'.$etichetta.'</td>
    <td width="20%" align="right">'.$hhmm.'</td>
    <td width="25%" align="center">'.$statoHtml.'</td>
  </tr>';
}

$html = '
<table cellpadding="5" cellspacing="0" border="1" width="100%">
  <thead>
    <tr style="background:#f2f2f2; font-weight:bold;">
      <th width="55%" align="left">Vigile</th>
      <th width="20%" align="right">Ore (ultimi 12 mesi, NETTE)</th>
      <th width="25%" align="center">Stato</th>
    </tr>
  </thead>
  <tbody>'.$rows.'</tbody>
</table>';

$pdf->writeHTML($html, true, false, true, false, '');

$pdf->Ln(3);
$pdf->SetFont('helvetica','',9);
$pdf->writeHTML(
  '<i>Regola: 5 ore/mese con finestra mobile di 12 mesi. '.
  'I mesi in cui il vigile risulta in infortunio/sospensione non concorrono alla quota minima. '.
  'Le <b>ore riportate</b> sono al <b>netto</b> delle ore di recupero (non-recupero meno recupero).</i>',
  false, false, false, false, ''
);

if (ob_get_length()) { @ob_end_clean(); }
$pdf->Output('report_operativita_'.$meseStr.'.pdf', 'I');
