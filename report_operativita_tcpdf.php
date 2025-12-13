<?php
// report_operativita_tcpdf.php — Stato operatività (12 mesi mobili) — TCPDF
ob_start();

ini_set('display_errors', 1);
error_reporting(E_ALL);

require __DIR__.'/auth.php';
require __DIR__.'/tenant_bootstrap.php'; // tenant + DATA_DIR
if (function_exists('require_tenant_user')) { require_tenant_user(); }
if (function_exists('require_mfa')) { require_mfa(); }

require __DIR__.'/storage.php';
require __DIR__.'/utils.php';
require __DIR__.'/lib/tcpdf/tcpdf.php';

/* -------------------- Tenant corrente -------------------- */
$TENANT = $_SESSION['tenant_slug'] ?? $_SESSION['CASERMA_SLUG'] ?? (function_exists('storage_current_tenant') ? storage_current_tenant() : 'default');
$TENANT = preg_replace('/[^a-z0-9_-]/i', '', (string)$TENANT);

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
          if (is_dir($base.'/'.$slug)) { $out[$slug] = ['slug'=>$slug, 'name'=>($name!==''?$name:$slug)]; }
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

/* -------------------- Fallback costanti dati (se mancanti) -------------------- */
if (!defined('DATA_DIR')) {
  $slugTmp = $TENANT;
  define('DATA_DIR', __DIR__ . '/data' . ($slugTmp ? '/'.$slugTmp : ''));
}
if (!defined('VIGILI_JSON'))    define('VIGILI_JSON',    DATA_DIR.'/vigili.json');
if (!defined('ADDESTR_JSON'))   define('ADDESTR_JSON',   DATA_DIR.'/addestramenti.json');
if (!defined('INFORTUNI_JSON')) define('INFORTUNI_JSON', DATA_DIR.'/infortuni.json');

/* -------------------- Loader robusto JSON -------------------- */
/* Usa gli helper tenant-aware se disponibili, altrimenti path diretto. */
if (!function_exists('load_json_relaxed_local')) {
  function load_json_relaxed_local(string $pathOrFile, ?string $tenant = null): array {
    // Se è un nome semplice (niente slash), prova via storage.php (tenant-aware)
    if (strpos($pathOrFile, '/') === false && strpos($pathOrFile, '\\') === false) {
      if (function_exists('read_json')) {
        return read_json($pathOrFile, $tenant);
      }
    }

    // Alias specifici comodi
    $base = basename($pathOrFile);
    if (function_exists('get_vigili') && $base === 'vigili.json') {
      return get_vigili($tenant);
    }
    if (function_exists('get_addestramenti') && $base === 'addestramenti.json') {
      return get_addestramenti($tenant);
    }

    // Fallback: leggi dal filesystem (tollerante)
    if (!is_file($pathOrFile)) return [];
    $s = @file_get_contents($pathOrFile);
    if ($s === false) return [];
    // rimuove BOM e virgole finali
    $s = preg_replace('/^\xEF\xBB\xBF/', '', $s);
    $s = preg_replace('/,\s*([\}\]])/m', '$1', $s);
    $arr = json_decode($s, true);
    return is_array($arr) ? $arr : [];
  }
}

/* -------------------- Modalità output -------------------- */
$__REPORT_MODE = (defined('REPORT_SEND_MODE') ? REPORT_SEND_MODE : ((($_GET['mode'] ?? '') === 'S') ? 'S' : 'I'));

/* -------------------- INPUT -------------------- */
$meseStr = $_GET['mese'] ?? date('Y-m');   // atteso: YYYY-MM
if (!preg_match('/^\d{4}-\d{2}$/', $meseStr)) {
  http_response_code(400); die('Parametro "mese" invalido');
}
const QUOTA_MENSILE_MIN = 5 * 60;          // 5h/mese

/* -------------------- Esclusioni dalla stampa (da riepilogo.php) -------------------- */
$excludeIds = [];
if (isset($_GET['exclude'])) {
  $raw = $_GET['exclude'];
  if (is_array($raw)) {
    $excludeIds = array_map('intval', $raw);
  } else {
    // supporta "exclude=1,2,3" o stringhe con spazi/punto e virgola
    $excludeIds = array_map('intval', preg_split('/[,\s;]+/', (string)$raw, -1, PREG_SPLIT_NO_EMPTY));
  }
}
$excludeIds = array_values(array_unique(array_filter($excludeIds, fn($x)=>$x>0)));

/* -------------------- DATI TENANT -------------------- */
$slug = $TENANT;
$casermaName = ucfirst($slug ?: 'Default');
foreach (load_caserme() as $c) {
  if (($c['slug'] ?? '') === $slug) { $casermaName = $c['name'] ?? $casermaName; break; }
}

/* -------------------- DATI -------------------- */
$vigili = array_values(array_filter(load_json_relaxed_local('vigili.json', $TENANT) ?: [], fn($v)=> (int)($v['attivo'] ?? 1)===1));
usort($vigili, fn($a,$b)=>strcmp(($a['cognome']??'').' '.($a['nome']??''), ($b['cognome']??'').' '.($b['nome']??'')));

// 🔴 Filtra i nominativi spuntati in riepilogo.php
if (!empty($excludeIds)) {
  $vigili = array_values(array_filter($vigili, function($v) use ($excludeIds) {
    $vid = (int)($v['id'] ?? 0);
    return $vid > 0 && !in_array($vid, $excludeIds, true);
  }));
}

$add = load_json_relaxed_local('addestramenti.json', $TENANT) ?: [];

// Infortuni per-tenant
$infortuni = function_exists('load_infortuni')
  ? (load_infortuni() ?: [])
  : (load_json_relaxed_local('infortuni.json', $TENANT) ?: []);

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
$winStart   = (clone $startRef)->modify('-12 months'); // inclusivo: 12 mesi precedenti il mese selezionato
$winEnd     = (clone $startRef)->modify('-1 day');     // fino all'ultimo giorno del mese precedente
$winStartStr = $winStart->format('Y-m-d');
$winEndStr   = $winEnd->format('Y-m-d');

// Limiti mese selezionato (per badge)
$meseStart = (clone $startRef);
$meseEnd   = (clone $startRef)->modify('last day of this month');
$meseStartStr = $meseStart->format('Y-m-d');
$meseEndStr   = $meseEnd->format('Y-m-d');

// Elenco mesi YYYY-MM nella finestra
$mesiFinestra = [];
$cur = (clone $winStart)->modify('first day of this month');
$end = (clone $winEnd)->modify('first day of next month');
while ($cur < $end) { $mesiFinestra[] = $cur->format('Y-m'); $cur->modify('+1 month'); }

/* -------------------- UTILS -------------------- */
$mesiIT = [1=>'GENNAIO',2=>'FEBBRAIO',3=>'MARZO',4=>'APRILE',5=>'MAGGIO',6=>'GIUGNO',7=>'LUGLIO',8=>'AGOSTO',9=>'SETTEMBRE',10=>'OTTOBRE',11=>'NOVEMBRE',12=>'DICEMBRE'];
// Etichetta mese di riferimento: usa il mese SUCCESSIVO a quello selezionato in riepilogo
$nextRef = (clone $startRef)->modify('+1 month');
$mTxt   = $mesiIT[(int)$nextRef->format('n')] ?? '';
$yTxt   = $nextRef->format('Y');
$fmtIT  = fn(string $ymd) => substr($ymd,8,2).'/'.substr($ymd,5,2).'/'.substr($ymd,0,4);
$ore_hhmm = fn(int $min)  => sprintf('%d.%02d', intdiv($min,60), $min%60);

// minuti robusti
$minutiRecord = function(array $r): int {
  if (isset($r['minuti'])) return (int)$r['minuti'];
  $inizioDT = $r['inizio_dt'] ?? (($r['data'] ?? '').'T'.substr((string)($r['inizio'] ?? '00:00'),0,5).':00');
  $fineDT   = $r['fine_dt']   ?? (($r['data'] ?? '').'T'.substr((string)($r['fine']   ?? '00:00'),0,5).':00');
  try { return minuti_da_intervallo_datetime($inizioDT, $fineDT); } catch(Throwable $e) { return 0; }
};

/* -------------------- ACCUMULI NELLA FINESTRA (NETTI) -------------------- */
$nr12 = []; $rc12 = []; $primaAttivita = [];
foreach ($add as $r) {
  $vid = (int)($r['vigile_id'] ?? 0);
  $d   = (string)($r['data'] ?? '');
  if (!$vid) continue;
  if ($d === '' && !empty($r['inizio_dt']) && preg_match('/^\d{4}-\d{2}-\d{2}T/', (string)$r['inizio_dt'])) {
    $d = substr($r['inizio_dt'], 0, 10);
  }
  if (!$d || $d < $winStartStr || $d > $winEndStr) continue;

  $mins = $minutiRecord($r);
  if ((int)($r['recupero'] ?? 0) === 1) { $rc12[$vid] = ($rc12[$vid] ?? 0) + $mins; }
  else { $nr12[$vid] = ($nr12[$vid] ?? 0) + $mins; }

  if (!isset($primaAttivita[$vid]) || $d < $primaAttivita[$vid]) $primaAttivita[$vid] = $d;
}

/* -------------------- MESI DI INFORTUNIO NELLA FINESTRA -------------------- */
$mesiInfByVid = [];
foreach ($infByVid as $vid => $ranges) {
  $set = [];
  foreach ($ranges as [$dal, $al]) {
    if ($al < $winStartStr || $dal > $winEndStr) continue;
    $from = max($dal, $winStartStr);
    $to   = min($al,  $winEndStr);

    $p = DateTime::createFromFormat('Y-m-d', substr($from,0,7).'-01');
    $lastMonth = substr($to,0,7);
    while ($p->format('Y-m') <= $lastMonth) { $set[$p->format('Y-m')] = true; $p->modify('+1 month'); }
  }
  $mesiInfByVid[$vid] = $set;
}

/* -------------------- TCPDF -------------------- */
class PDF extends TCPDF {
  public string $titolo1 = 'Comando Provinciale Vigili del Fuoco Torino';
  public string $titolo2 = 'Stato operatività';
  public string $titolo3 = 'Distaccamento';
  public string $meseAnno = '';
  public string $finestraTxt = '';
  public string $notaLegale = '';

  public function Header() {
    $this->SetY(10);
    $this->SetFont('helvetica','B',12);
    $this->Cell(0,0,$this->titolo1,0,1,'C');

    $this->Ln(2);
    $this->SetFont('helvetica','',11);
    $this->Cell(0,0,$this->titolo2,0,1,'C');

    $this->Ln(2);
    $this->SetFont('helvetica','',10);
    $this->Cell(0,0,$this->titolo3,0,1,'C');

    if ($this->meseAnno !== '') {
      $this->Ln(2);
      $this->SetFont('helvetica','B',11);
      $this->Cell(0,0,$this->meseAnno,0,1,'C');
    }
    if ($this->finestraTxt !== '') {
      $this->Ln(2);
      $this->SetFont('helvetica','',9);
      $this->Cell(0,0,$this->finestraTxt,0,1,'C');
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

    $this->SetY(-30);
    $sigBaseY = $this->GetY() + 10;

    if (!empty($this->notaLegale)) {
      $boxW = $W - $m['left'] - $m['right'];
      $this->SetFont('helvetica','',8);
      $this->SetTextColor(80,80,80);
      $h = $this->getStringHeight($boxW, $this->notaLegale);
      $yTop = $sigBaseY - 4 - $h; if ($yTop < $m['top']) $yTop = $m['top'];
      $this->SetXY($m['left'], $yTop);
      $this->MultiCell($boxW, 0, $this->notaLegale, 0, 'J', false, 1);
      $this->SetTextColor(0,0,0);
    }

    $rightW = ($W - $m['left'] - $m['right'])/2 - 4;
    $xRight = $W - $m['right'] - $rightW;
    $this->SetLineWidth(0.2);
    $this->Line($xRight, $sigBaseY, $W - $m['right'], $sigBaseY);
    $this->SetFont('helvetica','',9);
    $this->SetXY($xRight, $sigBaseY + 1);
    $this->Cell($rightW, 6, 'Firma del Capo Distaccamento', 0, 0, 'R');

    $this->SetY(-15);
    $this->Cell(0, 10, 'Pagina '.$this->getAliasNumPage().' / '.$this->getAliasNbPages(), 0, 0, 'C');
  }
}
$pdf = new PDF('P','mm','A4', true, 'UTF-8', false);
$pdf->meseAnno    = ($mTxt && $yTxt) ? ($mTxt.' '.$yTxt) : $meseStr;
$pdf->finestraTxt = 'Periodo: '.$fmtIT($winStartStr).' – '.$fmtIT($winEndStr);
$pdf->titolo3     = 'Distaccamento di '.$casermaName;
$pdf->notaLegale  =
  "Il sottoscritto, consapevole che la dichiarazione mendace e la falsità in atti costituiscono reato ai sensi dell'art. 76 del D.P.R. 445/2000, ".
  "dichiara sotto la propria responsabilità di aver verificato che i nominativi in oggetto hanno partecipato agli addestramenti indicati.";

$pdf->SetCreator('Sistema Ore Addestramento');
$pdf->SetAuthor('Sistema');
$pdf->SetTitle('Stato operatività '.$nextRef->format('Y-m').' – '.$casermaName);
$pdf->SetMargins(15, 48, 15);
$pdf->SetHeaderMargin(8);
$pdf->SetAutoPageBreak(true, 35);
$pdf->setPrintHeader(true);
$pdf->setPrintFooter(true);
$pdf->SetFont('helvetica','',10);

$pdf->AddPage();

/* -------------------- TABELLA -------------------- */
$rows = '';
// Decodifica eventuali entitÃ  HTML nei nomi e ri-escapa in uscita
$san = fn($s) => htmlspecialchars(html_entity_decode((string)$s, ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES, 'UTF-8');

foreach ($vigili as $v) {
  $vid     = (int)$v['id'];
  $grado   = $san(trim((string)($v['grado'] ?? '')));
  $cognome = $san($v['cognome'] ?? '');
  $nome    = $san($v['nome'] ?? '');

  // Badge infortunio nel mese selezionato
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

  // Quota richiesta su 12 mesi pro-rata (ingresso e infortuni)
  $ing = $v['data_ingresso'] ?? $v['ingresso'] ?? ($primaAttivita[$vid] ?? null);
  $ingMonth = $ing ? substr($ing, 0, 7) : substr($winStartStr, 0, 7);
  $mesiRilevanti = array_filter($mesiFinestra, fn($ym) => $ym >= $ingMonth);
  $setInf = $mesiInfByVid[$vid] ?? [];
  $mesiRichiesti = array_values(array_filter($mesiRilevanti, fn($ym) => empty($setInf[$ym])));
  $nMesiQuota = count($mesiRichiesti);

  // Minuti netti nella finestra
  $nonrec = (int)($nr12[$vid] ?? 0);
  $recup  = (int)($rc12[$vid] ?? 0);
  $actualMin   = max(0, $nonrec - $recup);
  $expectedMin = $nMesiQuota * QUOTA_MENSILE_MIN;

  // Stato operativo
  $isOperativo = ($nMesiQuota > 0) && ($actualMin >= $expectedMin);
  if ($haInfNelMese) $isOperativo = false;

  $hhmm  = $ore_hhmm($actualMin).' / '.$ore_hhmm($expectedMin);
  if ($isOperativo)      $statoHtml = '<span style="color:#0a0; font-weight:bold;">OPERATIVO</span>';
  elseif ($haInfNelMese) $statoHtml = '<span style="color:#ff8c00; font-weight:bold;">NON OPERATIVO</span>';
  else                   $statoHtml = '<span style="color:#a00; font-weight:bold;">NON OPERATIVO</span>';

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
  '<i>Regola: 5 ore/mese in 12 mesi. '.
  'I mesi in cui il vigile risulta in infortunio/sospensione non concorrono alla quota minima. '.
  'Le <b>ore riportate</b> sono al <b>netto</b> delle ore di recupero (non-recupero meno recupero).</i>',
  false, false, false, false, ''
);

$fname = 'report_operativita_'.$meseStr.'.pdf';
if ($__REPORT_MODE === 'S') {
  $bytes = $pdf->Output($fname, 'S');
  if (ob_get_length()) { @ob_end_clean(); }
  echo $bytes;
  return;
}

if (ob_get_length()) { @ob_end_clean(); }
$pdf->Output($fname, 'I');
