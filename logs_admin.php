<?php
// logs_admin.php — Audit log solo per Capo (o admin:perms)
require __DIR__.'/auth.php';
require __DIR__.'/tenant_bootstrap.php';
require_tenant_user();
require __DIR__.'/audit_log.php';

if (!(function_exists('auth_is_capo') && auth_is_capo()) && !(function_exists('auth_can') && auth_can('admin:perms'))) {
  http_response_code(403);
  exit('Accesso negato.');
}

// CSRF minimale
if (empty($_SESSION['_csrf_logs'])) $_SESSION['_csrf_logs'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['_csrf_logs'];

$all = audit_log_read_all();

// Azioni disponibili (derive dal file)
$actions = [];
foreach ($all as $r) { $a = (string)($r['action'] ?? ''); if ($a!=='') $actions[$a]=1; }
$actions = array_keys($actions); sort($actions, SORT_NATURAL | SORT_FLAG_CASE);

// Query params
$q_action  = trim((string)($_GET['action'] ?? ''));
$q_user    = trim((string)($_GET['user'] ?? ''));
$q_search  = trim((string)($_GET['q'] ?? ''));
$q_from    = trim((string)($_GET['from'] ?? ''));
$q_to      = trim((string)($_GET['to'] ?? ''));
$q_limit   = max(50, (int)($_GET['limit'] ?? 500)); // default 500 righe

// Filtra
$rows = array_filter($all, function($r) use($q_action,$q_user,$q_search,$q_from,$q_to){
  if ($q_action !== '' && (string)($r['action'] ?? '') !== $q_action) return false;
  if ($q_user   !== '' && strcasecmp((string)($r['user'] ?? ''), $q_user) !== 0) return false;

  // filtro data (YYYY-MM-DD)
  $ts = (int)($r['ts_unix'] ?? 0);
  if ($q_from && $ts && $ts < strtotime($q_from.' 00:00:00')) return false;
  if ($q_to   && $ts && $ts > strtotime($q_to.' 23:59:59')) return false;

  if ($q_search !== '') {
    $h = json_encode($r, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if (mb_stripos($h, $q_search, 0, 'UTF-8') === false) return false;
  }
  return true;
});

// Ordina per ts desc
usort($rows, fn($a,$b)=> ($b['ts_unix'] ?? 0) <=> ($a['ts_unix'] ?? 0));
$rows = array_slice($rows, 0, $q_limit);

// Export CSV
if (isset($_GET['export']) && $_GET['export']==='csv') {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="audit_'.$q_from.'_'.$q_to.'.csv"');
  $out = fopen('php://output', 'w');
  fputcsv($out, ['ts','action','user','is_capo','ip','slug','extra_json']);
  foreach ($rows as $r) {
    fputcsv($out, [
      (string)($r['ts'] ?? ''),
      (string)($r['action'] ?? ''),
      (string)($r['user'] ?? ''),
      (int)($r['is_capo'] ?? 0),
      (string)($r['ip'] ?? ''),
      (string)($r['slug'] ?? ''),
      json_encode($r['extra'] ?? new stdClass(), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
    ]);
  }
  fclose($out);
  exit;
}

// Purge (POST)
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['_csrf'] ?? '') === $csrf && ($_POST['do'] ?? '')==='purge') {
  @unlink(AUDIT_LOG);
  header('Location: logs_admin.php?purged=1');
  exit;
}
?>
<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <title>Log eventi — <?php echo htmlspecialchars(tenant_active_name(),ENT_QUOTES); ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    code.small { font-size:.85em; }
    .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
    .table-fixed { table-layout: fixed; }
    .col-extra { width: 45%; }
    .col-ts { width: 180px; }
    .col-user { width: 180px; }
    .col-action { width: 200px; }
  </style>
</head>
<body class="bg-light">
<?php include __DIR__.'/_menu.php'; ?>

<div class="container py-4">
  <div class="d-flex align-items-center gap-2 mb-3">
    <h1 class="mb-0">Log eventi</h1>
    <span class="badge text-bg-secondary">Solo Capo</span>
  </div>

  <?php if (isset($_GET['purged'])): ?>
    <div class="alert alert-warning">Log azzerato.</div>
  <?php endif; ?>

  <form class="row g-2 align-items-end mb-3" method="get">
    <div class="col-md-3">
      <label class="form-label">Azione</label>
      <select name="action" class="form-select">
        <option value="">— tutte —</option>
        <?php foreach ($actions as $a): ?>
          <option value="<?=htmlspecialchars($a)?>" <?= $q_action===$a?'selected':''?>><?=htmlspecialchars($a)?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <label class="form-label">Utente</label>
      <input type="text" class="form-control" name="user" value="<?=htmlspecialchars($q_user)?>" placeholder="username">
    </div>
    <div class="col-md-2">
      <label class="form-label">Dal</label>
      <input type="date" class="form-control" name="from" value="<?=htmlspecialchars($q_from)?>">
    </div>
    <div class="col-md-2">
      <label class="form-label">Al</label>
      <input type="date" class="form-control" name="to" value="<?=htmlspecialchars($q_to)?>">
    </div>
    <div class="col-md-2">
      <label class="form-label">Cerca</label>
      <input type="text" class="form-control" name="q" value="<?=htmlspecialchars($q_search)?>" placeholder="testo libero">
    </div>
    <div class="col-md-1">
      <label class="form-label">Limite</label>
      <input type="number" min="50" class="form-control" name="limit" value="<?= (int)$q_limit ?>">
    </div>
    <div class="col-12 d-flex gap-2">
      <button class="btn btn-primary">Filtra</button>
      <a class="btn btn-outline-secondary" href="logs_admin.php">Reset</a>
      <a class="btn btn-success ms-auto" href="?<?=http_build_query(array_merge($_GET,['export'=>'csv']))?>">Download CSV</a>
      <form method="post" class="ms-2" onsubmit="return confirm('Sicuro di voler azzerare il log? Azione irreversibile.');">
        <input type="hidden" name="_csrf" value="<?=$csrf?>">
        <input type="hidden" name="do" value="purge">
        <button class="btn btn-outline-danger">Svuota log</button>
      </form>
    </div>
  </form>

  <div class="card shadow-sm">
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-sm align-middle table-striped table-fixed">
          <thead class="table-light">
            <tr>
              <th class="col-ts">Timestamp</th>
              <th class="col-action">Azione</th>
              <th class="col-user">Utente</th>
              <th>IP</th>
              <th>Slug</th>
              <th class="col-extra">Dettagli</th>
            </tr>
          </thead>
          <tbody class="mono">
            <?php if (empty($rows)): ?>
              <tr><td colspan="6" class="text-muted">Nessun evento.</td></tr>
            <?php else: foreach ($rows as $r):
              $extra = $r['extra'] ?? [];
              $extraPretty = json_encode($extra, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
            ?>
              <tr>
                <td><code class="small"><?=htmlspecialchars($r['ts'] ?? '')?></code></td>
                <td><span class="badge text-bg-primary"><?=htmlspecialchars($r['action'] ?? '')?></span></td>
                <td><?=htmlspecialchars($r['user'] ?? '')?><?= !empty($r['is_capo']) ? ' <span class="badge text-bg-warning">Capo</span>' : '' ?></td>
                <td><code class="small"><?=htmlspecialchars($r['ip'] ?? '')?></code></td>
                <td><code class="small"><?=htmlspecialchars($r['slug'] ?? '')?></code></td>
                <td><pre class="mb-0" style="white-space:pre-wrap; word-break:break-word;"><?=htmlspecialchars($extraPretty)?></pre></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
