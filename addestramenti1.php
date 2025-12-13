<?php
// addestramenti.php — elenco sessioni con raggruppamento per sessione_uid

ob_start();
require __DIR__.'/auth.php';
require __DIR__.'/tenant_bootstrap.php';
require_tenant_user();

require __DIR__.'/storage.php';
require __DIR__.'/utils.php';
include __DIR__.'/_menu.php';

// Permesso di sola visualizzazione per entrare nella pagina
require_perm('view:addestramenti');   // visione ok per tutti quelli abilitati

/* ==========================================================
   Rilevazione "Capo": usa un permesso certo (admin:perms).
   Fallback: is_capo in sessione/utente se presente.
   ========================================================== */
$isCapo = false;

// 1) se il sistema permessi dice che hai admin:perms → sei Capo
if (function_exists('auth_can') && auth_can('admin:perms')) {
  $isCapo = true;
}

// 2) fallback: se la tua auth espone is_capo in sessione/utente, usalo
if (!$isCapo) {
  $u = function_exists('auth_user') ? auth_user() : ($_SESSION['user'] ?? []);
  $raw = $u['is_capo'] ?? ($_SESSION['is_capo'] ?? ($_SESSION['user']['is_capo'] ?? null));
  if (is_bool($raw))        { $isCapo = $raw; }
  elseif (is_int($raw))     { $isCapo = ($raw === 1); }
  elseif (is_string($raw))  { $isCapo = in_array(strtolower($raw), ['1','true','yes','on'], true); }
}

/* ==========================================================
   Dati
   ========================================================== */

// Carico anagrafica vigili
$vigili = load_vigili();
$vigById = [];
foreach ($vigili as $v) $vigById[(int)$v['id']] = $v;

// Carico addestramenti
$items = load_addestramenti();

// MIGRAZIONE SOFT: assegna sessione_uid dove manca (per vecchi record)
$changed  = false;
$groups   = [];    // uid => array di record
$tmpIndex = [];    // chiave composita => uid

foreach ($items as &$it) {
  if (empty($it['sessione_uid'])) {
    $chiave = implode('|', [
      $it['data'] ?? '',
      $it['inizio_dt'] ?? (($it['data']??'').'T'.substr((string)($it['inizio']??''),0,5).':00'),
      $it['fine_dt']   ?? (($it['data']??'').'T'.substr((string)($it['fine']??''),0,5).':00'),
      trim((string)($it['attivita'] ?? ''))
    ]);
    if (!isset($tmpIndex[$chiave])) $tmpIndex[$chiave] = bin2hex(random_bytes(8));
    $it['sessione_uid'] = $tmpIndex[$chiave];
    $changed = true;
  }
  $groups[$it['sessione_uid']][] = $it;
}
unset($it);
if ($changed) save_addestramenti($items);

// Ordino i gruppi per data/ora inizio decrescente
// Filtro visibilità: se NON sei Capo, mostra solo le sessioni di OGGI (Europe/Rome)
$tz = new DateTimeZone('Europe/Rome');
$oggi = (new DateTime('now', $tz))->format('Y-m-d');

if (!$isCapo) {
  $groups = array_filter($groups, function(array $rows) use ($oggi) {
    // prendo la prima riga del gruppo (stessa logica usata sotto)
    $r0   = $rows[0] ?? [];
    $data = $r0['data'] ?? substr((string)($r0['inizio_dt'] ?? ''), 0, 10);
    return $data === $oggi;
  });
}

// Ordino i gruppi per data/ora inizio decrescente (come prima)
uksort($groups, function($a,$b) use ($groups){
  $ga = $groups[$a][0]; $gb = $groups[$b][0];
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
  .attivita-cell {
    max-width: 560px;
    white-space: normal;
    word-break: break-word;
  }
  .btn-group-sm .btn { padding: .2rem .5rem; }
  /* Disabilita davvero i link marcati disabled */
  a.btn.disabled, a.btn[aria-disabled="true"] {
    pointer-events: none;
    opacity: .65;
  }
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
          <?php foreach ($groups as $uid => $rows):
            // master (prima riga del gruppo)
            $r0 = $rows[0];
            $data   = $r0['data'] ?? substr((string)($r0['inizio_dt']??''),0,10);
            $inizio = $r0['inizio'] ?? substr((string)($r0['inizio_dt']??''),11,5);
            $fine   = $r0['fine']   ?? substr((string)($r0['fine_dt']??''),11,5);
            $att    = trim((string)($r0['attivita'] ?? ''));
            $partecipanti = count($rows);
            $totMin = 0; foreach ($rows as $rr) $totMin += (int)($rr['minuti'] ?? 0);
            $isRecupero = false;
            foreach ($rows as $rr) { if ((int)($rr['recupero'] ?? 0) === 1) { $isRecupero = true; break; } }
          ?>
          <tr class="<?= $isRecupero ? 'is-recupero' : '' ?>">
          <td>
  <?= htmlspecialchars($data) ?>
  <?php if ($data === $oggi): ?>
    <span class="badge rounded-pill text-bg-success ms-1">Oggi</span>
  <?php endif; ?>
</td>
            <td><?= htmlspecialchars($inizio) ?></td>
            <td><?= htmlspecialchars($fine) ?></td>
            <td class="attivita-cell">
              <?= htmlspecialchars($att) ?>
              <?php if ($isRecupero): ?>
                <span class="badge rounded-pill text-bg-warning ms-1">Recupero</span>
              <?php endif; ?>
            </td>
            <td><?= (int)$partecipanti ?></td>
            <td><?= (int)$totMin ?></td>
            <td class="text-end">
              <div class="btn-group btn-group-sm" role="group">
                <a class="btn btn-secondary" href="qr_view.php?uid=<?= urlencode($uid) ?>" title="Mostra QR per il check-in">QR</a>
                <a class="btn btn-outline-secondary" href="checkin.php?uid=<?= urlencode($uid) ?>" title="Pagina di check-in">Check-in</a>

                <?php if ($isCapo): ?>
                  <a class="btn btn-primary" href="edit_addestramento.php?uid=<?= urlencode($uid) ?>">Dettaglio / Modifica</a>
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