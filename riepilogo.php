<?php
// riepilogo.php — vista riepilogo mensile/annuale addestramenti
ob_start();

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__.'/auth.php';
require_once __DIR__.'/tenant_bootstrap.php';
require_tenant_user();

require_once __DIR__.'/storage.php';
require_once __DIR__.'/utils.php';

if (function_exists('require_perm')) {
  require_perm('view:riepilogo');
  require_perm('edit:riepilogo');
  require_perm('export:pdf');
}

/* ============================================================
   Fallback MULTITENANT: se mancano costanti/funzioni, le creo.
   ============================================================ */

// tenant_active_slug fallback
if (!function_exists('tenant_active_slug')) {
  function tenant_active_slug(): string {
    return (string)($_SESSION['tenant_slug'] ?? $_SESSION['CASERMA_SLUG'] ?? 'default');
  }
}

// Base dati: /data/<slug> come in caserme.php
if (!defined('DATA_CASERME_BASE')) {
  define('DATA_CASERME_BASE', __DIR__ . '/data');
}
$__slug = preg_replace('/[^a-z0-9_-]/i', '', tenant_active_slug());
$__dir  = rtrim(DATA_CASERME_BASE,'/').'/'.$__slug;

if (!defined('DATA_DIR')) {
  define('DATA_DIR', $__dir);
}

// JSON path di default (se non definiti altrove) — tenant-aware
if (!defined('VIGILI_JSON'))     define('VIGILI_JSON',     rtrim(DATA_DIR,'/').'/vigili.json');
if (!defined('ADDESTR_JSON'))    define('ADDESTR_JSON',    rtrim(DATA_DIR,'/').'/addestramenti.json');
if (!defined('INFORTUNI_JSON'))  define('INFORTUNI_JSON',  rtrim(DATA_DIR,'/').'/infortuni.json');
// caserme.json resta a livello base
if (!defined('CASERME_JSON'))    define('CASERME_JSON',    __DIR__.'/data/caserme.json');

// load_json/save_json fallback (quando storage.php non li fornisce)
if (!function_exists('load_json')) {
  function load_json(string $pathOrName): array {
    // accetta sia path assoluto che solo nome file
    $p = $pathOrName;
    if (!str_contains($p, '/')) {
      $p = rtrim(DATA_DIR,'/').'/'.$p;
    }
    if (!is_file($p)) return [];
    $s = @file_get_contents($p);
    $j = $s!==false ? json_decode($s, true) : null;
    return is_array($j) ? $j : [];
  }
}
if (!function_exists('save_json')) {
  function save_json(string $pathOrName, array $data): void {
    $p = $pathOrName;
    if (!str_contains($p, '/')) {
      $p = rtrim(DATA_DIR,'/').'/'.$p;
    }
    $dir = dirname($p);
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    $json = json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
    if ($json === false) throw new RuntimeException('JSON encoding fallita: '.json_last_error_msg());
    $tmp = $p.'.tmp-'.bin2hex(random_bytes(6));
    if (file_put_contents($tmp, $json) === false) throw new RuntimeException('Scrittura temporanea fallita: '.$tmp);
    if (!@rename($tmp, $p)) { @unlink($tmp); throw new RuntimeException('Rename atomico fallito verso: '.$p); }
    @chmod($p, 0664);
  }
}

// minuti_da_intervallo_datetime (se utils.php non l’ha messa)
if (!function_exists('minuti_da_intervallo_datetime')) {
  function minuti_da_intervallo_datetime(string $inizioDT, string $fineDT): int {
    $a = DateTime::createFromFormat(DateTimeInterface::ATOM, $inizioDT) ?: new DateTime($inizioDT);
    $b = DateTime::createFromFormat(DateTimeInterface::ATOM, $fineDT)   ?: new DateTime($fineDT);
    if (!$a || !$b) return 0;
    return max(0, (int) round(($b->getTimestamp() - $a->getTimestamp()) / 60));
  }
}

/* ============================================================
   Fallback load_caserme se non esiste
   ============================================================ */
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
      if (is_file($dir.'/users.json') && !isset($out[$slug])) {
        $out[$slug] = ['slug'=>$slug, 'name'=>$slug];
      }
    }

    if (empty($out)) $out['default'] = ['slug'=>'default','name'=>'Default'];
    uasort($out, fn($a,$b)=> strcasecmp($a['name'],$b['name']));
    return array_values($out);
  }
}

/* ======= Costanti soglie ======= */
const QUOTA_MENSILE_MIN = 5 * 60;   // 5 ore/mese
const BANCA_SOGLIA_MIN  = 60 * 60;  // 60 ore (per banca 12 mesi)

/* ======= Vista ======= */
$tipo = ($_GET['tipo'] ?? 'annuale') === 'mensile' ? 'mensile' : 'annuale';

/* ======= Opzione: scala ore di recupero (default ON) ======= */
$scalaRec = (isset($_GET['scala_rec']) ? (string)$_GET['scala_rec'] : '1') === '1';

/* ======= Riferimenti temporali ======= */
$oggi = new DateTimeImmutable('now', new DateTimeZone(date_default_timezone_get()));

// Per coerenza col report operatività, anche "annuale" usa un mese di riferimento
$meseStr = $_GET['mese'] ?? $oggi->format('Y-m'); // YYYY-MM
if (!preg_match('/^\d{4}-\d{2}$/', $meseStr)) $meseStr = $oggi->format('Y-m');

// Compat: anno solo per UI
$anno = isset($_GET['anno']) ? (int)$_GET['anno'] : (int)$oggi->format('Y');

// Boundaries del MESE selezionato
$rifYmDT = DateTime::createFromFormat('Y-m-d', substr($meseStr,0,4).'-'.substr($meseStr,5,2).'-01');
$monthStart = $rifYmDT ? $rifYmDT->format('Y-m-d') : date('Y-m-01');
$monthEnd   = $rifYmDT ? (clone $rifYmDT)->modify('last day of this month')->format('Y-m-d') : date('Y-m-t');

/* ======= Dati JSON (tenant-aware) ======= */
$vigili = array_values(array_filter(load_json(VIGILI_JSON), fn($v)=> (int)($v['attivo'] ?? 1) === 1));
$add    = load_json(ADDESTR_JSON);

// Infortuni → mappa [vid] => [[dal, al], ...]
$infRaw = load_json(INFORTUNI_JSON);
$INFORTUNI = [];
if (is_array($infRaw)) {
  foreach ($infRaw as $row) {
    $vid = (int)($row['vigile_id'] ?? 0);
    $dal = (string)($row['dal'] ?? '');
    $al  = (string)($row['al'] ?? '');
    if ($vid && preg_match('/^\d{4}-\d{2}-\d{2}$/',$dal) && preg_match('/^\d{4}-\d{2}-\d{2}$/',$al) && $al >= $dal) {
      $INFORTUNI[$vid][] = [$dal, $al];
    }
  }
}

/* ======= Helpers ======= */
$minutiRecord = function(array $r): int {
  if (isset($r['minuti']) && $r['minuti'] !== '') return (int)$r['minuti'];
  $data = (string)($r['data'] ?? '');
  $inizioDT = $r['inizio_dt'] ?? ($data ? ($data.'T'.substr((string)($r['inizio'] ?? '00:00'),0,5).':00') : null);
  $fineDT   = $r['fine_dt']   ?? ($data ? ($data.'T'.substr((string)($r['fine']   ?? '00:00'),0,5).':00') : null);
  if (!$inizioDT || !$fineDT) return 0;
  try { return minuti_da_intervallo_datetime($inizioDT, $fineDT); } catch(Throwable $e) { return 0; }
};

$estraiData = function(array $r): string {
  if (!empty($r['data'])) return (string)$r['data'];
  if (!empty($r['inizio_dt']) && preg_match('/^\d{4}-\d{2}-\d{2}T/',$r['inizio_dt'])) {
    return substr($r['inizio_dt'], 0, 10);
  }
  return '';
};

if (!function_exists('h_ore_min')) {
  function h_ore_min(int $min): string { return sprintf('%d:%02d', intdiv($min,60), $min%60); }
}

/* ======= Periodo di calcolo ======= */
if ($tipo === 'annuale') {
  $rifY = (int)substr($meseStr,0,4);
  $rifM = (int)substr($meseStr,5,2);
  $startRef = DateTime::createFromFormat('Y-m-d', sprintf('%04d-%02d-01', $rifY, $rifM)) ?: new DateTime(date('Y-m-01'));
  $winStart = (clone $startRef)->modify('-1 year'); // incluso
  $winEnd   = (clone $startRef)->modify('-1 day');  // incluso
  $periodStart = $winStart->format('Y-m-d');
  $periodEnd   = $winEnd->format('Y-m-d');
  $titolo = "Riepilogo annuale (ultimi 12 mesi: {$periodStart} → {$periodEnd})";
} else {
  $rifY = (int)substr($meseStr,0,4);
  $rifM = (int)substr($meseStr,5,2);
  $dt0  = DateTime::createFromFormat('Y-m-d', sprintf('%04d-%02d-01', $rifY, $rifM)) ?: new DateTime(date('Y-m-01'));
  $dt1  = (clone $dt0)->modify('last day of this month');
  $periodStart = $dt0->format('Y-m-d');
  $periodEnd   = $dt1->format('Y-m-d');
  $titolo = "Riepilogo mensile ($meseStr)";
}

/* ======= Accumulatori vista corrente ======= */
$sessioniPerVigile   = [];
$minLordoPerVigile   = [];
$minNonRecPerVigile  = [];
$minRecPerVigile     = [];

/* ======= Variabili per vista annuale (finestra 12 mesi) ======= */
$mesiFinestra = [];
$primaAttInFinestra = [];
$mesiInfByVid = [];

if ($tipo === 'annuale') {
  $cur = DateTime::createFromFormat('Y-m-d', $periodStart)->modify('first day of this month');
  $end = DateTime::createFromFormat('Y-m-d', $periodEnd)->modify('first day of next month');
  while ($cur < $end) {
    $mesiFinestra[] = $cur->format('Y-m');
    $cur->modify('+1 month');
  }

  foreach ($add as $r) {
    $vid = (int)($r['vigile_id'] ?? 0);
    $d   = $estraiData($r);
    if (!$vid || $d==='') continue;
    if ($d >= $periodStart && $d <= $periodEnd) {
      $min = $minutiRecord($r);
      $isRec = (int)($r['recupero'] ?? 0) === 1;

      $minLordoPerVigile[$vid]  = ($minLordoPerVigile[$vid]  ?? 0) + $min;
      if ($isRec) $minRecPerVigile[$vid]    = ($minRecPerVigile[$vid]    ?? 0) + $min;
      else        $minNonRecPerVigile[$vid] = ($minNonRecPerVigile[$vid] ?? 0) + $min;

      $sessioniPerVigile[$vid] = ($sessioniPerVigile[$vid] ?? 0) + 1;
      if (!isset($primaAttInFinestra[$vid]) || $d < $primaAttInFinestra[$vid]) $primaAttInFinestra[$vid] = $d;
    }
  }

  foreach ($INFORTUNI as $vid => $ranges) {
    $set = [];
    foreach ($ranges as [$dal, $al]) {
      if ($al < $periodStart || $dal > $periodEnd) continue;
      $from = max($dal, $periodStart);
      $to   = min($al,  $periodEnd);
      $p = DateTime::createFromFormat('Y-m-d', substr($from,0,7).'-01');
      $lastMonth = substr($to,0,7);
      while ($p->format('Y-m') <= $lastMonth) {
        $set[$p->format('Y-m')] = true;
        $p->modify('+1 month');
      }
    }
    $mesiInfByVid[$vid] = $set;
  }
} else {
  foreach ($add as $a) {
    $d = $estraiData($a);
    if ($d === '' || $d < $periodStart || $d > $periodEnd) continue;
    $vid = (int)($a['vigile_id'] ?? 0);
    if ($vid <= 0) continue;

    $minuti = $minutiRecord($a);
    $isRec  = (int)($a['recupero'] ?? 0) === 1;

    $minLordoPerVigile[$vid]  = ($minLordoPerVigile[$vid]  ?? 0) + $minuti;
    if ($isRec) $minRecPerVigile[$vid]    = ($minRecPerVigile[$vid]    ?? 0) + $minuti;
    else        $minNonRecPerVigile[$vid] = ($minNonRecPerVigile[$vid] ?? 0) + $minuti;

    $sessioniPerVigile[$vid] = ($sessioniPerVigile[$vid] ?? 0) + 1;
  }
}

/* ======= BANCA 12 mesi (sempre) ======= */
$rifYB = (int)substr($meseStr,0,4);
$rifMB = (int)substr($meseStr,5,2);
$refB  = DateTime::createFromFormat('Y-m-d', sprintf('%04d-%02d-01', $rifYB, $rifMB)) ?: new DateTime(date('Y-m-01'));
$bankStart = (clone $refB)->modify('-1 year')->format('Y-m-d'); // incluso
$bankEnd   = (clone $refB)->modify('-1 day')->format('Y-m-d');  // incluso

$nr12 = []; $rc12 = []; $recCount12 = [];
foreach ($add as $r) {
  $vid = (int)($r['vigile_id'] ?? 0);
  $d   = $estraiData($r);
  if (!$vid || $d==='') continue;
  if ($d < $bankStart || $d > $bankEnd) continue;

  $min = $minutiRecord($r);
  if ((int)($r['recupero'] ?? 0) === 1) {
    $rc12[$vid] = ($rc12[$vid] ?? 0) + $min;
    $recCount12[$vid] = ($recCount12[$vid] ?? 0) + 1;
  } else {
    $nr12[$vid] = ($nr12[$vid] ?? 0) + $min;
  }
}
$banca12 = [];
foreach ($vigili as $v) {
  $vid = (int)($v['id'] ?? 0);
  $nr  = (int)($nr12[$vid] ?? 0);
  $rc  = (int)($rc12[$vid] ?? 0);
  $ex  = max(0, $nr - BANCA_SOGLIA_MIN);
  $bank= $ex - $rc;
  if ($bank < 0) $bank = 0;
  $banca12[$vid] = $bank;
}

/* ======= Conteggio RECUPERI del MESE ======= */
$recCountMonth = [];
foreach ($add as $r) {
  $vid = (int)($r['vigile_id'] ?? 0);
  $d   = $estraiData($r);
  if (!$vid || $d==='') continue;
  if ($d >= $monthStart && $d <= $monthEnd && (int)($r['recupero'] ?? 0) === 1) {
    $recCountMonth[$vid] = ($recCountMonth[$vid] ?? 0) + 1;
  }
}

// Ordina anagrafe
usort($vigili, fn($a,$b)=>strcasecmp(($a['cognome']??'').' '.($a['nome']??''), ($b['cognome']??'').' '.($b['nome']??'')));

// Querystring export coerente
$qsBase = ($tipo==='annuale')
      ? 'tipo=annuale&mese='.urlencode($meseStr)
      : 'tipo=mensile&mese='.urlencode($meseStr);
$qs = $qsBase.'&scala_rec='.($scalaRec?'1':'0').'&export=csv';

// Dati tenant per default oggetto/corpo email (modale)
$slug = tenant_active_slug();
$casermaName = 'Default';
foreach (load_caserme() as $c) {
  if (($c['slug'] ?? '') === $slug) { $casermaName = $c['name'] ?? $slug; break; }
}
$defaultSubject = 'Invio riepilogo mensile ore addestramento  '.$meseStr.' — '.$casermaName;
$defaultBody    = "Buongiorno\n\ncon la presente sono ad inviare in allegato il PDF del riepilogo mensile degli addestramenti per il mese $meseStr.\n\nDistaccamento: $casermaName\n\nCordiali saluti\n\nil Capo Distaccamento di $casermaName";
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo htmlspecialchars($titolo); ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<?php if (is_file(__DIR__.'/_menu.php')) include __DIR__.'/_menu.php'; ?>

<div class="container py-4">

  <!-- TOOLBAR -->
  <div class="sticky-top bg-white border rounded-3 p-2 mb-3" style="top:.5rem; box-shadow:0 2px 8px rgba(0,0,0,.03);">
    <div class="d-flex flex-wrap align-items-center gap-2">
      <a class="btn btn-outline-dark" href="index.php">Inserimento ▶</a>
      <a class="btn btn-outline-primary" href="addestramenti.php">Dettagli e Modifiche ✎</a>

      <div class="btn-group ms-2" role="group" aria-label="Vista">
        <a class="btn btn-sm <?php echo $tipo==='annuale'?'btn-primary':'btn-outline-primary'; ?>"
           href="?tipo=annuale&mese=<?php echo htmlspecialchars($meseStr); ?>&scala_rec=<?php echo $scalaRec?'1':'0'; ?>">Annuale</a>
        <a class="btn btn-sm <?php echo $tipo==='mensile'?'btn-primary':'btn-outline-primary'; ?>"
           href="?tipo=mensile&mese=<?php echo htmlspecialchars($meseStr); ?>&scala_rec=<?php echo $scalaRec?'1':'0'; ?>">Mensile</a>
      </div>

      <form class="d-flex align-items-center gap-2 ms-2" method="get">
        <input type="hidden" name="tipo" value="<?php echo htmlspecialchars($tipo); ?>">
        <input type="hidden" name="scala_rec" value="<?php echo $scalaRec?'1':'0'; ?>">
        <label for="meseTop" class="form-label mb-0 fw-semibold"><?php echo $tipo==='annuale'?'Mese di riferimento':'Mese'; ?></label>
        <input id="meseTop" type="month" class="form-control form-control-sm" name="mese"
               value="<?php echo htmlspecialchars($meseStr); ?>">
        <button class="btn btn-sm btn-outline-primary">Vai</button>
      </form>

      <form class="ms-2" method="get">
        <input type="hidden" name="tipo" value="<?php echo htmlspecialchars($tipo); ?>">
        <input type="hidden" name="mese" value="<?php echo htmlspecialchars($meseStr); ?>">
        <input type="hidden" name="scala_rec" value="0">
        <div class="form-check form-switch d-flex align-items-center">
          <input
            class="form-check-input"
            type="checkbox"
            role="switch"
            id="swRec"
            name="scala_rec"
            value="1"
            <?php echo $scalaRec ? 'checked' : ''; ?>
            onchange="this.form.submit()"
          >
          <label class="form-check-label ms-1" for="swRec">Scala ore di recupero</label>
        </div>
      </form>

      <div class="ms-auto d-flex align-items-center gap-2">
  <!-- PDF OPERATIVITÀ con esclusioni -->
  <button type="submit"
          form="form-escludi"
          formaction="report_operativita_tcpdf.php"
          formmethod="get"
          formtarget="_blank"
          class="btn btn-sm btn-danger fw-semibold">
    PDF operatività Distaccamento
  </button>

  <!-- PDF MENSILE con esclusioni -->
  <button type="submit"
          form="form-escludi"
          formaction="report_mensile_tcpdf.php"
          formmethod="get"
          formtarget="_blank"
          class="btn btn-sm btn-danger fw-semibold">
    PDF mensile 
  </button>

  <!-- PDF ANNUALE: resta un form separato -->
  <form action="report_annuale_tcpdf.php" method="get" target="_blank" class="d-flex align-items-center gap-2">
    <input type="hidden" name="anno" value="<?php echo (int)substr($meseStr,0,4); ?>">
    <input type="hidden" name="includi_vuoti" value="1">
    <button type="submit" class="btn btn-sm btn-danger fw-semibold">PDF annuale</button>
  </form>

  <?php if ($tipo==='mensile'): ?>
    <button class="btn btn-sm btn-success fw-semibold" data-bs-toggle="modal" data-bs-target="#sendPdfModal">
      Invia PDF mensile ✉️
    </button>
  <?php endif; ?>
</div>
    </div>
  </div>

  <h1 class="mb-3"><?php echo htmlspecialchars($titolo); ?></h1>

  <?php if ($tipo==='annuale'): ?>
    <div class="alert alert-info mt-2">
      Calcolo su <strong>ultimi 12 mesi</strong> fino al <em>mese precedente</em> al riferimento (<code><?php echo htmlspecialchars($meseStr); ?></code>).
      La soglia è <strong>5h per ciascun mese</strong> rilevante: si escludono i mesi antecedenti all’ingresso e i mesi con <em>infortunio/sospensione</em>.
      <?php if ($scalaRec): ?>
        <br>Visualizzazione: <strong>ore nette</strong> (non-recupero − recupero).
      <?php else: ?>
        <br>Visualizzazione: <strong>ore lorde</strong> (tutte le ore).
      <?php endif; ?>
      <hr class="my-2">
      <span class="d-inline-block">
        <strong>Nota operativa:</strong>
        se l’elenco dei vigili non è completo, verifica lo stato
        <em>Visualizzato/Non visualizzato</em> nella pagina <em>Gestione Vigili</em>.
      </span>
      <a class="btn btn-sm btn-outline-primary ms-2 align-text-bottom" href="https://allerto.it/vigili.php" target="_blank" rel="noopener">Apri Gestione Vigili</a>
    </div>
  <?php else: ?>
    <div class="alert alert-info mt-2">
      Vista del <strong>mese</strong> selezionato. La soglia è <strong>5h</strong>, con eventuale <em>prorata</em> se presenti giorni di infortunio nel mese.
      <?php if ($scalaRec): ?>
        <br>Visualizzazione: <strong>ore nette</strong> (non-recupero − recupero).
      <?php else: ?>
        <br>Visualizzazione: <strong>ore lorde</strong> (tutte le ore).
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php
    // Calcoli finali per tabella
    $rifY = (int)substr($meseStr,0,4);
    $rifM = (int)substr($meseStr,5,2);
    if ($tipo==='annuale') {
      $startRef = DateTime::createFromFormat('Y-m-d', sprintf('%04d-%02d-01', $rifY, $rifM)) ?: new DateTime(date('Y-m-01'));
      $periodStart = (clone $startRef)->modify('-1 year')->format('Y-m-d');
      $periodEnd   = (clone $startRef)->modify('-1 day')->format('Y-m-d');

      $mesiFinestra = [];
      $cur = DateTime::createFromFormat('Y-m-d', $periodStart)->modify('first day of this month');
      $end = DateTime::createFromFormat('Y-m-d', $periodEnd)->modify('first day of next month');
      while ($cur < $end) { $mesiFinestra[] = $cur->format('Y-m'); $cur->modify('+1 month'); }
    } else {
      $dt0  = DateTime::createFromFormat('Y-m-d', sprintf('%04d-%02d-01', $rifY, $rifM)) ?: new DateTime(date('Y-m-01'));
      $dt1  = (clone $dt0)->modify('last day of this month');
      $periodStart = $dt0->format('Y-m-d');
      $periodEnd   = $dt1->format('Y-m-d');
    }

    $daysBetween = function(string $A, string $B): int {
      $dA = DateTime::createFromFormat('Y-m-d', $A);
      $dB = DateTime::createFromFormat('Y-m-d', $B);
      if (!$dA || !$dB) return 0;
      if ($dB < $dA) return 0;
      return (int)$dA->diff($dB)->days + 1;
    };
    $overlapDays = function(string $aStart, string $aEnd, string $bStart, string $bEnd) use ($daysBetween): int {
      $start = max($aStart, $bStart);
      $end   = min($aEnd,   $bEnd);
      if ($end < $start) return 0;
      return $daysBetween($start, $end);
    };
    $soglia_prorata_min = function(int $baseMin, array $rangesInfortunio, string $pStart, string $pEnd) use ($overlapDays, $daysBetween): int {
      $totDays = $daysBetween($pStart, $pEnd);
      if ($totDays <= 0) return 0;
      $infDays = 0;
      foreach ($rangesInfortunio as $r) {
        $dal = $r[0] ?? null; $al = $r[1] ?? null;
        if (!$dal || !$al) continue;
        $infDays += $overlapDays($pStart, $pEnd, $dal, $al);
      }
      if ($infDays > $totDays) $infDays = $totDays;
      $attivi = $totDays - $infDays;
      $ratio  = ($attivi <= 0) ? 0.0 : ($attivi / $totDays);
      $effMin = (int) round($baseMin * $ratio);
      return max(0, min($effMin, $baseMin));
    };
  ?>

  <!-- ********** INIZIO FORM CHE AVVOLGE LA TABELLA ********** -->
  <form id="form-escludi" method="get" action="report_mensile_tcpdf.php" target="_blank">
    <input type="hidden" name="mese" value="<?php echo htmlspecialchars($meseStr); ?>">
    <input type="hidden" name="includi_vuoti" value="1">

    <table class="table table-striped align-middle mt-3">
      <thead>
        <tr>
          <th style="width:42px;">
            Escludi<br>
            <input type="checkbox" id="checkAll" onclick="
              const cbs = document.querySelectorAll('input[name=&quot;exclude[]&quot;]');
              for (const cb of cbs) cb.checked = this.checked;
            ">
          </th>
          <th>#</th>
          <th>Vigile</th>
          <th>Addestramenti</th>
          <th>Recuperi (mese/12m)</th>
          <th>Totale svolto (h:mm)<?php echo $scalaRec?' <span class=&quot;badge text-bg-light&quot;>NETTO</span>':''; ?></th>
          <th>Banca ore (ultimi 12 mesi)</th>
          <th>Soglia effettiva</th>
          <th>Stato</th>
        </tr>
      </thead>
      <tbody>
        <?php
        $iRow = 0;

        // Prepara banche/recuperi conteggio (già calcolati sopra)
        $rifYB = (int)substr($meseStr,0,4);
        $rifMB = (int)substr($meseStr,5,2);
        $refB  = DateTime::createFromFormat('Y-m-d', sprintf('%04d-%02d-01', $rifYB, $rifMB)) ?: new DateTime(date('Y-m-01'));
        $bankStart = (clone $refB)->modify('-1 year')->format('Y-m-d');
        $bankEnd   = (clone $refB)->modify('-1 day')->format('Y-m-d');

        foreach ($vigili as $v):
          $vid = (int)($v['id'] ?? 0);
          $nomeCompleto = trim(($v['cognome'] ?? '').' '.($v['nome'] ?? ''));

          $lordo = (int)($minLordoPerVigile[$vid] ?? 0);
          $netto = max(0, (int)($minNonRecPerVigile[$vid] ?? 0) - (int)($minRecPerVigile[$vid] ?? 0));
          $nSess = (int)($sessioniPerVigile[$vid] ?? 0);

          $bancaMin = (int)($banca12[$vid] ?? 0);

          $nRecM = (int)($recCountMonth[$vid] ?? 0);
          $nRecY = (int)($recCount12[$vid] ?? 0);

          if ($tipo==='annuale') {
            $ing = $v['data_ingresso'] ?? $v['ingresso'] ?? ($primaAttInFinestra[$vid] ?? null);
            $ingMonth = $ing ? substr($ing, 0, 7) : ($mesiFinestra[0] ?? substr($periodStart,0,7));
            $mesiRilevanti = array_filter($mesiFinestra, fn($ym) => $ym >= $ingMonth);

            $setInf   = $mesiInfByVid[$vid] ?? [];
            $mesiRich = array_values(array_filter($mesiRilevanti, fn($ym) => empty($setInf[$ym])));
            $nMesiQuota = count($mesiRich);

            $sogliaEffMin = $nMesiQuota * QUOTA_MENSILE_MIN;
            $actualMin    = $scalaRec ? $netto : $lordo;
            $ok = ($nMesiQuota > 0) && ($actualMin >= $sogliaEffMin);

          } else {
            $ranges       = $INFORTUNI[$vid] ?? [];
            $sogliaEffMin = $soglia_prorata_min(QUOTA_MENSILE_MIN, $ranges, $periodStart, $periodEnd);
            $actualMin    = $scalaRec ? $netto : $lordo;
            $ok = ($actualMin >= $sogliaEffMin);
          }
        ?>
        <tr>
          <td>
            <input type="checkbox" name="exclude[]" value="<?php echo $vid; ?>">
          </td>
          <td><?php echo ++$iRow; ?></td>
          <td>
            <?php if ($tipo==='mensile'): ?>
              <a href="dettaglio.php?vigile=<?php echo $vid; ?>&mese=<?php echo urlencode($meseStr); ?>">
                <?php echo htmlspecialchars($nomeCompleto); ?>
              </a>
            <?php else: ?>
              <a href="dettaglio_annuale.php?vigile=<?php echo $vid; ?>&anno=<?php echo (int)substr($meseStr,0,4); ?>">
                <?php echo htmlspecialchars($nomeCompleto); ?>
              </a>
            <?php endif; ?>
          </td>
          <td><?php echo $nSess; ?></td>
          <td>
            <?php if ($nRecM || $nRecY): ?>
              <span class="badge text-bg-warning"><?php echo $nRecM.' / '.$nRecY; ?></span>
            <?php else: ?>
              <span class="badge text-bg-secondary">0 / 0</span>
            <?php endif; ?>
          </td>
          <td>
            <?php
              $actualMin = $scalaRec ? max(0, ($minNonRecPerVigile[$vid] ?? 0) - ($minRecPerVigile[$vid] ?? 0))
                                     : ($minLordoPerVigile[$vid] ?? 0);
            echo h_ore_min((int)$actualMin);
            ?>
          </td>
          <td><span class="badge text-bg-light"><?php echo h_ore_min($bancaMin); ?></span></td>
          <td><span class="badge text-bg-secondary"><?php echo h_ore_min($sogliaEffMin); ?></span></td>
          <td>
            <?php if ($ok): ?>
              <span class="badge text-bg-success">OK</span>
            <?php else: ?>
              <span class="badge text-bg-danger">Sotto soglia</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>

        <?php if (empty($vigili)): ?>
          <tr><td colspan="9" class="text-muted">Nessun vigile attivo.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </form>
  <!-- ********** FINE FORM ********** -->

  <div class="mt-3">
    <a class="btn btn-sm btn-outline-secondary" href="?<?php echo htmlspecialchars($qs); ?>">Esporta CSV</a>
  </div>
</div>

<?php if ($tipo==='mensile'): ?>
<!-- MODALE: invio PDF mensile via email -->
<div class="modal fade" id="sendPdfModal" tabindex="-1" aria-labelledby="sendPdfLabel" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="post" action="send_riepilogo_mensile.php" target="_blank">
      <div class="modal-header">
        <h5 class="modal-title" id="sendPdfLabel">Invia PDF riepilogo mensile</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="mese" value="<?php echo htmlspecialchars($meseStr, ENT_QUOTES); ?>">
        <div class="mb-3">
          <label class="form-label">Destinatari</label>
          <input type="text" name="to" class="form-control" placeholder="es. capo@example.it, segreteria@example.it" required>
          <div class="form-text">Separali con virgola, spazio o punto e virgola.</div>
        </div>
        <div class="mb-3">
          <label class="form-label">Oggetto</label>
          <input type="text" name="subject" class="form-control" value="<?php echo htmlspecialchars($defaultSubject, ENT_QUOTES); ?>">
        </div>
        <div class="mb-2">
          <label class="form-label">Messaggio</label>
          <textarea name="body" class="form-control" rows="4"><?php echo htmlspecialchars($defaultBody, ENT_QUOTES); ?></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annulla</button>
        <button type="submit" class="btn btn.success">Invia</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php
/* ======= Export CSV coerente con la tabella ======= */
if (isset($_GET['export']) && $_GET['export']==='csv') {
  if (ob_get_length()) { @ob_end_clean(); }

  header('Content-Type: text/csv; charset=utf-8');
  $fname = ($tipo==='annuale')
           ? "riepilogo_finestra12_{$meseStr}".($scalaRec?'_NETTO':'_LORDO').".csv"
           : "riepilogo_{$meseStr}".($scalaRec?'_NETTO':'_LORDO').".csv";
  header('Content-Disposition: attachment; filename="'.$fname.'"');
  $out = fopen('php://output', 'w');

  fputcsv($out, [
    '#','Cognome','Nome','Addestramenti',
    'Recuperi_mese','Recuperi_12m',
    'Totale(min)','Totale(h:mm)',
    'Banca12m(min)','Banca12m(h:mm)',
    'SogliaEff(min)','SogliaEff(h:mm)','Stato','Vista'
  ]);
  $vistaLbl = $scalaRec ? 'NETTO (non-recupero − recupero)' : 'LORDO';

  // riciclo variabili/utility già calcolate sopra
  $i = 0;
  foreach ($vigili as $v) {
    $vid  = (int)($v['id'] ?? 0);
    $nome = (string)($v['nome'] ?? '');
    $cogn = (string)($v['cognome'] ?? '');

    $lordo = (int)($minLordoPerVigile[$vid] ?? 0);
    $netto = max(0, (int)($minNonRecPerVigile[$vid] ?? 0) - (int)($minRecPerVigile[$vid] ?? 0));
    $nSess = (int)($sessioniPerVigile[$vid] ?? 0);
    $bancaMin = (int)($banca12[$vid] ?? 0);

    $nRecM = (int)($recCountMonth[$vid] ?? 0);
    $nRecY = (int)($recCount12[$vid] ?? 0);

    if ($tipo==='annuale') {
      $ing = $v['data_ingresso'] ?? $v['ingresso'] ?? ($primaAttInFinestra[$vid] ?? null);
      $ingMonth = $ing ? substr($ing, 0, 7) : ($mesiFinestra[0] ?? substr($periodStart,0,7));
      $mesiRilevanti = array_filter($mesiFinestra, fn($ym) => $ym >= $ingMonth);

      $setInf   = $mesiInfByVid[$vid] ?? [];
      $mesiRich = array_values(array_filter($mesiRilevanti, fn($ym) => empty($setInf[$ym])));
      $nMesiQuota = count($mesiRich);

      $sogliaEffMin = $nMesiQuota * QUOTA_MENSILE_MIN;
      $actualMin    = $scalaRec ? $netto : $lordo;
      $ok = ($nMesiQuota > 0) && ($actualMin >= $sogliaEffMin);

      fputcsv($out, [
        ++$i, $cogn, $nome, $nSess,
        $nRecM, $nRecY,
        $actualMin, h_ore_min($actualMin),
        $bancaMin,  h_ore_min($bancaMin),
        $sogliaEffMin, h_ore_min($sogliaEffMin),
        $ok ? 'OK' : 'SOTTO', $vistaLbl
      ]);

    } else {
      // mensile
      // per CSV ricalcolo rapidamente la soglia mensile prorata
      $daysBetween = function(string $A, string $B): int {
        $dA = DateTime::createFromFormat('Y-m-d', $A);
        $dB = DateTime::createFromFormat('Y-m-d', $B);
        if (!$dA || !$dB) return 0;
        if ($dB < $dA) return 0;
        return (int)$dA->diff($dB)->days + 1;
      };
      $overlapDays = function(string $aStart, string $aEnd, string $bStart, string $bEnd) use ($daysBetween): int {
        $start = max($aStart, $bStart);
        $end   = min($aEnd,   $bEnd);
        if ($end < $start) return 0;
        return $daysBetween($start, $end);
      };
      $soglia_prorata_min = function(int $baseMin, array $rangesInfortunio, string $pStart, string $pEnd) use ($overlapDays, $daysBetween): int {
        $totDays = $daysBetween($pStart, $pEnd);
        if ($totDays <= 0) return 0;
        $infDays = 0;
        foreach ($rangesInfortunio as $r) {
          $dal = $r[0] ?? null; $al = $r[1] ?? null;
          if (!$dal || !$al) continue;
          $infDays += $overlapDays($pStart, $pEnd, $dal, $al);
        }
        if ($infDays > $totDays) $infDays = $totDays;
        $attivi = $totDays - $infDays;
        $ratio  = ($attivi <= 0) ? 0.0 : ($attivi / $totDays);
        $effMin = (int) round($baseMin * $ratio);
        return max(0, min($effMin, $baseMin));
      };

      $rifY = (int)substr($meseStr,0,4);
      $rifM = (int)substr($meseStr,5,2);
      $dt0  = DateTime::createFromFormat('Y-m-d', sprintf('%04d-%02d-01', $rifY, $rifM)) ?: new DateTime(date('Y-m-01'));
      $dt1  = (clone $dt0)->modify('last day of this month');
      $periodStart = $dt0->format('Y-m-d');
      $periodEnd   = $dt1->format('Y-m-d');

      $ranges       = $INFORTUNI[$vid] ?? [];
      $sogliaEffMin = $soglia_prorata_min(QUOTA_MENSILE_MIN, $ranges, $periodStart, $periodEnd);
      $actualMin    = $scalaRec ? $netto : $lordo;
      $ok = ($actualMin >= $sogliaEffMin);

      fputcsv($out, [
        ++$i, $cogn, $nome, $nSess,
        $nRecM, $nRecY,
        $actualMin, h_ore_min($actualMin),
        $bancaMin,  h_ore_min($bancaMin),
        $sogliaEffMin, h_ore_min($sogliaEffMin),
        $ok ? 'OK' : 'SOTTO', $vistaLbl
      ]);
    }
  }

  fclose($out);
  exit;
}