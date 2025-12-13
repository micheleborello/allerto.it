<?php
ob_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

require __DIR__.'/auth.php';
require __DIR__.'/tenant_bootstrap.php';
if (function_exists('require_tenant_user')) require_tenant_user();
if (function_exists('require_mfa')) require_mfa();

if (session_status() === PHP_SESSION_NONE) session_start();

require __DIR__.'/storage.php';

// Permessi (se il tuo sistema permessi è attivo)
if (function_exists('require_perm')) {
  require_perm('view:attivita');
  require_perm('edit:attivita');
}

// ===== CSRF =====
if (empty($_SESSION['_csrf_attivita'])) {
  $_SESSION['_csrf_attivita'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['_csrf_attivita'];

// Menu opzionale
$menuPath = __DIR__.'/_menu.php';
if (is_file($menuPath)) include $menuPath;

/* -----------------------------
   Tenant dallo slug corrente
   ----------------------------- */
$tenant = $_SESSION['tenant_slug'] ?? $_SESSION['CASERMA_SLUG'] ?? (function_exists('storage_current_tenant') ? storage_current_tenant() : 'default');

/* -----------------------------
   Catalogo attività con TIPO
   ----------------------------- */

const TIPI_ATT = ['pratica','teoria','varie'];

function norm_tipo($t){
  $t = strtolower(trim((string)$t));
  return in_array($t, TIPI_ATT, true) ? $t : 'varie';
}

/** Legge il catalogo dal tenant: ritorna SEMPRE [{label,tipo}] */
function load_attivita_catalogo(string $tenant): array {
  if (!function_exists('read_json')) return [];
  $rows = read_json('attivita.json', $tenant);
  if (!is_array($rows)) $rows = [];

  $out = [];
  foreach ($rows as $x) {
    if (is_string($x)) {
      $lab = trim($x);
      if ($lab !== '') $out[] = ['label'=>$lab, 'tipo'=>'varie'];
    } elseif (is_array($x)) {
      $lab = trim((string)($x['label'] ?? ''));
      $tp  = norm_tipo($x['tipo'] ?? 'varie');
      if ($lab !== '') $out[] = ['label'=>$lab, 'tipo'=>$tp];
    }
  }
  // dedup case-insensitive
  $seen=[]; $clean=[];
  foreach ($out as $r){
    $k = mb_strtolower($r['label'],'UTF-8');
    if (isset($seen[$k])) continue;
    $seen[$k]=1;
    $clean[]=$r;
  }
  usort($clean, fn($a,$b)=> strcasecmp($a['label'],$b['label']));
  return $clean;
}

/** Salva nel nuovo formato [{label, tipo}] nel tenant */
function save_attivita_catalogo(array $items, string $tenant): void {
  if (!function_exists('write_json')) return;
  $norm=[]; $seen=[];
  foreach ($items as $r) {
    $lab = trim((string)($r['label'] ?? ''));
    if ($lab==='') continue;
    $tp  = norm_tipo($r['tipo'] ?? 'varie');
    $k   = mb_strtolower($lab,'UTF-8');
    if (isset($seen[$k])) continue;
    $seen[$k]=1;
    $norm[]=['label'=>$lab,'tipo'=>$tp];
  }
  usort($norm, fn($a,$b)=> strcasecmp($a['label'],$b['label']));
  write_json('attivita.json', $norm, $tenant);
}

/** Aggiunge (o aggiorna il tipo se già esiste) nel tenant */
function add_to_attivita_catalogo(string $label, string $tipo, string $tenant): void {
  $label = trim($label); if ($label==='') return;
  $tipo = norm_tipo($tipo);
  $all = load_attivita_catalogo($tenant);
  $kNew = mb_strtolower($label,'UTF-8');
  $found=false;
  foreach ($all as &$r) {
    if (mb_strtolower($r['label'],'UTF-8') === $kNew) {
      $r['tipo'] = $tipo; // aggiorna tipo
      $found=true; break;
    }
  }
  unset($r);
  if (!$found) $all[]=['label'=>$label,'tipo'=>$tipo];
  save_attivita_catalogo($all, $tenant);
}

/* ----------- POST ----------- */
$err = $_GET['err'] ?? '';
$msg = $_GET['msg'] ?? '';

if ($_SERVER['REQUEST_METHOD']==='POST') {
  // ===== Verifica CSRF =====
  if (($_POST['_csrf'] ?? '') !== $csrf) {
    http_response_code(400);
    exit('CSRF non valido.');
  }

  $act = $_POST['action'] ?? '';
  if ($act==='add') {
    $lbl  = trim((string)($_POST['label'] ?? ''));
    $tipo = norm_tipo($_POST['tipo'] ?? 'varie');
    if ($lbl==='') { header('Location: attivita.php?err=val'); exit; }
    add_to_attivita_catalogo($lbl, $tipo, $tenant);
    header('Location: attivita.php?msg=added'); exit;
  }

  if ($act==='delete') {
    $lbl = (string)($_POST['label'] ?? '');
    $all = load_attivita_catalogo($tenant);
    $all = array_values(array_filter($all, fn($x)=> ($x['label'] ?? '') !== $lbl));
    save_attivita_catalogo($all, $tenant);
    header('Location: attivita.php?msg=deleted'); exit;
  }

  if ($act==='update') {
    $old  = trim((string)($_POST['old'] ?? ''));
    $new  = trim((string)($_POST['new'] ?? ''));
    $tipo = norm_tipo($_POST['tipo'] ?? 'varie');
    if ($old==='' || $new==='') { header('Location: attivita.php?err=val'); exit; }

    $all = load_attivita_catalogo($tenant);

    // evita duplicati se cambi solo in maiuscole/minuscole
    $kNew = mb_strtolower($new,'UTF-8');
    foreach($all as $ex){
      if (mb_strtolower($ex['label'] ?? '','UTF-8')===$kNew && mb_strtolower($old,'UTF-8')!==$kNew) {
        header('Location: attivita.php?err=dup'); exit;
      }
    }
    foreach ($all as &$r) {
      if (($r['label'] ?? '') === $old) { $r['label'] = $new; $r['tipo'] = $tipo; }
    }
    unset($r);
    save_attivita_catalogo($all, $tenant);
    header('Location: attivita.php?msg=updated'); exit;
  }
}

$labels = load_attivita_catalogo($tenant);
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Programma attività addestrative</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
.badge-tipo{font-weight:600}
.badge-tipo.pratica{background:#d1e7dd;color:#0f5132}
.badge-tipo.teoria{background:#cff4fc;color:#055160}
.badge-tipo.varie{background:#f8d7da;color:#842029}
</style>
</head>
<body class="bg-light">
<div class="container py-4">
  <div class="d-flex align-items-center justify-content-between">
    <h1 class="mb-0">Gestione Programma Didattico</h1>
    <a class="btn btn-outline-secondary" href="index.php">← Torna</a>
  </div>

  <?php if ($err): ?>
    <div class="alert alert-danger mt-3">
      <?php
        echo match($err) {
          'val' => 'Valore non valido.',
          'dup' => 'Esiste già un’attività con quel nome.',
          default => 'Errore.'
        };
      ?>
    </div>
  <?php endif; ?>
  <?php if ($msg): ?>
    <div class="alert alert-success mt-3">
      <?php
        echo match($msg) {
          'added'   => 'Attività aggiunta.',
          'deleted' => 'Attività eliminata.',
          'updated' => 'Attività aggiornata.',
          default   => 'OK.'
        };
      ?>
    </div>
  <?php endif; ?>

  <div class="row g-3 mt-2">
    <!-- Aggiungi -->
    <div class="col-lg-5">
      <div class="card shadow-sm">
        <div class="card-body">
          <h5 class="card-title">Aggiungi attività</h5>
          <form method="post" class="row g-2">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="add">
            <div class="col-8">
              <input type="text" name="label" class="form-control" placeholder="Es: Autorespiratori" required>
            </div>
            <div class="col-4">
              <select name="tipo" class="form-select" required>
                <option value="pratica">Pratica</option>
                <option value="teoria">Teoria</option>
                <option value="varie">Varie</option>
              </select>
            </div>
            <div class="col-12">
              <button class="btn btn-primary w-100">Aggiungi</button>
            </div>
          </form>
          <div class="text-muted small mt-2">Le attività qui elencate compariranno nella tendina in inserimento/modifica addestramenti, raggruppate per tipo.</div>
        </div>
      </div>
    </div>

    <!-- Elenco + filtro -->
    <div class="col-lg-7">
      <div class="card shadow-sm">
        <div class="card-body">
          <div class="d-flex align-items-center justify-content-between">
            <h5 class="card-title mb-0">Elenco</h5>
            <div class="d-flex gap-2">
              <select id="filtroTipo" class="form-select form-select-sm">
                <option value="">Tutti i tipi</option>
                <option value="pratica">Solo Pratica</option>
                <option value="teoria">Solo Teoria</option>
                <option value="varie">Solo Varie</option>
              </select>
            </div>
          </div>
          <table class="table table-sm align-middle mt-2" id="tabAtt">
            <thead><tr><th>#</th><th>Attività</th><th>Tipo</th><th class="text-end">Azioni</th></tr></thead>
            <tbody>
              <?php if (empty($labels)): ?>
                <tr><td colspan="4" class="text-muted">Nessuna attività registrata.</td></tr>
              <?php else: foreach ($labels as $i=>$r): $lbl=$r['label']; $tp=$r['tipo']; ?>
                <tr data-tipo="<?= htmlspecialchars($tp) ?>">
                  <td><?= $i+1 ?></td>
                  <td><?= htmlspecialchars($lbl) ?></td>
                  <td>
                    <span class="badge badge-tipo <?= htmlspecialchars($tp) ?>">
                      <?= $tp==='pratica'?'Pratica':($tp==='teoria'?'Teoria':'Varie') ?>
                    </span>
                  </td>
                  <td class="text-end">
                    <form method="post" class="d-inline-block" onsubmit="return confirm('Eliminare &quot;<?= htmlspecialchars($lbl) ?>&quot;?');">
                      <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="label" value="<?= htmlspecialchars($lbl, ENT_QUOTES) ?>">
                      <button class="btn btn-sm btn-outline-danger">Elimina</button>
                    </form>
                    <button class="btn btn-sm btn-outline-primary btn-edit"
                            data-old="<?= htmlspecialchars($lbl, ENT_QUOTES) ?>"
                            data-tipo="<?= htmlspecialchars($tp) ?>"
                            data-bs-toggle="modal" data-bs-target="#modalEdit">Modifica</button>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Modal Modifica -->
<div class="modal fade" id="modalEdit" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="old" id="md_old">
      <div class="modal-header">
        <h5 class="modal-title">Modifica attività</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
      </div>
      <div class="modal-body">
        <label class="form-label">Nome</label>
        <input type="text" class="form-control" name="new" id="md_new" required>
        <label class="form-label mt-2">Tipo</label>
        <select class="form-select" name="tipo" id="md_tipo">
          <option value="pratica">Pratica</option>
          <option value="teoria">Teoria</option>
          <option value="varie">Varie</option>
        </select>
      </div>
      <div class="modal-footer">
        <button class="btn btn-primary">Salva</button>
      </div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Modale "Modifica"
document.querySelectorAll('.btn-edit').forEach(b=>{
  b.addEventListener('click', ()=>{
    document.getElementById('md_old').value  = b.dataset.old || '';
    document.getElementById('md_new').value  = b.dataset.old || '';
    document.getElementById('md_tipo').value = b.dataset.tipo || 'varie';
  });
});

// Filtro tipo client-side
const filtro = document.getElementById('filtroTipo');
filtro?.addEventListener('change', ()=>{
  const val = filtro.value;
  document.querySelectorAll('#tabAtt tbody tr').forEach(tr=>{
    const t = tr.getAttribute('data-tipo') || '';
    tr.style.display = (!val || val===t) ? '' : 'none';
  });
});
</script>
</body>
</html>