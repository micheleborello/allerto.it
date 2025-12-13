<?php
// report_mensile_tcpdf.php — riepilogo mensile addestramenti — TCPDF
ob_start();

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__.'/auth.php';
require_once __DIR__.'/tenant_bootstrap.php'; // DATA_DIR e costanti per-tenant
if (function_exists('require_tenant_user')) { require_tenant_user(); }
if (function_exists('require_mfa')) { require_mfa(); }

require_once __DIR__.'/storage.php';
require_once __DIR__.'/utils.php';
require_once __DIR__.'/lib/tcpdf/tcpdf.php';

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

/* -------------------- Loader robusto JSON (tenant-aware) -------------------- */
if (!function_exists('load_json_relaxed_local')) {
  function load_json_relaxed_local(string $pathOrFile, ?string $tenant = null): array {
    // Se è un nome "semplice", prova read_json() tenant-aware
    if (strpos($pathOrFile, '/') === false && strpos($pathOrFile, '\\') === false) {
      if (function_exists('read_json')) return read_json($pathOrFile, $tenant);
    }
    // Alias helper
    $base = basename($pathOrFile);
    if (function_exists('get_vigili') && $base === 'vigili.json')        return get_vigili($tenant) ?: [];
    if (function_exists('get_addestramenti') && $base === 'addestramenti.json') return get_addestramenti($tenant) ?: [];
    if (function_exists('load_infortuni') && $base === 'infortuni.json')  return load_infortuni($tenant) ?: [];

    // Fallback: filesystem
    if (!is_file($pathOrFile)) return [];
    $s = @file_get_contents($pathOrFile);
    if ($s === false) return [];
    $s = preg_replace('/^\xEF\xBB\xBF/', '', $s);      // BOM
    $s = preg_replace('/,\s*([\}\]])/m', '$1', $s);    // virgole finali
    $arr = json_decode($s, true);
    return is_array($arr) ? $arr : [];
  }
}

/* -------------------- Modalità output -------------------- */
$__REPORT_MODE = (defined('REPORT_SEND_MODE') ? REPORT_SEND_MODE : ((($_GET['mode'] ?? '') === 'S') ? 'S' : 'I'));

/* -------------------- Parametri -------------------- */
$meseStr = $_GET['mese'] ?? date('Y-m'); // YYYY-MM
$includiVuoti = !isset($_GET['solo_con_ore']);
if (!preg_match('/^\d{4}-\d{2}$/', $meseStr)) { http_response_code(400); die('Parametro "mese" invalido'); }

/* -------------------- Esclusioni dalla stampa (da riepilogo.php) -------------------- */
$excludeIds = [];
if (isset($_GET['exclude'])) {
  $raw = $_GET['exclude'];
  if (is_array($raw)) {
    $excludeIds = array_map('intval', $raw);
  } else {
    // supporta "exclude=1,2,3" o stringhe con spazi
    $excludeIds = array_map('intval', preg_split('/[,\s]+/', (string)$raw, -1, PREG_SPLIT_NO_EMPTY));
  }
}
$excludeIds = array_values(array_unique(array_filter($excludeIds, fn($x)=>$x>0)));

/* -------------------- Dati tenant -------------------- */
$slug = $TENANT;
$casermaName = 'Default';
foreach (load_caserme() as $c) {
  if (($c['slug'] ?? '') === $slug) { $casermaName = $c['name'] ?? $slug; break; }
}

/* -------------------- Dati JSON -------------------- */
$vigili = array_values(array_filter(load_json_relaxed_local('vigili.json', $TENANT) ?: [], fn($v)=> (int)($v['attivo'] ?? 1)===1));
usort($vigili, fn($a,$b)=>strcasecmp(($a['cognome']??'').' '.($a['nome']??''), ($b['cognome']??'').' '.($b['nome']??'')));

// 🔴 Filtra i nominativi spuntati in riepilogo.php
if (!empty($excludeIds)) {
  $vigili = array_values(array_filter($vigili, function($v) use ($excludeIds) {
    $vid = (int)($v['id'] ?? 0);
    return $vid > 0 && !in_array($vid, $excludeIds, true);
  }));
}

$add    = load_json_relaxed_local('addestramenti.json', $TENANT) ?: [];
$infRaw = load_json_relaxed_local('infortuni.json',     $TENANT) ?: [];

// Infortuni: mappa [vid] => [[dal, al], ...]
$INFORTUNI = [];
if (is_array($infRaw)) {
  foreach ($infRaw as $row) {
    $vid = (int)($row['vigile_id'] ?? 0);
    $dal = (string)($row['dal'] ?? '');
    $al  = (string)($row['al'] ?? '');
    if ($vid && preg_match('/^\d{4}-\d{2}-\d{2}$/',$dal) && preg_match('/^\d{4}-\d{2}-\d{2}$/',$al)) {
      $INFORTUNI[$vid][] = [$dal,$al];
    }
  }
}

// helper minuti robusto
$minutiRecord = function(array $r): int {
  if (isset($r['minuti'])) return (int)$r['minuti'];
  $data = (string)($r['data'] ?? '');
  $inizioDT = $r['inizio_dt'] ?? ($data ? ($data.'T'.substr((string)($r['inizio'] ?? '00:00'),0,5).':00') : null);
  $fineDT   = $r['fine_dt']   ?? ($data ? ($data.'T'.substr((string)($r['fine']   ?? '00:00'),0,5).':00') : null);
  if (!$inizioDT || !$fineDT) return 0;
  try { return minuti_da_intervallo_datetime($inizioDT, $fineDT); } catch(Throwable $e) { return 0; }
};

// helper: verifica infortunio nel mese
function ha_infortunio_nel_mese(int $vid, string $meseYYYYMM, array $mappaRanges): bool {
  if (!preg_match('/^\d{4}-\d{2}$/', $meseYYYYMM)) return false;
  $start = $meseYYYYMM.'-01';
  $dt = DateTime::createFromFormat('Y-m-d', $start); if (!$dt) return false;
  $end = $dt->format('Y-m-t');
  foreach ($mappaRanges[$vid] ?? [] as $rng) {
    $dal = $rng[0] ?? ''; $al = $rng[1] ?? '';
    if ($dal <= $end && $al >= $start) return true;
  }
  return false;
}
function data_inizio_infortunio_mese(int $vid, string $meseYYYYMM, array $mappaRanges): ?string {
  if (!preg_match('/^\d{4}-\d{2}$/', $meseYYYYMM)) return null;
  $start = $meseYYYYMM.'-01';
  $dt = DateTime::createFromFormat('Y-m-d', $start); if (!$dt) return null;
  $end = $dt->format('Y-m-t');
  $found = null;
  foreach ($mappaRanges[$vid] ?? [] as $rng) {
    $dal = $rng[0] ?? ''; $al = $rng[1] ?? '';
    if ($dal <= $end && $al >= $start) { if ($found === null || $dal < $found) $found = $dal; }
  }
  return $found;
}

// ---- Raggruppa per vigile nel mese (data robusta) ----
$prefix = substr($meseStr,0,7);
$estraiData = function(array $r): string {
  if (!empty($r['data'])) return (string)$r['data'];
  if (!empty($r['inizio_dt']) && preg_match('/^\d{4}-\d{2}-\d{2}T/',$r['inizio_dt'])) return substr($r['inizio_dt'],0,10);
  return '';
};
$perVigile = [];
foreach ($add as $r) {
  $vid = (int)($r['vigile_id'] ?? 0);
  $d   = $estraiData($r);
  if (!$vid || substr($d,0,7) !== $prefix) continue;
  $r['_d_'] = $d;
  $perVigile[$vid][] = $r;
}
foreach ($perVigile as &$lista) {
  usort($lista, function($x,$y){
    $dx = (string)($x['_d_'] ?? ''); $dy = (string)($y['_d_'] ?? '');
    if ($dx !== $dy) return strcmp($dx,$dy);
    $sx = $x['inizio_dt'] ?? (($dx ?: ($x['data'] ?? '')).'T'.substr((string)($x['inizio'] ?? '00:00'),0,5).':00');
    $sy = $y['inizio_dt'] ?? (($dy ?: ($y['data'] ?? '')).'T'.substr((string)($y['inizio'] ?? '00:00'),0,5).':00');
    return strcmp($sx,$sy);
  });
}
unset($lista);

// ---- Helpers presentazione ----
$ore_hhmm = fn(int $min) => sprintf('%d.%02d', intdiv($min,60), $min%60);
$giornoNum = fn(?string $ymd) => (preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)$ymd)) ? (string)(int)substr($ymd,8,2) : '';
$mesiIT = [1=>'GENNAIO',2=>'FEBBRAIO',3=>'MARZO',4=>'APRILE',5=>'MAGGIO',6=>'GIUGNO',7=>'LUGLIO',8=>'AGOSTO',9=>'SETTEMBRE',10=>'OTTOBRE',11=>'NOVEMBRE',12=>'DICEMBRE'];
$mTxt = $mesiIT[(int)substr($meseStr,5,2)] ?? '';
$yTxt = substr($meseStr,0,4);
$fmtIT = fn(string $ymd) => substr($ymd,8,2).'/'.substr($ymd,5,2).'/'.substr($ymd,0,4);

/* -------------------- TCPDF -------------------- */
class PDF extends TCPDF {
  public string $titolo1 = 'Comando Provinciale Vigili del Fuoco Torino';
  public string $titolo2 = 'Prestazione personale Volontario per addestramento';
  public string $titolo3 = 'Distaccamento';
  public string $meseAnno = '';
  public string $notaLegale = '';

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

    $this->Ln(10);
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
$pdf->meseAnno = ($mTxt && $yTxt) ? ($mTxt.' '.$yTxt) : '';
$pdf->titolo3  = 'Distaccamento di '.$casermaName;
$pdf->notaLegale =
  "Il sottoscritto, consapevole che la dichiarazione mendace e la falsità in atti costituiscono reato ai sensi dell'art. 76 del D.P.R. 445/2000, ".
  "dichiara sotto la propria responsabilità di aver verificato che i nominativi in oggetto hanno partecipato agli addestramenti indicati.";

$pdf->SetCreator('Sistema Ore Addestramento');
$pdf->SetAuthor('Sistema');
$pdf->SetTitle('Report mensile '.$meseStr.' — '.$casermaName);
$pdf->SetMargins(15, 48, 15);
$pdf->SetHeaderMargin(8);
$pdf->SetAutoPageBreak(true, 45);
$pdf->setImageScale(1.00);
$pdf->setPrintHeader(true);
$pdf->setPrintFooter(true);
$pdf->SetFont('helvetica','',10);

$pdf->AddPage();

$printed = false;

$ore_hhmm = fn(int $min) => sprintf('%d.%02d', intdiv($min,60), $min%60);
$giornoNum = fn(?string $ymd) => (preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)$ymd)) ? (string)(int)substr($ymd,8,2) : '';
$mesiIT = [1=>'GENNAIO',2=>'FEBBRAIO',3=>'MARZO',4=>'APRILE',5=>'MAGGIO',6=>'GIUGNO',7=>'LUGLIO',8=>'AGOSTO',9=>'SETTEMBRE',10=>'OTTOBRE',11=>'NOVEMBRE',12=>'DICEMBRE'];
$mTxt = $mesiIT[(int)substr($meseStr,5,2)] ?? '';
$yTxt = substr($meseStr,0,4);
$fmtIT = fn(string $ymd) => substr($ymd,8,2).'/'.substr($ymd,5,2).'/'.substr($ymd,0,4);

foreach ($vigili as $v) {
  $vid   = (int)$v['id'];
  $lista = $perVigile[$vid] ?? [];
  if (!$includiVuoti && empty($lista)) continue;
  $printed = true;

  $gradoOrig   = htmlspecialchars($v['grado']   ?? '', ENT_QUOTES, 'UTF-8');
  $cognomeOrig = htmlspecialchars($v['cognome'] ?? '', ENT_QUOTES, 'UTF-8');
  $nomeOrig    = htmlspecialchars($v['nome']    ?? '', ENT_QUOTES, 'UTF-8');

  $haInf = ha_infortunio_nel_mese($vid, $prefix, $INFORTUNI);
  $infDal = $haInf ? data_inizio_infortunio_mese($vid, $prefix, $INFORTUNI) : null;
  $badgeInf = $haInf ? ' <span style="color:#a00; font-weight:bold;">(INFORTUNIO)</span>' : '';

  $rowsHtml = '';
  $totMin = 0;

  if (empty($lista)) {
    $rowsHtml .= '<tr>
      <td width="10%">'.$gradoOrig.'</td>
      <td width="20%">'.$cognomeOrig.'</td>
      <td width="20%">'.$nomeOrig.$badgeInf.'</td>
      <td width="10%" align="C">—</td>
      <td width="15%" align="C">—</td>
      <td width="15%" align="C">—</td>
      <td width="10%" align="R">'. $ore_hhmm(0) .'</td>
    </tr>';
  } else {
    $grado=$gradoOrig; $cognome=$cognomeOrig; $nome=$nomeOrig.$badgeInf; $first=true;
    foreach ($lista as $r) {
      $day = $giornoNum((string)($r['_d_'] ?? ($r['data'] ?? '')));
      $iHH = $r['inizio'] ?? substr((string)($r['inizio_dt'] ?? ''),11,5);
      $fHH = $r['fine']   ?? substr((string)($r['fine_dt']   ?? ''),11,5);
      $min = $minutiRecord($r);
      $totMin += $min;

      $rowsHtml .= '<tr>
        <td width="10%">'.$grado.'</td>
        <td width="20%">'.$cognome.'</td>
        <td width="20%">'.$nome.'</td>
        <td width="10%" align="C">'.$day.'</td>
        <td width="15%" align="C">'.$iHH.'</td>
        <td width="15%" align="C">'.$fHH.'</td>
        <td width="10%" align="R">'.$ore_hhmm($min).'</td>
      </tr>';

      if ($first) { $grado = $cognome = ''; $nome = ''; $first = false; }
    }
  }

  $notaInf = $infDal ? '<span style="color:#a00; font-weight:bold;">(INFORTUNIO dal '.$fmtIT($infDal).')</span>' : '';

  $htmlTable = '
  <table cellpadding="4" cellspacing="0" border="1" width="100%">
    <thead>
      <tr>
        <th width="10%" style="background:#f2f2f2; font-weight:bold;">GRADO</th>
        <th width="20%" style="background:#f2f2f2; font-weight:bold;">COGNOME</th>
        <th width="20%" style="background:#f2f2f2; font-weight:bold;">NOME</th>
        <th width="10%" style="background:#f2f2f2; font-weight:bold;">DATA</th>
        <th width="15%" style="background:#f2f2f2; font-weight:bold;">INIZIO</th>
        <th width="15%" style="background:#f2f2f2; font-weight:bold;">FINE</th>
        <th width="10%" style="background:#f2f2f2; font-weight:bold;" align="right">ORE</th>
      </tr>
    </thead>
    <tbody>'.$rowsHtml.'</tbody>
    <tfoot>
      <tr>
        <td colspan="5" align="right" style="font-weight:bold;">Totale</td>
        <td width="15%" align="right" style="font-weight:bold;">'.$notaInf.'</td>
        <td width="10%" align="right" style="font-weight:bold;">'.$ore_hhmm($totMin).'</td>
      </tr>
    </tfoot>
  </table>';

  // salto pagina robusto
  $rowsCount = max(1, count($lista));
  $theadH = 8; $rowH = 9; $tfootH = 8; $firmaH = 12; $sepH = 6; $fudge = 3;
  $estimatedH = $theadH + ($rowsCount*$rowH) + $tfootH + $firmaH + $sepH + $fudge;
  $bottomLimit = $pdf->getPageHeight() - $pdf->getBreakMargin();
  if ($pdf->GetY() + $estimatedH > $bottomLimit) { $pdf->AddPage(); }

  $pdf->writeHTML($htmlTable, true, false, true, false, '');

  // firme + separatore
  $m = $pdf->getMargins();
  $y = $pdf->GetY() + 2;
  $pdf->SetLineWidth(0.2);
  $pdf->Line($m['left'], $y, $m['left'] + 70, $y);
  $pdf->SetY($y+1);
  $pdf->SetFont('helvetica','',9);
  $pdf->Cell(70, 6, 'FIRMA', 0, 1, 'L');

  $pdf->Ln(2);
  $y = $pdf->GetY();
  $pdf->Line($m['left'], $y, $pdf->getPageWidth() - $m['right'], $y);
  $pdf->Ln(2);
}

if (!$printed) {
  $pdf->writeHTML('<div style="color:#666;">Nessun vigile con addestramenti nel mese selezionato.</div>');
}

$fname = 'report_mensile_'.$meseStr.'.pdf';
if ($__REPORT_MODE === 'S') {
  $bytes = $pdf->Output($fname, 'S');
  if (ob_get_length()) { @ob_end_clean(); }
  echo $bytes;
  return;
}

if (ob_get_length()) { @ob_end_clean(); }
$pdf->Output($fname, 'I');