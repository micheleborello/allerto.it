<?php
// report_annuale_tcpdf.php — PDF riepilogo annuale addestramenti (TCPDF)
ob_start();

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__.'/auth.php';
require_once __DIR__.'/tenant_bootstrap.php'; // tenant + DATA_DIR (se presenti)

// Protezioni soft
if (function_exists('require_tenant_user')) { require_tenant_user(); }
if (function_exists('require_mfa')) { require_mfa(); }

require_once __DIR__.'/storage.php';
require_once __DIR__.'/utils.php';
require_once __DIR__.'/lib/tcpdf/tcpdf.php';

/* -------------------- Tenant corrente -------------------- */
$TENANT = $_SESSION['tenant_slug'] ?? $_SESSION['CASERMA_SLUG'] ?? (function_exists('storage_current_tenant') ? storage_current_tenant() : '');
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
  $slugTmp = $TENANT;
  define('DATA_DIR', __DIR__ . '/data' . ($slugTmp ? '/'.$slugTmp : ''));
}
if (!defined('VIGILI_JSON'))    define('VIGILI_JSON',    DATA_DIR.'/vigili.json');
if (!defined('ADDESTR_JSON'))   define('ADDESTR_JSON',   DATA_DIR.'/addestramenti.json');
if (!defined('INFORTUNI_JSON')) define('INFORTUNI_JSON', DATA_DIR.'/infortuni.json');

/* -------------------- Loader robusto JSON (tenant-aware) -------------------- */
if (!function_exists('load_json_relaxed_local')) {
  function load_json_relaxed_local(string $pathOrName, ?string $tenant = null): array {
    // Se è un nome "semplice", prova read_json() tenant-aware
    if (strpos($pathOrName, '/') === false && strpos($pathOrName, '\\') === false) {
      if (function_exists('read_json')) return read_json($pathOrName, $tenant);
      // alias helper
      $base = $pathOrName;
      if ($base === 'vigili.json'        && function_exists('get_vigili'))        return get_vigili($tenant) ?: [];
      if ($base === 'addestramenti.json' && function_exists('get_addestramenti')) return get_addestramenti($tenant) ?: [];
      if ($base === 'infortuni.json'     && function_exists('load_infortuni'))    return load_infortuni($tenant) ?: [];
    }
    // Fallback filesystem
    if (!is_file($pathOrName)) return [];
    $s = @file_get_contents($pathOrName);
    if ($s === false) return [];
    $s = preg_replace('/^\xEF\xBB\xBF/', '', $s);   // BOM
    $s = preg_replace('/,\s*([\}\]])/m', '$1', $s); // virgole finali
    $a = json_decode($s, true);
    return is_array($a) ? $a : [];
  }
}

/* -------------------- Parametri -------------------- */
$anno = isset($_GET['anno']) ? (int)$_GET['anno'] : (int)date('Y');
if ($anno < 2000 || $anno > 2100) {
  http_response_code(400);
  die('Parametro "anno" invalido');
}

/* -------------------- Dati tenant (nome caserma) -------------------- */
$slug = $TENANT;
$casermaName = ucfirst($slug ?: 'Default');
foreach (load_caserme() as $c) {
  if (($c['slug'] ?? '') === $slug) { $casermaName = $c['name'] ?? $casermaName; break; }
}

/* -------------------- Dati JSON (tenant-aware) -------------------- */
$vigili = array_values(array_filter(load_json_relaxed_local('vigili.json', $TENANT) ?: [], fn($v)=> (int)($v['attivo'] ?? 1)===1));
usort($vigili, fn($a,$b)=>strcasecmp(($a['cognome']??'').' '.($a['nome']??''), ($b['cognome']??'').' '.($b['nome']??'')));

$add = load_json_relaxed_local('addestramenti.json', $TENANT) ?: [];

/* -------------------- Infortuni: mappa [vid] => [[dal, al], ...] -------------------- */
$infRaw = load_json_relaxed_local('infortuni.json', $TENANT) ?: [];
$INFORTUNI = [];
if (is_array($infRaw)) {
  foreach ($infRaw as $row) {
    $vid = (int)($row['vigile_id'] ?? 0);
    $dal = (string)($row['dal'] ?? '');
    $al  = (string)($row['al'] ?? '');
    if ($vid && preg_match('/^\d{4}-\d{2}-\d{2}$/',$dal) && preg_match('/^\d{4}-\d{2}-\d{2}$/',$al) && $al >= $dal) {
      $INFORTUNI[$vid][] = [$dal,$al];
    }
  }
}

/* -------------------- Raggruppa addestramenti per vigile nell'anno -------------------- */
$perVigile = [];
$primaAttivitaGlob = [];

// minuti robusti
$minutiRecord = function(array $r): int {
  if (isset($r['minuti'])) return (int)$r['minuti'];
  $data = (string)($r['data'] ?? '');
  $inizioDT = $r['inizio_dt'] ?? ($data ? ($data.'T'.substr((string)($r['inizio'] ?? '00:00'),0,5).':00') : null);
  $fineDT   = $r['fine_dt']   ?? ($data ? ($data.'T'.substr((string)($r['fine']   ?? '00:00'),0,5).':00') : null);
  if (!$inizioDT || !$fineDT) return 0;
  try { return minuti_da_intervallo_datetime($inizioDT, $fineDT); } catch(Throwable $e) { return 0; }
};

foreach ($add as $r) {
  $vid = (int)($r['vigile_id'] ?? 0);
  if (!$vid) continue;

  // data robusta
  $d = (string)($r['data'] ?? '');
  if ($d === '' && !empty($r['inizio_dt']) && preg_match('/^\d{4}-\d{2}-\d{2}T/', (string)$r['inizio_dt'])) {
    $d = substr($r['inizio_dt'], 0, 10);
  }
  if ($d === '') continue;

  // prima attività globale per il vigile
  if (!isset($primaAttivitaGlob[$vid]) || $d < $primaAttivitaGlob[$vid]) {
    $primaAttivitaGlob[$vid] = $d;
  }

  // filtra per anno
  if ((int)substr($d,0,4) !== $anno) continue;

  $r['_d_'] = $d;
  $perVigile[$vid][] = $r;
}

// Ordina sessioni per data+ora
foreach ($perVigile as &$lista) {
  usort($lista, function($x,$y){
    $dx = (string)($x['_d_'] ?? ($x['data'] ?? '')); $dy = (string)($y['_d_'] ?? ($y['data'] ?? ''));
    if ($dx !== $dy) return strcmp($dx,$dy);
    $sx = $x['inizio_dt'] ?? (($dx ?: ($x['data'] ?? '')).'T'.substr((string)($x['inizio'] ?? '00:00'),0,5).':00');
    $sy = $y['inizio_dt'] ?? (($dy ?: ($y['data'] ?? '')).'T'.substr((string)($y['inizio'] ?? '00:00'),0,5).':00');
    return strcmp((string)$sx,(string)$sy);
  });
}
unset($lista);

/* -------------------- Helpers -------------------- */
$ore_hhmm = function(int $min): string { return sprintf('%d.%02d', intdiv($min,60), $min%60); };
$fmtNome  = function(array $v): string {
  $grado = trim((string)($v['grado'] ?? ''));
  $cn    = trim(($v['cognome'] ?? '').' '.($v['nome'] ?? ''));
  return trim(($grado ? $grado.' ' : '').$cn);
};

// Quota annua: 5h/mese (esclude mesi con infortunio e mesi precedenti all’ingresso)
const QUOTA_MENSILE_MIN = 5 * 60;

// Mesi dell’anno corrente (YYYY-MM)
$mesiAnno = [];
for ($m=1; $m<=12; $m++) { $mesiAnno[] = sprintf('%04d-%02d', $anno, $m); }

// Calcola set mesi esenti per infortunio del vigile in questo anno
$mesi_esenti_infortunio = function(int $vid, array $ranges) use ($mesiAnno): array {
  $set = [];
  if (empty($ranges)) return $set;
  foreach ($mesiAnno as $ym) {
    $start = $ym.'-01';
    $dt = DateTime::createFromFormat('Y-m-d', $start);
    if (!$dt) continue;
    $end = $dt->format('Y-m-t');
    foreach ($ranges as $rng) {
      $dal = $rng[0] ?? ''; $al = $rng[1] ?? '';
      if ($al >= $start && $dal <= $end) { $set[$ym] = true; break; }
    }
  }
  return $set;
};

/* -------------------- TCPDF -------------------- */
class PDF extends TCPDF {
  public string $titolo1 = 'Comando Provinciale Vigili del Fuoco Torino';
  public string $titolo2 = 'Prestazione personale Volontario per addestramento';
  public string $titolo3 = 'Distaccamento';
  public string $periodo = '';
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

    if ($this->periodo !== '') {
      $this->Ln(2);
      $this->SetFont('helvetica','B',11);
      $this->Cell(0, 0, $this->periodo, 0, 1, 'C');
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
      $yTop = $sigBaseY - 4 - $h;
      if ($yTop < $m['top']) $yTop = $m['top'];
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
$pdf->periodo = 'ANNO '.$anno;
$pdf->titolo3 = 'Distaccamento di '.$casermaName;

$pdf->SetCreator('Sistema Ore Addestramento');
$pdf->SetAuthor('Sistema');
$pdf->SetTitle('Report annuale '.$anno.' — '.$casermaName);
$pdf->SetMargins(15, 48, 15);
$pdf->SetHeaderMargin(8);
$pdf->SetAutoPageBreak(true, 45);
$pdf->setPrintHeader(true);
$pdf->setPrintFooter(true);
$pdf->SetFont('helvetica','',10);
$pdf->notaLegale =
  "Il sottoscritto, consapevole che la dichiarazione mendace e la falsità in atti costituiscono reato ai sensi dell'art. 76 del D.P.R. 445/2000, ".
  "dichiara sotto la propria responsabilità di aver verificato che i nominativi in oggetto hanno partecipato agli addestramenti indicati.";

$pdf->AddPage();

/* -------------------- Box intestazione vigile -------------------- */
$print_vigile_box = function(PDF $pdf, string $nomeVigile, string $hhmmActual, string $hhmmQuota) {
  $box = '
  <table cellpadding="4" cellspacing="0" border="1" width="100%" style="margin-bottom:4px;">
    <tr style="background:#f8f9fa;">
      <td width="60%"><b>Vigile:</b> '.htmlspecialchars($nomeVigile, ENT_QUOTES, 'UTF-8').'</td>
      <td width="20%" align="right"><b>Ore/Quota:</b></td>
      <td width="20%" align="right"><b>'.$hhmmActual.' / '.$hhmmQuota.'</b></td>
    </tr>
  </table>';
  $pdf->writeHTML($box, true, false, true, false, '');
};

/* -------------------- Stampa vigile per vigile -------------------- */
foreach ($vigili as $v) {
  $vid      = (int)$v['id'];
  $lista    = $perVigile[$vid] ?? [];
  $nomeFull = $fmtNome($v);

  // Totale minuti nell'anno
  $totMin = 0;
  foreach ($lista as $r) { $totMin += $minutiRecord($r); }

  // Mesi richiesti: da ingresso (o prima attività nota) in poi, nell'anno; esclusi mesi con infortunio
  $ing = $v['data_ingresso'] ?? $v['ingresso'] ?? ($primaAttivitaGlob[$vid] ?? null);
  $ingMonth = $ing ? substr($ing, 0, 7) : sprintf('%04d-01', $anno);

  $mesiAnnoLoc = [];
  for ($m=1; $m<=12; $m++) { $mesiAnnoLoc[] = sprintf('%04d-%02d', $anno, $m); }
  $mesiRilevanti = array_filter($mesiAnnoLoc, fn($ym) => $ym >= $ingMonth);

  $setInfAnno    = $mesi_esenti_infortunio($vid, $INFORTUNI[$vid] ?? []);
  $mesiRichiesti = array_values(array_filter($mesiRilevanti, fn($ym) => empty($setInfAnno[$ym])));
  $nMesiQuota    = count($mesiRichiesti);

  $quotaMin      = $nMesiQuota * QUOTA_MENSILE_MIN;
  $hhmmActual    = $ore_hhmm($totMin);
  $hhmmQuota     = $ore_hhmm($quotaMin);

  // Stima spazio per box+tabella
  $rowsCount = max(1, count($lista));
  $theadH = 8; $rowH = 9; $tfootH = 8; $firmaH = 12; $sepH = 6; $fudge = 3;
  $estimatedTableH = $theadH + ($rowsCount*$rowH) + $tfootH + $firmaH + $sepH + $fudge;
  $estimatedBoxH   = 12;
  $bottomLimit = $pdf->getPageHeight() - $pdf->getBreakMargin();

  if ($pdf->GetY() + $estimatedBoxH + $estimatedTableH > $bottomLimit) {
    $pdf->AddPage();
  }

  // BOX
  $print_vigile_box($pdf, $nomeFull, $hhmmActual, $hhmmQuota);

  // Tabella attività
  $rowsHtml = '';
  if (empty($lista)) {
    $gradoOrig   = htmlspecialchars($v['grado']   ?? '', ENT_QUOTES, 'UTF-8');
    $cognomeOrig = htmlspecialchars($v['cognome'] ?? '', ENT_QUOTES, 'UTF-8');
    $nomeOrig    = htmlspecialchars($v['nome']    ?? '', ENT_QUOTES, 'UTF-8');

    $rowsHtml .= '<tr>
      <td width="10%">'.$gradoOrig.'</td>
      <td width="25%">'.$cognomeOrig.'</td>
      <td width="25%">'.$nomeOrig.'</td>
      <td width="15%" align="C">—</td>
      <td width="15%" align="C">—</td>
      <td width="10%" align="R">'.$ore_hhmm(0).'</td>
    </tr>';
  } else {
    $gradoOrig   = htmlspecialchars($v['grado']   ?? '', ENT_QUOTES, 'UTF-8');
    $cognomeOrig = htmlspecialchars($v['cognome'] ?? '', ENT_QUOTES, 'UTF-8');
    $nomeOrig    = htmlspecialchars($v['nome']    ?? '', ENT_QUOTES, 'UTF-8');

    $grado=$gradoOrig; $cognome=$cognomeOrig; $nome=$nomeOrig; $first=true;
    foreach ($lista as $r) {
      $data = htmlspecialchars((string)($r['_d_'] ?? ($r['data'] ?? '')), ENT_QUOTES, 'UTF-8');
      $iHH  = $r['inizio'] ?? substr((string)($r['inizio_dt'] ?? ''),11,5);
      $fHH  = $r['fine']   ?? substr((string)($r['fine_dt']   ?? ''),11,5);
      $iHH  = htmlspecialchars((string)$iHH, ENT_QUOTES, 'UTF-8');
      $fHH  = htmlspecialchars((string)$fHH, ENT_QUOTES, 'UTF-8');
      $min  = $minutiRecord($r);

      $rowsHtml .= '<tr>
        <td width="10%">'.$grado.'</td>
        <td width="25%">'.$cognome.'</td>
        <td width="25%">'.$nome.'</td>
        <td width="15%" align="C">'.$data.'</td>
        <td width="15%" align="C">'.$iHH.' – '.$fHH.'</td>
        <td width="10%" align="R">'.$ore_hhmm($min).'</td>
      </tr>';

      if ($first) { $grado = $cognome = $nome = ''; $first = false; }
    }
  }

  $htmlTable = '
  <table cellpadding="4" cellspacing="0" border="1" width="100%">
    <thead>
      <tr style="background:#f2f2f2; font-weight:bold;">
        <th width="10%">GRADO</th>
        <th width="25%">COGNOME</th>
        <th width="25%">NOME</th>
        <th width="15%">DATA</th>
        <th width="15%">ORARIO</th>
        <th width="10%" align="right">ORE</th>
      </tr>
    </thead>
    <tbody>'.$rowsHtml.'</tbody>
    <tfoot>
      <tr>
        <td colspan="5" align="right" style="font-weight:bold;">Totale annuo</td>
        <td align="right" style="font-weight:bold;">'.$ore_hhmm($totMin).'</td>
      </tr>
    </tfoot>
  </table>';

  $bottomLimit = $pdf->getPageHeight() - $pdf->getBreakMargin();
  if ($pdf->GetY() + $estimatedTableH > $bottomLimit) { $pdf->AddPage(); }

  $pdf->writeHTML($htmlTable, true, false, true, false, '');

  // Firma e separatore
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

if (ob_get_length()) { @ob_end_clean(); }
$pdf->Output('report_annuale_'.$anno.'.pdf', 'I');