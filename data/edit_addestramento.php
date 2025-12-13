<?php
// edit_addestramento.php — modifica di una sessione esistente (per UID)
// Banca ore PRO-RATA: soglia = 5h × (# mesi rilevanti nella finestra 12m),
// escludendo mesi con infortunio/sospensione e mesi precedenti all’ingresso.
// Se RECUPERO attivo: per ogni vigile registra min(durata_sessione, banca_vigile).
// Colorazione: VERDE (banca ≥ durata), ARANCIONE (0 < banca < durata), ROSSO (banca = 0).

ob_start();
require __DIR__.'/auth.php';
require __DIR__.'/tenant_bootstrap.php'; // DATA_DIR per caserma attiva
require_tenant_user();

require_mfa();
ini_set('display_errors', 1); error_reporting(E_ALL);

require __DIR__.'/storage.php';
require __DIR__.'/utils.php';

// Permessi PRIMA del rendering UI
require_perm('view:addestramenti');
require_perm('edit:addestramenti');

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['_csrf_edit_addestramento'])) {
  $_SESSION['_csrf_edit_addestramento'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['_csrf_edit_addestramento'];

include __DIR__.'/_menu.php';

// === Catalogo attività (standalone, in DATA_DIR) ===
if (!defined('ATTIVITA_JSON')) {
  define('ATTIVITA_JSON', DATA_DIR . '/attivita.json');
}
if (!file_exists(ATTIVITA_JSON)) { @file_put_contents(ATTIVITA_JSON, "[]"); }

function load_attivita_catalogo(): array {
  try { $rows = load_json(ATTIVITA_JSON); } catch (Throwable $e) { $rows = []; }
  if (!is_array($rows)) $rows = [];
  $clean = [];
  foreach ($rows as $x) {
    if (is_string($x)) { $s = trim($x); if ($s !== '') $clean[] = $s; }
    elseif (is_array($x) && isset($x['label'])) {
      $s = trim((string)$x['label']); if ($s !== '') $clean[] = $s;
    }
  }
  $seen = []; $out = [];
  foreach ($clean as $s) {
    $k = mb_strtolower($s, 'UTF-8');
    if (!isset($seen[$k])) { $seen[$k] = true; $out[] = $s; }
  }
  sort($out, SORT_FLAG_CASE | SORT_STRING);
  return $out;
}
function add_to_attivita_catalogo(string $label): void {
  $label = trim($label);
  if ($label === '') return;
  $all = load_attivita_catalogo();
  $kNew = mb_strtolower($label, 'UTF-8');
  foreach ($all as $ex) if (mb_strtolower($ex, 'UTF-8') === $kNew) return;
  $all[] = $label;
  sort($all, SORT_FLAG_CASE | SORT_STRING);
  save_json_atomic(ATTIVITA_JSON, $all);
}
$catalogoAtt = load_attivita_catalogo();

// --- UID sessione ---
$uid = $_GET['uid'] ?? ($_POST['uid'] ?? '');
if ($uid === '') { http_response_code(400); die('UID mancante'); }

// Anagrafica attiva
$vigili = load_vigili();
$vigById = [];
foreach ($vigili as $v) { if ((int)($v['attivo'] ?? 1) === 1) $vigById[(int)$v['id']] = $v; }
$vigiliOrdinati = array_values($vigById);
usort($vigiliOrdinati, fn($a,$b)=>strcmp(
  trim(($a['cognome']??'').' '.($a['nome']??'')),
  trim(($b['cognome']??'').' '.($b['nome']??''))
));

// Addestramenti
$items = load_addestramenti();

// --- ELIMINA l’intera sessione ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete'])) {
  if (!hash_equals($_SESSION['_csrf_edit_addestramento'] ?? '', $_POST['csrf'] ?? '')) {
    http_response_code(400); die('Bad CSRF');
  }
  $items = array_values(array_filter($items, fn($it)=> (($it['sessione_uid'] ?? '') !== $uid)));
  save_addestramenti($items);
  header('Location: addestramenti.php?msg=del', true, 303);
  exit;
}

// Filtra records di questa sessione
// Tutte le righe della sessione (anche senza vigile_id)
$sessionRowsAll = array_values(array_filter($items, fn($r)=> ($r['sessione_uid'] ?? '') === $uid));
if (empty($sessionRowsAll)) { http_response_code(404); die('Sessione non trovata'); }

// Solo le righe partecipante (con vigile_id)
$sessionRows = array_values(array_filter($sessionRowsAll, fn($r)=> isset($r['vigile_id'])));

// Master: prendi la prima riga disponibile (partecipante se c'è, altrimenti una “master” senza vigile_id)
$r0 = $sessionRows[0] ?? $sessionRowsAll[0];


$inizioDT  = $r0['inizio_dt'] ?? (($r0['data']??'').'T'.substr((string)($r0['inizio']??'00:00'),0,5).':00');
$fineDT    = $r0['fine_dt']   ?? (($r0['data']??'').'T'.substr((string)($r0['fine']  ??'00:00'),0,5).':00');
$attivita0 = trim((string)($r0['attivita'] ?? ''));
$note0     = trim((string)($r0['note'] ?? ''));

// === Calcolo BANCA (ultimi 12 mesi pre-mese della sessione) ===
$yyyy = (int)substr($inizioDT,0,4);
$mm   = (int)substr($inizioDT,5,2);
$startRef = DateTime::createFromFormat('Y-m-d', sprintf('%04d-%02d-01', $yyyy, $mm));
$winStart = (clone $startRef)->modify('-1 year'); // incluso
$winEnd   = (clone $startRef)->modify('-1 day');  // incluso
$winStartStr = $winStart->format('Y-m-d');
$winEndStr   = $winEnd->format('Y-m-d');

// Elenco mesi YYYY-MM nella finestra
$mesiFinestra = [];
$cur = (clone $winStart)->modify('first day of this month');
$end = (clone $winEnd)->modify('first day of next month');
while ($cur < $end) {
  $mesiFinestra[] = $cur->format('Y-m');
  $cur->modify('+1 month');
}

// Accumula non-recupero e recupero nella finestra
$nonrec = []; $rec = [];
foreach ($items as $r) {
  $vid = (int)($r['vigile_id'] ?? 0);
  $d   = (string)($r['data'] ?? '');
  if (!$vid || !$d) continue;
  if ($d < $winStartStr || $d > $winEndStr) continue;

  // minuti robusti: usa 'minuti' se presente, altrimenti calcola
  $minr = isset($r['minuti']) ? (int)$r['minuti'] : minuti_da_intervallo_datetime(
    $r['inizio_dt'] ?? ($d.'T'.substr((string)($r['inizio']??'00:00'),0,5).':00'),
    $r['fine_dt']   ?? ($d.'T'.substr((string)($r['fine']  ??'00:00'),0,5).':00')
  );

  if ((int)($r['recupero'] ?? 0) === 1) $rec[$vid]    = ($rec[$vid]    ?? 0) + $minr;
  else                                   $nonrec[$vid] = ($nonrec[$vid] ?? 0) + $minr;
}

// Infortuni: [vid] => [[dal, al], ...]
$infortuniRaw = load_infortuni();
$INFORTUNI = [];
if (is_array($infortuniRaw)) {
  foreach ($infortuniRaw as $row) {
    $vid = (int)($row['vigile_id'] ?? 0);
    $dal = (string)($row['dal'] ?? '');
    $al  = (string)($row['al'] ?? '');
    if ($vid && preg_match('/^\d{4}-\d{2}-\d{2}$/',$dal) && preg_match('/^\d{4}-\d{2}-\d{2}$/',$al)) {
      $INFORTUNI[$vid][] = [$dal,$al];
    }
  }
}

// Mesi di infortunio intersecanti la finestra, per-vid (set di "YYYY-MM")
$mesiInfByVid = [];
foreach ($INFORTUNI as $vid => $ranges) {
  $set = [];
  foreach ($ranges as [$dal,$al]) {
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

// BANCA PRO-RATA
const QUOTA_MENSILE_MIN = 5 * 60; // 5 ore/mese
$BANCA = [];

foreach ($vigById as $vid => $vv) {
  // Mese di ingresso (se non presente, considero tutta la finestra)
  $ing = $vv['data_ingresso'] ?? $vv['ingresso'] ?? null;
  $ingMonth = $ing ? substr($ing,0,7) : ($mesiFinestra[0] ?? substr($winStartStr,0,7));

  // Mesi rilevanti (da ingresso in poi) nella finestra
  $mesiRilevanti = array_filter($mesiFinestra, fn($ym) => $ym >= $ingMonth);

  // Escludi mesi con infortunio
  $setInf = $mesiInfByVid[$vid] ?? [];
  $mesiQuota = array_values(array_filter($mesiRilevanti, fn($ym) => empty($setInf[$ym])));
  $nMesiQuota = count($mesiQuota);

  $sogliaEffMin = $nMesiQuota * QUOTA_MENSILE_MIN;          // minuti dovuti in 12m pro-rata
  $nr = (int)($nonrec[$vid] ?? 0);                           // minuti fatti (non-recupero)
  $rc = (int)($rec[$vid]    ?? 0);                           // minuti già recuperati
  $eccedenza = max(0, $nr - $sogliaEffMin);                  // oltre la soglia
  $bank = $eccedenza - $rc;                                  // tolgo recuperi già fatti
  if ($bank < 0) $bank = 0;
  $BANCA[$vid] = $bank;
}

// stato “recupero” attuale della sessione
$recupero0 = 0;
foreach ($sessionRows as $r) { if ((int)($r['recupero'] ?? 0) === 1) { $recupero0 = 1; break; } }

// elenco presenti correnti (safe su chiavi mancanti)
$presenti = [];
foreach ($sessionRows as $r) {
  if (isset($r['vigile_id'])) {
    $presenti[(int)$r['vigile_id']] = true;
  }
}

// === POST: salva modifiche ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'save')) {
  if (!hash_equals($_SESSION['_csrf_edit_addestramento'] ?? '', $_POST['csrf'] ?? '')) {
    http_response_code(400); die('Bad CSRF');
  }
  try {
    $inizioDT = trim($_POST['inizio_dt'] ?? '');
    $fineDT   = trim($_POST['fine_dt']   ?? '');

    // attività: select / altro
    $att_sel = trim((string)($_POST['attivita_select'] ?? ''));
    $att_cus = trim(preg_replace('/\s+/', ' ', $_POST['attivita_custom'] ?? ''));
    $addProg = isset($_POST['add_to_program']) ? 1 : 0;
    $attivita = ($att_sel && $att_sel !== '__ALTRO__') ? $att_sel : $att_cus;
    if ($addProg && $attivita !== '') { add_to_attivita_catalogo($attivita); }

    $note       = trim(preg_replace('/\s+/', ' ', $_POST['note'] ?? ''));
    $selez      = $_POST['vigili_presenti'] ?? [];
    $isRecupero = isset($_POST['recupero']) ? 1 : 0;

    if (!is_array($selez)) $selez = [];
    $selez = array_values(array_unique(array_map('intval', $selez)));
    if (empty($selez)) throw new InvalidArgumentException('Seleziona almeno un presente');

    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?$/', $inizioDT)) throw new InvalidArgumentException('Inizio non valido');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?$/', $fineDT))   throw new InvalidArgumentException('Fine non valido');

    // durata sessione richiesta (minuti)
    $min = minuti_da_intervallo_datetime($inizioDT, $fineDT);

    // TS per overlap
    $tz  = new DateTimeZone(date_default_timezone_get());
    $fix = fn($s)=> (strlen($s)===16 ? $s.':00' : $s);
    $startTs = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', $fix($inizioDT), $tz)->getTimestamp();
    $endTs   = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', $fix($fineDT),   $tz)->getTimestamp();

    // Mappa esistenti della sessione corrente (safe su chiavi mancanti)
    $esistenti = [];
    foreach ($items as $idx => $it) {
      if (($it['sessione_uid'] ?? '') === $uid && isset($it['vigile_id'])) {
        $esistenti[(int)$it['vigile_id']] = $idx;
      }
    }

    // Overlap (esclusa la stessa sessione)
    foreach ($selez as $vid) {
      foreach ($items as $it) {
        if ((int)($it['vigile_id'] ?? 0) !== $vid) continue;
        if (($it['sessione_uid'] ?? '') === $uid)  continue;

        $s = $e = null;
        if (isset($it['inizio_dt'], $it['fine_dt'])) {
          $s = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', $fix($it['inizio_dt']), $tz);
          $e = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', $fix($it['fine_dt']),   $tz);
        } else {
          $s = DateTimeImmutable::createFromFormat('Y-m-d H:i', ($it['data']??'').' '.substr((string)($it['inizio']??'00:00'),0,5), $tz);
          $e = DateTimeImmutable::createFromFormat('Y-m-d H:i', ($it['data']??'').' '.substr((string)($it['fine']  ??'00:00'),0,5), $tz);
          if ($s && $e && $e <= $s) $e = $e->add(new DateInterval('P1D'));
        }
        if (!$s || !$e) continue;

        if (max($startTs, $s->getTimestamp()) < min($endTs, $e->getTimestamp())) {
          throw new InvalidArgumentException("Sovrapposizione oraria con altro addestramento per vigile ID $vid");
        }
      }
    }

    // Normalizza
    $data = substr($inizioDT, 0, 10);
    $toHH = fn($dt)=> substr($fix($dt),11,5);

    // --- SALVATAGGIO PER-VIGILE con CAP sulla BANCA (se recupero attivo) ---
    foreach ($selez as $vid) {
      $bank = (int)($BANCA[$vid] ?? 0);               // banca residua (pro-rata) calcolata sopra
      $minEff = $isRecupero ? min($min, max(0,$bank)) // se RECUPERO: usa solo i minuti disponibili
                            : $min;                   // se NON recupero: tieni tutta la durata

      if (isset($esistenti[$vid])) {
        $i = $esistenti[$vid];
        $items[$i]['data']      = $data;
        $items[$i]['inizio']    = $toHH($inizioDT);
        $items[$i]['fine']      = $toHH($fineDT);
        $items[$i]['inizio_dt'] = $fix($inizioDT);
        $items[$i]['fine_dt']   = $fix($fineDT);
        $items[$i]['minuti']    = (int)$minEff;        // minuti effettivi registrati
        $items[$i]['attivita']  = ($attivita !== '' ? $attivita : null);
        $items[$i]['note']      = ($note !== '' ? $note : null);
        $items[$i]['recupero']  = $isRecupero ? 1 : 0;
      } else {
        $id = next_id($items);
        $items[] = [
          'id'           => $id,
          'sessione_uid' => $uid,
          'vigile_id'    => $vid,
          'data'         => $data,
          'inizio'       => $toHH($inizioDT),
          'fine'         => $toHH($fineDT),
          'inizio_dt'    => $fix($inizioDT),
          'fine_dt'      => $fix($fineDT),
          'minuti'       => (int)$minEff,              // minuti effettivi registrati
          'attivita'     => ($attivita !== '' ? $attivita : null),
          'note'         => ($note !== '' ? $note : null),
          'created_at'   => date('Y-m-d H:i:s'),
          'recupero'     => $isRecupero ? 1 : 0,
        ];
      }
    }

    // Rimuovi eventuali righe della sessione corrente per vigili non più selezionati (safe su chiavi mancanti)
    $keepIds = array_flip($selez);
    $items = array_values(array_filter($items, function($it) use ($uid, $keepIds){
      if (($it['sessione_uid'] ?? '') !== $uid) return true;
      // tengo solo se la riga ha un vigile_id presente nella selezione
      if (!isset($it['vigile_id'])) return false;
      return isset($keepIds[(int)$it['vigile_id']]);
    }));

    save_addestramenti($items);
    header('Location: addestramenti.php?msg=ok', true, 303); exit;

  } catch (Throwable $e) {
    http_response_code(400);
    echo 'Errore: '.htmlspecialchars($e->getMessage()); exit;
  }
}

// UI helper: se l’attività salvata non è nel catalogo, seleziono “Altro”
$usaAltro = ($attivita0 !== '' && !in_array($attivita0, $catalogoAtt, true));
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Modifica addestramento</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  .form-check-label.text-danger { font-weight: 600; }
  .form-check-input.is-invalid { outline: 2px solid rgba(220,53,69,.6); }
  .form-check-label.text-success { font-weight: 600; }
  .form-check-input.is-valid { outline: 2px solid rgba(25,135,84,.6); }
  /* Stato parziale (arancione) */
  .form-check-label.text-warning { font-weight: 600; }
  .form-check-input.is-partial { outline: 2px solid rgba(255,193,7,.6); }
  .form-check-input:disabled + .form-check-label { opacity:.65; }

  /* Select con opzioni lunghe leggibili */
  select.long-options { width: 100%; max-width: 100%; }
  select.long-options option { white-space: normal; word-break: break-word; }
</style>
</head>
<body class="bg-light">
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center">
    <h1 class="mb-0">Dettaglio / Modifica addestramento</h1>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-secondary" href="addestramenti.php">← Elenco</a>
      <form method="post" onsubmit="return confirm('Eliminare TUTTA la sessione?');">
        <input type="hidden" name="delete" value="1">
        <input type="hidden" name="uid" value="<?= htmlspecialchars($uid) ?>">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
        <button class="btn btn-outline-danger">Elimina addestramento</button>
      </form>
    </div>
  </div>

  <div class="row g-3 mt-2">
    <div class="col-lg-6">
      <div class="card shadow-sm">
        <div class="card-body">
        <form id="form-edit" method="post" class="row g-2">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="uid" value="<?= htmlspecialchars($uid) ?>">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">

            <div class="col-12">
              <label class="form-label">Inizio (data e ora)</label>
              <input id="inizio_dt" type="datetime-local" name="inizio_dt" class="form-control" value="<?= htmlspecialchars(substr($inizioDT,0,16)) ?>" required>
            </div>
            <div class="col-12">
              <label class="form-label">Fine (data e ora)</label>
              <input id="fine_dt" type="datetime-local" name="fine_dt" class="form-control" value="<?= htmlspecialchars(substr($fineDT,0,16)) ?>" required>
            </div>

            <div class="col-12 form-check mt-1">
              <input class="form-check-input" type="checkbox" id="chkRec" name="recupero" value="1" <?= $recupero0 ? 'checked' : '' ?>>
              <label class="form-check-label fw-semibold" for="chkRec">Addestramento di recupero</label>
              <div class="form-text">
                Se attivo, per ciascun vigile verranno conteggiati al massimo i minuti disponibili nella
                <strong>Banca ore (ultimi 12 mesi)</strong>
                <span class="text-muted">(soglia: <em>5h × mesi rilevanti</em>, esclusi mesi di infortunio e pre-ingresso)</span>.
              </div>
            </div>

            <!-- Attività: select + "Altro" + aggiungi al programma -->
            <div class="col-12">
              <label class="form-label mb-1">Attività</label>
              <select class="form-select long-options" name="attivita_select" id="f_att_sel">
                <option value="">— Seleziona dal programma —</option>
                <?php foreach ($catalogoAtt as $lbl): ?>
                  <option value="<?= htmlspecialchars($lbl) ?>"
                          title="<?= htmlspecialchars($lbl) ?>"
                          <?= ($attivita0 !== '' && $attivita0 === $lbl) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($lbl) ?>
                  </option>
                <?php endforeach; ?>
                <option value="__ALTRO__" <?= $usaAltro ? 'selected' : '' ?>>— Altro (scrivi sotto) —</option>
              </select>

              <input type="text" class="form-control mt-2 <?= $usaAltro ? '' : 'd-none' ?>"
                     id="f_att_custom" name="attivita_custom"
                     value="<?= $usaAltro ? htmlspecialchars($attivita0) : '' ?>"
                     placeholder="Nuova attività…">

              <div class="form-check mt-2">
                <input class="form-check-input" type="checkbox" id="f_add_prog" name="add_to_program" value="1">
                <label class="form-check-label" for="f_add_prog">Aggiungi l’attività al programma</label>
              </div>
            </div>

            <div class="col-12">
              <label class="form-label">Note (opz.)</label>
              <textarea name="note" class="form-control" rows="2"><?= htmlspecialchars($note0) ?></textarea>
            </div>
        </div>
      </div>
    </div>

    <div class="col-lg-6">
      <div class="card shadow-sm">
        <div class="card-body">
          <h5 class="card-title mb-2">Partecipanti</h5>
          <div class="text-muted small mb-2">
            <span class="me-3"><strong>Banca ore (ultimi 12 mesi)</strong> = max(0, <em>non-recupero − soglia</em>) − recuperi.</span><br>
            <em>Soglia</em> = 5h × mesi rilevanti (da ingresso, esclusi i mesi con infortunio/sospensione).<br>
            Con “<em>Addestramento di recupero</em>” attivo: se <em>banca</em> &lt; durata, verranno conteggiati solo i minuti disponibili (es. 2h su 3h).
          </div>
          <div class="border rounded p-2" style="max-height:280px; overflow:auto;">
            <?php foreach ($vigiliOrdinati as $v):
              $id = (int)$v['id'];
              $label = htmlspecialchars(($v['cognome']??'').' '.($v['nome']??''));
              $chk = isset($presenti[$id]) ? 'checked' : '';
              $bancaMin = (int)($BANCA[$id] ?? 0);
            ?>
              <div class="form-check">
                <input class="form-check-input vigile-checkbox" type="checkbox" name="vigili_presenti[]" id="v<?= $id ?>" value="<?= $id ?>" <?= $chk ?>>
                <label class="form-check-label" for="v<?= $id ?>">
                  <?= $label ?>
                  <span class="ms-1 badge rounded-pill bg-light text-muted small vigile-info" data-vid="<?= $id ?>">
                    Banca: <?= htmlspecialchars(sprintf('%d:%02d', intdiv($bancaMin,60), $bancaMin%60)) ?>
                  </span>
                  <small class="badge rounded-pill text-bg-danger-subtle d-none badge-infortunio" data-vid="<?= $id ?>">Infortunio</small>
                </label>
              </div>
            <?php endforeach; ?>
          </div>
          <div class="mt-2 d-flex gap-2">
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="document.querySelectorAll('input[name=\'vigili_presenti[]\']').forEach(c=>{ if(!c.disabled) c.checked=true; })">Seleziona tutti</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="document.querySelectorAll('input[name=\'vigili_presenti[]\']').forEach(c=>c.checked=false)">Deseleziona tutti</button>
          </div>
          <div class="mt-2 text-muted small">
            <span class="me-3">Legenda:</span>
            <span class="fw-semibold text-success">Verde</span> = banca ≥ durata (se recupero) •
            <span class="fw-semibold text-warning">Arancione</span> = banca &gt; 0 ma &lt; durata (se recupero) •
            <span class="fw-semibold text-danger">Rosso</span> = banca = 0 (se recupero) •
            <span class="fw-semibold">Badge “Infortunio”</span> = non selezionabile nella data
          </div>
        </div>
      </div>
    </div>

    <div class="col-12">
      <div class="d-grid">
        <button class="btn btn-primary">Salva modifiche</button>
      </div>
      </form>
    </div>
  </div>
</div>

<script>
// UI: toggle campo "Altro" per attività
(function(){
  const sel  = document.getElementById('f_att_sel');
  const txt  = document.getElementById('f_att_custom');
  function toggleAltro(){
    if (!sel || !txt) return;
    if (sel.value === '__ALTRO__') txt.classList.remove('d-none');
    else txt.classList.add('d-none');
  }
  sel?.addEventListener('change', toggleAltro);
  toggleAltro();
})();

// Evidenziazione banca/infortunio durante l’editing + stima conteggio effettivo se RECUPERO
(() => {
  const BANCA    = <?= json_encode($BANCA, JSON_UNESCAPED_UNICODE) ?>;
  const INFORTUNI= <?= json_encode($INFORTUNI, JSON_UNESCAPED_UNICODE) ?>;

  const inpInizio = document.getElementById('inizio_dt');
  const inpFine   = document.getElementById('fine_dt');
  const chkRec    = document.getElementById('chkRec');
  const chks      = Array.from(document.querySelectorAll('input.vigile-checkbox'));

  function parseDatetimeLocal(v){
    if (!v || !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/.test(v)) return null;
    const val = v.length === 16 ? v + ':00' : v;
    const d = new Date(val.replace('T',' ') + '');
    return isNaN(d.getTime()) ? null : d;
  }
  function durataSessioneMin(){
    const s = parseDatetimeLocal(inpInizio.value);
    const e = parseDatetimeLocal(inpFine.value);
    if (!s || !e) return null;
    const diff = (e - s) / 60000;
    return diff > 0 ? Math.round(diff) : null;
  }
  function hmm(min){ min = Math.max(0, parseInt(min||0,10)); const h=Math.floor(min/60), m=min%60; return h+':'+String(m).padStart(2,'0'); }
  function datePart(v){ return v ? v.split('T')[0] : null; }

  function isInfortunio(vid, ymd){
    const ranges = INFORTUNI[vid] || [];
    if (!ymd) return false;
    for (const [dal, al] of ranges) {
      if (ymd >= dal && ymd <= al) return true;
    }
    return false;
  }

  function aggiornaStato() {
    const dur = durataSessioneMin();
    const ymd = datePart(inpInizio?.value);
    const isRec = !!chkRec?.checked;

    chks.forEach(cb => {
      cb.classList.remove('is-invalid', 'is-valid', 'is-partial');
      const lab = document.querySelector(`label[for="${cb.id}"]`);
      if (lab) lab.classList.remove('text-danger', 'text-success', 'text-warning');
      const badge = lab ? lab.querySelector('.vigile-info[data-vid="'+cb.value+'"]') : null;
      const bi    = lab ? lab.querySelector('.badge-infortunio[data-vid="'+cb.value+'"]') : null;
      if (badge) { badge.textContent = 'Banca: '+hmm(BANCA[cb.value]||0); badge.classList.remove('text-danger','text-success','text-warning'); }
      if (bi)    { bi.classList.add('d-none'); }
      cb.disabled = false;
    });

    if (dur === null || !ymd) return;

    chks.forEach(cb => {
      const vid   = parseInt(cb.value, 10);
      const bank  = parseInt(BANCA[vid] || 0, 10);
      const lab   = document.querySelector(`label[for="${cb.id}"]`);
      const badge = lab ? lab.querySelector('.vigile-info[data-vid="'+vid+'"]') : null;
      const bi    = lab ? lab.querySelector('.badge-infortunio[data-vid="'+vid+'"]') : null;

      if (isInfortunio(vid, ymd)) {
        cb.checked = false;
        cb.disabled = true;
        if (bi) bi.classList.remove('d-none');
        if (badge) { badge.textContent = 'Banca: '+hmm(bank)+' — INFORTUNIO'; badge.classList.remove('text-success','text-warning'); badge.classList.add('text-danger'); }
        return;
      }

      if (isRec && badge) {
        const eff = Math.min(bank, dur);
        badge.textContent = `Banca: ${hmm(bank)} • Richiesti: ${hmm(dur)} • Conteggio: ${hmm(eff)}`;
        badge.classList.remove('text-danger','text-success','text-warning');
        if (bank >= dur)      badge.classList.add('text-success');
        else if (bank > 0)    badge.classList.add('text-warning'); // arancione se parziale
        else                  badge.classList.add('text-danger');
      }

      if (cb.checked && isRec) {
        if (bank >= dur) {
          lab?.classList.add('text-success');
          cb.classList.add('is-valid');
        } else if (bank > 0) {
          lab?.classList.add('text-warning'); // arancione
          cb.classList.add('is-partial');
        } else {
          lab?.classList.add('text-danger');
          cb.classList.add('is-invalid');
        }
      }
    });
  }

  inpInizio?.addEventListener('input', aggiornaStato);
  inpFine?.addEventListener('input', aggiornaStato);
  chkRec?.addEventListener('change', aggiornaStato);
  chks.forEach(cb => cb.addEventListener('change', aggiornaStato));
  aggiornaStato();
})();

// Allarga il select durante l’apertura per leggere meglio le voci molto lunghe
(function(){
  function autosizeSelect(sel){
    if (!sel) return;
    const meas = document.createElement('span');
    meas.style.visibility = 'hidden';
    meas.style.position   = 'fixed';
    meas.style.whiteSpace = 'pre';
    meas.style.font = getComputedStyle(sel).font;
    document.body.appendChild(meas);

    let max = sel.offsetWidth;
    for (const opt of sel.options) {
      meas.textContent = opt.text;
      max = Math.max(max, meas.offsetWidth + 48);
    }
    document.body.removeChild(meas);

    sel.dataset.prevWidth = sel.style.width || '';
    sel.style.width = Math.min(max, 900) + 'px';
  }
  function restoreSize(sel){
    if (!sel) return;
    sel.style.width = sel.dataset.prevWidth || '';
  }

  const selects = [ document.getElementById('f_att_sel') ].filter(Boolean);
  selects.forEach(sel => {
    sel.addEventListener('focus',    () => autosizeSelect(sel));
    sel.addEventListener('mousedown',() => autosizeSelect(sel));
    sel.addEventListener('blur',     () => restoreSize(sel));
    sel.addEventListener('change',   () => restoreSize(sel));
  });
})();
</script>
</body>
</html>