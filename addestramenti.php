<?php
// addestramenti.php — elenco sessioni con raggruppamento per sessione_uid
// Versione autonoma e NON distruttiva: non modifica il JSON esistente.

ob_start();
ini_set('display_errors', '1'); error_reporting(E_ALL);

require __DIR__.'/auth.php';
require __DIR__.'/tenant_bootstrap.php';
require_tenant_user();

// _menu opzionale
if (is_file(__DIR__.'/_menu.php')) {
  include __DIR__.'/_menu.php';
}

/* =========================
   UTILITIES LOCALI
   ========================= */
if (!defined('DATA_DIR')) {
  define('DATA_DIR', __DIR__ . '/data');
}
function _path(string $file): string {
  $file = ltrim($file, '/');
  return rtrim(DATA_DIR, '/').'/'.$file;
}
function _read_json(string $absPath): array {
  if (!is_file($absPath)) return [];
  $s = @file_get_contents($absPath);
  if ($s === false) return [];
  $j = json_decode($s, true);
  return is_array($j) ? $j : [];
}
function load_vigili_local(): array {
  $path = defined('VIGILI_JSON') ? VIGILI_JSON : _path('vigili.json');
  return _read_json($path);
}
function load_addestramenti_local(): array {
  $path = defined('ADDESTRAMENTI_JSON') ? ADDESTRAMENTI_JSON : _path('addestramenti.json');
  return _read_json($path);
}
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* =========================
   Permessi / Ruolo
   ========================= */
if (function_exists('require_perm')) {
  require_perm('view:addestramenti');
}

$isCapo = false;
if (function_exists('auth_can') && auth_can('admin:perms')) {
  $isCapo = true;
}
if (!$isCapo) {
  $u = function_exists('auth_current_user') ? auth_current_user() : ($_SESSION['user'] ?? []);
  $raw = $u['is_capo'] ?? ($_SESSION['is_capo'] ?? ($_SESSION['user']['is_capo'] ?? null));
  if (is_bool($raw))        { $isCapo = $raw; }
  elseif (is_int($raw))     { $isCapo = ($raw === 1); }
  elseif (is_string($raw))  { $isCapo = in_array(strtolower($raw), ['1','true','yes','on'], true); }
}

/* =========================
   Dati
   ========================= */
$activeSlug = $_SESSION['tenant_slug'] ?? $_SESSION['CASERMA_SLUG'] ?? '';
$vigili = load_vigili_local();
$vigById = [];
foreach ($vigili as $v) {
  $idStr = trim((string)($v['id'] ?? ''));
  if ($idStr === '') continue;
  $vigById[$idStr] = $v;
  $vigById[(int)$idStr] = $v;
}
$items = load_addestramenti_local();

/* ==========================================
   Raggruppamento NON distruttivo:
   - se c'è sessione_uid ⇒ gruppo per UID
   - altrimenti ⇒ gruppo legacy per (data,inizio,fine,attività)
   ========================================== */
$groups = []; // chiave => ['uid'=>string, 'rows'=>array]

foreach ($items as $it) {
  if (!is_array($it)) continue;

  // Normalizza inizio/fine robusti
  $data  = (string)($it['data'] ?? '');
  $inizio_dt = $it['inizio_dt'] ?? ($data ? ($data.'T'.substr((string)($it['inizio']??'00:00'),0,5).':00') : '');
  $fine_dt   = $it['fine_dt']   ?? ($data ? ($data.'T'.substr((string)($it['fine']  ??'00:00'),0,5).':00') : '');
  $attivita  = trim((string)($it['attivita'] ?? ''));

  if (!empty($it['sessione_uid'])) {
    $key = 'uid:'.$it['sessione_uid'];
    if (!isset($groups[$key])) $groups[$key] = ['uid'=>$it['sessione_uid'], 'rows'=>[]];
    $groups[$key]['rows'][] = $it;
  } else {
    // chiave legacy stabile (non random, non scrive sul file)
    $legacyKey = 'legacy:'.implode('|', [$data, $inizio_dt, $fine_dt, $attivita]);
    if (!isset($groups[$legacyKey])) $groups[$legacyKey] = ['uid'=>'', 'rows'=>[]];
    $groups[$legacyKey]['rows'][] = $it;
  }
}

/* ==========================================
   Oggi e filtro visibilità “non capo”
   ========================================== */
$tz = new DateTimeZone('Europe/Rome');
$oggi = (new DateTime('now', $tz))->format('Y-m-d');

if (!$isCapo) {
  $groups = array_filter($groups, function(array $g) use ($oggi){
    $r0 = $g['rows'][0] ?? [];
    $d  = $r0['data'] ?? substr((string)($r0['inizio_dt'] ?? ''), 0, 10);
    return $d === $oggi;
  });
}

/* ==========================================
   Ordina per orario inizio decrescente
   ========================================== */
uksort($groups, function($ka,$kb) use ($groups){
  $ga = $groups[$ka]['rows'][0] ?? [];
  $gb = $groups[$kb]['rows'][0] ?? [];
  $ia = $ga['inizio_dt'] ?? (($ga['data']??'').'T'.substr((string)($ga['inizio']??'00:00'),0,5).':00');
  $ib = $gb['inizio_dt'] ?? (($gb['data']??'').'T'.substr((string)($gb['inizio']??'00:00'),0,5).':00');
  return strcmp($ib,$ia);
});
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Addestramenti – Elenco sessioni</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  tr.is-recupero { background: var(--bs-warning-bg-subtle); }
  .attivita-cell { max-width: 560px; white-space: normal; word-break: break-word; }
  .btn-group-sm .btn { padding: .2rem .5rem; }
  a.btn.disabled, a.btn[aria-disabled="true"] { pointer-events: none; opacity: .65; }
</style>
</head>
<body class="bg-light">
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center">
    <h1 class="mb-0">Elenco Addestramenti</h1>
    <a class="btn btn-outline-secondary" href="index.php">← Inserimento</a>
  </div>

  <div class="card mt-3 shadow-sm">
    <div class="card-body">
      <table class="table table-sm align-middle">
        <thead class="table-light">
          <tr>
            <th>Data</th>
            <th>Inizio</th>
            <th>Fine</th>
            <th class="attivita-cell">Attività</th>
            <th>Partecipanti</th>
            <th>Totale minuti</th>
            <th class="text-end">Azioni</th>
          </tr>
        </thead>
        <tbody>
        <?php if (empty($groups)): ?>
          <tr><td colspan="7" class="text-muted">Nessun addestramento in data odierna.</td></tr>
        <?php else: ?>
          <?php foreach ($groups as $gkey => $g):
            $rows = $g['rows'];
            $r0   = $rows[0];

            $data   = $r0['data'] ?? substr((string)($r0['inizio_dt']??''),0,10);
            $inizio = $r0['inizio'] ?? substr((string)($r0['inizio_dt']??''),11,5);
            $fine   = $r0['fine']   ?? substr((string)($r0['fine_dt']??''),11,5);
            $att    = trim((string)($r0['attivita'] ?? ''));

            // Somma minuti
            $totMin = 0;
            foreach ($rows as $rr) { $totMin += (int)($rr['minuti'] ?? 0); }

            // Partecipanti (ID unici robusti)
            $ids = [];
            foreach ($rows as $rr) {
              $raw = isset($rr['vigile_id']) ? (string)$rr['vigile_id'] : '';
              $idTrim = trim($raw);
              if ($idTrim === '') continue;
              $ids[] = $idTrim;
            }
            $ids = array_values(array_unique($ids));
            $partecipanti = count($ids);

            // Elenco nomi robusto
            $presentiNomi = [];
            foreach ($ids as $idStr) {
              $idInt = (int)$idStr;
              $v = $vigById[$idStr] ?? ($vigById[$idInt] ?? null);
              if ($v) {
                $nome = trim(($v['cognome'] ?? '').' '.($v['nome'] ?? ''));
                $presentiNomi[] = ($nome !== '') ? $nome : ('ID #'.$idStr.' (dati anagrafici mancanti)');
              } else {
                $presentiNomi[] = 'ID #'.$idStr.' (sconosciuto)';
              }
            }
            $presentiNomi = array_values(array_filter($presentiNomi, fn($x)=>trim($x) !== ''));

            // flag recupero
            $isRecupero = false;
            foreach ($rows as $rr) {
              if ((int)($rr['recupero'] ?? 0) === 1) { $isRecupero = true; break; }
            }

            // UID presente nel gruppo?
            $uid = $g['uid'] ?? '';

            // Fallback ID (per i gruppi legacy senza UID)
            $firstId = 0;
            foreach ($rows as $rr) {
              if (!empty($rr['id'])) { $firstId = (int)$rr['id']; break; }
            }

            // Link Dettaglio: se c’è sessione_uid usa ?uid=..., altrimenti ?id=...
            $editHref = ($uid !== '')
              ? 'edit_addestramento.php?uid='.urlencode($uid)
              : 'edit_addestramento.php?id='.$firstId;

            // Azioni QR / Check-in: abilita solo se c’è UID
            $qrEnabled  = ($uid !== '');
            $ciEnabled  = ($uid !== '');
          ?>
          <tr class="<?= $isRecupero ? 'is-recupero' : '' ?>">
            <td>
              <?= h($data) ?>
              <?php if ($data === $oggi): ?>
                <span class="badge rounded-pill text-bg-success ms-1">Oggi</span>
              <?php endif; ?>
            </td>
            <td><?= h($inizio) ?></td>
            <td><?= h($fine) ?></td>
            <td class="attivita-cell">
              <?= h($att) ?>
              <?php if ($isRecupero): ?>
                <span class="badge rounded-pill text-bg-warning ms-1">Recupero</span>
              <?php endif; ?>
              <?php if (!$isCapo && !empty($presentiNomi)): ?>
                <div class="mt-2">
                  <div class="fw-semibold small text-secondary">Presenti:</div>
                  <ul class="mb-0 ps-3 small">
                    <?php foreach ($presentiNomi as $nom): ?>
                      <li><?= h($nom) ?></li>
                    <?php endforeach; ?>
                  </ul>
                </div>
              <?php endif; ?>
            </td>
            <td><?= (int)$partecipanti ?></td>
            <td><?= (int)$totMin ?></td>
            <td class="text-end">
              <div class="btn-group btn-group-sm" role="group">
                <?php if ($qrEnabled): ?>
                  <a class="btn btn-secondary"
                     href="qr_view.php?uid=<?= urlencode($uid) ?>&slug=<?= urlencode($activeSlug) ?>"
                     title="Mostra QR per il check-in">QR</a>
                <?php else: ?>
                  <a class="btn btn-secondary disabled" tabindex="-1" aria-disabled="true"
                     title="QR disponibile solo per sessioni con UID">QR</a>
                <?php endif; ?>

                <?php if ($ciEnabled): ?>
                  <a class="btn btn-outline-secondary"
                     href="checkin.php?uid=<?= urlencode($uid) ?>&slug=<?= urlencode($activeSlug) ?>"
                     title="Pagina di check-in">Check-in</a>
                <?php else: ?>
                  <a class="btn btn-outline-secondary disabled" tabindex="-1" aria-disabled="true"
                     title="Check-in disponibile solo per sessioni con UID">Check-in</a>
                <?php endif; ?>

                <?php if ($isCapo): ?>
                  <a class="btn btn-primary" href="<?= h($editHref) ?>">Dettaglio / Modifica</a>
                <?php else: ?>
                  <a class="btn btn-primary disabled" tabindex="-1" aria-disabled="true" title="Consentito solo al Capo">Dettaglio / Modifica</a>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
</body>
</html>