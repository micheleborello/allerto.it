<?php
// logs_audit.php — elenco audit log per-tenant (solo Capo)

ob_start();
require __DIR__.'/auth.php';
require __DIR__.'/tenant_bootstrap.php';
require_tenant_user();

require __DIR__.'/event_log.php';

// Permesso: solo Capo o chi gestisce permessi
if (!(function_exists('auth_is_capo') && auth_is_capo()) && !(function_exists('auth_can') && auth_can('admin:perms'))) {
  http_response_code(403);
  exit('Accesso negato.');
}

// Debug flag
$DBG = !empty($_GET['_dbg']);

// Carica log
$rows = event_log_load();

// Statistiche file per il debug
$path = LOGS_AUDIT_JSON;
$size = is_file($path) ? (filesize($path) ?: 0) : 0;
$count = is_array($rows) ? count($rows) : 0;

// Ordinamento: più recenti in alto
usort($rows, function($a,$b){
  // confronta per ts_iso/ts desc
  $ta = $a['ts_iso'] ?? $a['ts'] ?? '';
  $tb = $b['ts_iso'] ?? $b['ts'] ?? '';
  return strcmp($tb, $ta);
});

// Helper per dettagli sintetici
function render_summary(array $r): string {
  $t = (string)($r['type'] ?? '');
  $d = is_array($r['data'] ?? null) ? $r['data'] : [];
  if ($t === 'addestramento.create') {
    $uid  = $d['sessione_uid'] ?? '';
    $dat  = $d['data'] ?? '';
    $ini  = $d['inizio_dt'] ?? '';
    $fin  = $d['fine_dt'] ?? '';
    $min  = $d['minuti'] ?? '';
    $att  = $d['attivita'] ?? '';
    $rec  = !empty($d['recupero']) ? ' (Recupero)' : '';
    $pres = $d['presenti'] ?? [];
    $np   = is_array($pres) ? count($pres) : 0;
    $nomi = [];
    if (is_array($pres)) {
      foreach ($pres as $p) {
        $nomi[] = trim(($p['nome'] ?? '') ?: ('ID '.$p['vigile_id']));
      }
    }
    return "Sessione {$uid} — {$dat} • {$ini} → {$fin} • {$min}' • {$att}{$rec} • Presenti: {$np}".($nomi?(' — '.implode(', ', $nomi)):'');
  }
  if ($t === 'checkin.ok') {
    $nome = $d['nome'] ?? '';
    $uid  = $d['sessione_uid'] ?? '';
    $dat  = $d['data'] ?? '';
    $min  = $d['minuti'] ?? '';
    $at   = $d['at'] ?? '';
    $ip   = $d['ip'] ?? '';
    return "Check-in OK — {$nome} • {$dat} ({$min}') • {$at} • UID {$uid} • IP {$ip}";
  }
  if ($t === 'checkin.block_infortunio') {
    $nome = $d['nome'] ?? '';
    $dat  = $d['data'] ?? '';
    $at   = $d['at'] ?? '';
    return "Check-in BLOCCATO per infortunio — {$nome} • data sessione {$dat} • {$at}";
  }
  // fallback
  return json_encode($d, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}

include __DIR__.'/_menu.php';
?>
<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <title>Audit Log — <?= htmlspecialchars(tenant_active_name()); ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    code.small { font-size: .85em; }
    .payload { white-space: pre-wrap; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: .85em; background:#f8f9fa; border:1px solid #e9ecef; border-radius:.5rem; padding:.5rem .75rem; }
  </style>
</head>
<body class="bg-light">
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="mb-0">Audit Log</h1>
    <span class="text-muted"><?= htmlspecialchars(tenant_active_name()); ?></span>
  </div>

  <?php if ($DBG): ?>
    <div class="alert alert-info">
      <div><b>DEBUG</b> — user: <code><?= htmlspecialchars($_SESSION['user']['username'] ?? $_SESSION['username'] ?? 'n/d') ?></code> • slug: <code><?= htmlspecialchars(tenant_active_slug() ?? 'n/d') ?></code></div>
      <div>file: <code><?= htmlspecialchars($path) ?></code> • size: <code><?= (int)$size ?></code> bytes • records: <code><?= (int)$count ?></code></div>
    </div>
  <?php endif; ?>

  <?php if ($count === 0): ?>
    <div class="alert alert-secondary">Nessun evento registrato al momento.</div>
  <?php else: ?>
    <div class="card shadow-sm">
      <div class="card-body">
        <div class="table-responsive">
          <table class="table table-sm align-middle">
            <thead class="table-light">
              <tr>
                <th style="width:170px;">Quando</th>
                <th style="width:210px;">Tipo</th>
                <th style="width:160px;">Utente</th>
                <th>Dettagli</th>
                <th style="width:100px;">Raw</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $r): 
                $ts   = (string)($r['ts'] ?? $r['ts_iso'] ?? '');
                $type = (string)($r['type'] ?? '');
                $user = (string)($r['user'] ?? '');
                $sum  = render_summary($r);
              ?>
              <tr>
                <td><code class="small"><?= htmlspecialchars($ts) ?></code></td>
                <td><span class="badge text-bg-primary"><?= htmlspecialchars($type) ?></span></td>
                <td><?= htmlspecialchars($user ?: '—') ?></td>
                <td><?= htmlspecialchars($sum) ?></td>
                <td>
                  <?php $id = 'raw'.substr(md5(json_encode($r)),0,8); ?>
                  <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#<?= $id ?>">JSON</button>
                </td>
              </tr>
              <tr class="collapse" id="<?= $id ?>">
                <td colspan="5">
                  <div class="payload"><?= htmlspecialchars(json_encode($r, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)) ?></div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div class="text-muted small">Ultimi <?= (int)$count ?> eventi (più recenti in alto).</div>
      </div>
    </div>
  <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>