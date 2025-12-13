<?php
// infortuni.php — gestione periodi di infortunio/sospensione (robusto con fallback)

ob_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

require __DIR__.'/auth.php';
require __DIR__.'/tenant_bootstrap.php'; // per leggere lo slug attivo
require __DIR__.'/utils.php';

// Protezione tenant (se disponibile)
if (function_exists('require_tenant_user')) { require_tenant_user(); }

// MFA opzionale
if (!function_exists('require_mfa')) {
  function require_mfa(){ /* no-op se MFA non è configurata */ }
}
require_mfa();

// Permessi (se disponibili)
if (!function_exists('require_perm')) {
  function require_perm($p){ /* no-op */ }
}
require_perm('view:infortuni');
require_perm('edit:infortuni');

// Tenta di caricare lo storage (se c'è)
@include __DIR__.'/storage.php';

/* =========================
   FALLBACK costanti & funzioni
   ========================= */
if (!defined('DATA_DIR')) {
  define('DATA_DIR', __DIR__.'/data');
  if (!is_dir(DATA_DIR)) @mkdir(DATA_DIR, 0775, true);
}
if (!defined('VIGILI_JSON'))     define('VIGILI_JSON', DATA_DIR.'/vigili.json');
if (!defined('INFORTUNI_JSON'))  define('INFORTUNI_JSON', DATA_DIR.'/infortuni.json');

if (!function_exists('load_json')) {
  function load_json($path){
    if (!is_file($path)) return [];
    $s = @file_get_contents($path);
    if ($s === false || $s === '') return [];
    $j = json_decode($s, true);
    return is_array($j) ? $j : [];
  }
}
if (!function_exists('save_json_atomic')) {
  function save_json_atomic($path, $data){
    $tmp = $path.'.tmp';
    if (@file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)) === false) {
      throw new RuntimeException("Impossibile scrivere $tmp");
    }
    if (!@rename($tmp, $path)) throw new RuntimeException("Impossibile rinominare $tmp in $path");
    @chmod($path, 0664);
  }
}
if (!function_exists('load_vigili')) {
  function load_vigili(){
    $rows = load_json(VIGILI_JSON);
    return function_exists('sanitize_vigili_list') ? sanitize_vigili_list($rows) : $rows;
  }
}
if (!function_exists('save_vigili')) {
  function save_vigili($rows){ save_json_atomic(VIGILI_JSON, $rows); }
}
if (!function_exists('load_infortuni')) {
  function load_infortuni(){
    if (!is_file(INFORTUNI_JSON)) @file_put_contents(INFORTUNI_JSON, "[]");
    return load_json(INFORTUNI_JSON);
  }
}
if (!function_exists('save_infortuni')) {
  function save_infortuni($rows){ save_json_atomic(INFORTUNI_JSON, $rows); }
}
if (!function_exists('next_id')) {
  function next_id(array $rows): int {
    $max = 0;
    foreach ($rows as $r) { $id = (int)($r['id'] ?? 0); if ($id > $max) $max = $id; }
    return $max + 1;
  }
}

/* =========================
   Sessione & CSRF
   ========================= */
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['_csrf_infortuni'])) {
  $_SESSION['_csrf_infortuni'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['_csrf_infortuni'];

/* =========================
   Menu opzionale
   ========================= */
$menuPath = __DIR__.'/_menu.php';
if (is_file($menuPath)) { include $menuPath; }

/* =========================
   Dati base
   ========================= */
$err = $_GET['err'] ?? '';
$msg = $_GET['msg'] ?? '';

$vigili = load_vigili();
if (!is_array($vigili)) $vigili = [];
usort($vigili, fn($a,$b)=>strcmp(($a['cognome']??'').' '.($a['nome']??''), ($b['cognome']??'').' '.($b['nome']??'')));
$vigById = [];
foreach ($vigili as $v) { $vigById[(int)($v['id'] ?? 0)] = $v; }

/* =========================
   POST (add/edit/delete)
   ========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // CSRF
  if (($_POST['_csrf'] ?? '') !== $csrf) {
    http_response_code(400);
    exit('CSRF non valido.');
  }

  $rows = load_infortuni();
  if (!is_array($rows)) $rows = [];

  // ADD
  if (($_POST['action'] ?? '') === 'add') {
    $vid = (int)($_POST['vigile_id'] ?? 0);
    $dal = trim((string)($_POST['dal'] ?? ''));
    $al  = trim((string)($_POST['al']  ?? ''));
    $note= trim(preg_replace('/\s+/', ' ', (string)($_POST['note'] ?? '')));
    if (!$vid || !isset($vigById[$vid]) ||
        !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dal) ||
        !preg_match('/^\d{4}-\d{2}-\d{2}$/', $al)  ||
        $al < $dal) {
      header('Location: infortuni.php?err=val'); exit;
    }
    $id = next_id($rows);
    $rows[] = [
      'id'         => $id,
      'vigile_id'  => $vid,
      'dal'        => $dal,
      'al'         => $al,
      'note'       => ($note !== '' ? $note : null),
      'created_at' => date('Y-m-d H:i:s'),
    ];
    save_infortuni($rows);
    header('Location: infortuni.php?msg=added'); exit;
  }

  // UPDATE
  if (($_POST['action'] ?? '') === 'edit') {
    $id  = (int)($_POST['id'] ?? 0);
    $vid = (int)($_POST['vigile_id'] ?? 0);
    $dal = trim((string)($_POST['dal'] ?? ''));
    $al  = trim((string)($_POST['al']  ?? ''));
    $note= trim(preg_replace('/\s+/', ' ', (string)($_POST['note'] ?? '')));
    if ($id<=0 || !$vid || !isset($vigById[$vid]) ||
        !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dal) ||
        !preg_match('/^\d{4}-\d{2}-\d{2}$/', $al)  ||
        $al < $dal) {
      header('Location: infortuni.php?err=val'); exit;
    }
    $found = false;
    foreach ($rows as &$r) {
      if ((int)($r['id'] ?? 0) === $id) {
        $r['vigile_id'] = $vid;
        $r['dal']       = $dal;
        $r['al']        = $al;
        $r['note']      = ($note !== '' ? $note : null);
        $found = true;
        break;
      }
    }
    unset($r);
    if (!$found) { header('Location: infortuni.php?err=nf'); exit; }
    save_infortuni($rows);
    header('Location: infortuni.php?msg=edited'); exit;
  }

  // DELETE
  if (($_POST['action'] ?? '') === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    $before = count($rows);
    $rows = array_values(array_filter($rows, fn($r)=> (int)($r['id'] ?? 0) !== $id));
    if (count($rows) === $before) { header('Location: infortuni.php?err=nf'); exit; }
    save_infortuni($rows);
    header('Location: infortuni.php?msg=deleted'); exit;
  }
}

/* =========================
   GET: elenco
   ========================= */
$rows = load_infortuni();
if (!is_array($rows)) $rows = [];
usort($rows, function($a,$b){
  $ka = ($a['dal'] ?? '') . ' ' . ($a['al'] ?? '');
  $kb = ($b['dal'] ?? '') . ' ' . ($b['al'] ?? '');
  return strcmp($kb, $ka);
});
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Gestione infortuni/sospensioni</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4">
  <div class="d-flex align-items-center justify-content-between">
    <h1 class="mb-0">Gestione infortuni/sospensioni</h1>
    <div>
      <a class="btn btn-outline-secondary" href="index.php">← Torna</a>
    </div>
  </div>

  <?php if ($err): ?>
    <div class="alert alert-danger mt-3">
      <?php
        echo match($err) {
          'val' => 'Dati non validi. Controlla vigile e date (al ≥ dal, formato YYYY-MM-DD).',
          'nf'  => 'Record non trovato.',
          default => 'Errore.'
        };
      ?>
    </div>
  <?php endif; ?>

  <?php if ($msg): ?>
    <div class="alert alert-success mt-3">
      <?php
        echo match($msg) {
          'added'  => 'Periodo di infortunio aggiunto.',
          'edited' => 'Periodo modificato.',
          'deleted'=> 'Periodo eliminato.',
          default  => 'OK.'
        };
      ?>
    </div>
  <?php endif; ?>

  <div class="row g-3 mt-3">
    <div class="col-lg-5">
      <div class="card shadow-sm">
        <div class="card-body">
          <h5 class="card-title">Aggiungi periodo</h5>
          <form method="post" class="row g-2">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="add">
            <div class="col-12">
              <label class="form-label">Vigile</label>
              <select name="vigile_id" class="form-select" required>
                <option value="">— Seleziona —</option>
                <?php foreach ($vigili as $v): ?>
                  <option value="<?= (int)$v['id'] ?>">
                    <?= htmlspecialchars(($v['cognome']??'').' '.($v['nome']??'')) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-6">
              <label class="form-label">Dal</label>
              <input type="date" name="dal" class="form-control" required>
            </div>
            <div class="col-6">
              <label class="form-label">Al</label>
              <input type="date" name="al" class="form-control" required>
            </div>
            <div class="col-12">
              <label class="form-label">Note (opz.)</label>
              <input type="text" name="note" class="form-control">
            </div>
            <div class="col-12 d-grid">
              <button class="btn btn-primary">Aggiungi</button>
            </div>
          </form>
          <p class="text-muted small mt-2">I periodi indicati bloccano la selezione del vigile nelle date corrispondenti in fase di inserimento/modifica addestramenti.</p>
        </div>
      </div>
    </div>

    <div class="col-lg-7">
      <div class="card shadow-sm">
        <div class="card-body">
          <h5 class="card-title">Storico infortuni</h5>
          <table class="table table-sm align-middle">
            <thead>
              <tr>
                <th>#</th>
                <th>Vigile</th>
                <th>Periodo</th>
                <th>Note</th>
                <th class="text-end">Azioni</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $i=>$r):
                $id  = (int)($r['id'] ?? 0);
                $vid = (int)($r['vigile_id'] ?? 0);
                $dal = (string)($r['dal'] ?? '');
                $al  = (string)($r['al'] ?? '');
                $note= (string)($r['note'] ?? '');
                $nome = isset($vigById[$vid]) ? (($vigById[$vid]['cognome']??'').' '.($vigById[$vid]['nome']??'')) : 'N/A';
              ?>
                <tr>
                  <td><?= $i+1 ?></td>
                  <td><?= htmlspecialchars($nome) ?></td>
                  <td><?= htmlspecialchars($dal.' → '.$al) ?></td>
                  <td><?= htmlspecialchars($note ?: '—') ?></td>
                  <td class="text-end">
                    <button
                      type="button"
                      class="btn btn-sm btn-outline-primary btn-edit"
                      data-id="<?= $id ?>"
                      data-vigile="<?= $vid ?>"
                      data-dal="<?= htmlspecialchars($dal, ENT_QUOTES) ?>"
                      data-al="<?= htmlspecialchars($al, ENT_QUOTES) ?>"
                      data-note="<?= htmlspecialchars($note, ENT_QUOTES) ?>"
                      data-bs-toggle="modal"
                      data-bs-target="#modalEdit"
                    >Modifica</button>
                    <form method="post" class="d-inline" onsubmit="return confirm('Eliminare il periodo selezionato?');">
                      <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= $id ?>">
                      <button class="btn btn-sm btn-outline-danger">Elimina</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (empty($rows)): ?>
                <tr><td colspan="5" class="text-muted">Nessun infortunio inserito.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

  </div>
</div>

<!-- Modale Modifica -->
<div class="modal fade" id="modalEdit" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="id" id="editId">
      <div class="modal-header">
        <h5 class="modal-title">Modifica periodo</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
      </div>
      <div class="modal-body">
        <div class="row g-2">
          <div class="col-12">
            <label class="form-label">Vigile</label>
            <select name="vigile_id" id="editVigile" class="form-select" required>
              <?php foreach ($vigili as $v): ?>
                <option value="<?= (int)$v['id'] ?>">
                  <?= htmlspecialchars(($v['cognome']??'').' '.($v['nome']??'')) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-6">
            <label class="form-label">Dal</label>
            <input type="date" name="dal" id="editDal" class="form-control" required>
          </div>
          <div class="col-6">
            <label class="form-label">Al</label>
            <input type="date" name="al" id="editAl" class="form-control" required>
          </div>
          <div class="col-12">
            <label class="form-label">Note (opz.)</label>
            <input type="text" name="note" id="editNote" class="form-control">
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annulla</button>
        <button class="btn btn-primary">Salva</button>
      </div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.querySelectorAll('.btn-edit').forEach(btn => {
  btn.addEventListener('click', () => {
    document.getElementById('editId').value = btn.dataset.id || '';
    const sel = document.getElementById('editVigile');
    const vig = btn.dataset.vigile || '';
    Array.from(sel.options).forEach(o => o.selected = (o.value === String(vig)));
    document.getElementById('editDal').value  = btn.dataset.dal || '';
    document.getElementById('editAl').value   = btn.dataset.al || '';
    document.getElementById('editNote').value = btn.dataset.note || '';
  });
});
</script>
</body>
</html>
