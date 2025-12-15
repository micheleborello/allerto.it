<?php
// vigili_contatti.php — Modifica rapida username/email dei vigili (scrive vigili.json)
ob_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__.'/auth.php';
require_once __DIR__.'/tenant_bootstrap.php';
require_once __DIR__.'/storage.php';

if (session_status() === PHP_SESSION_NONE) session_start();

// Gate: superadmin o permesso edit:personale
$ok = false;
if (function_exists('auth_is_superadmin') && auth_is_superadmin()) $ok = true;
if (function_exists('auth_can') && auth_can('edit:personale'))    $ok = true;
if (!$ok && function_exists('require_tenant_user')) require_tenant_user();
if (!$ok && function_exists('auth_can') && !auth_can('edit:personale')) { http_response_code(403); exit('Accesso negato.'); }

// Slug attivo
$slug = function_exists('tenant_active_slug') ? tenant_active_slug() : ($_SESSION['tenant_slug'] ?? '');
$slug = preg_replace('/[^a-z0-9_-]/i','', (string)$slug);
if ($slug==='') { http_response_code(500); exit('Tenant mancante.'); }

$vigiliPath = __DIR__."/data/{$slug}/vigili.json";

// ===== JSON helpers (definiti solo se mancano) =====
if (!function_exists('json_load_relaxed')) {
  function json_load_relaxed($p){
    if (!is_file($p)) return [];
    $s = @file_get_contents($p); if ($s===false) return [];
    $s = preg_replace('/^\xEF\xBB\xBF/', '', $s);          // BOM
    $s = preg_replace('/,\s*([\}\]])/m', '$1', $s);        // trailing commas
    $j = json_decode($s, true);
    return is_array($j)?$j:[];
  }
}
if (!function_exists('json_save_atomic')) {
  function json_save_atomic($p,$data){
    @mkdir(dirname($p),0775,true);
    $j = json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
    if ($j===false) throw new RuntimeException(json_last_error_msg());
    $tmp = $p.'.tmp_'.bin2hex(random_bytes(6));
    if (@file_put_contents($tmp,$j,LOCK_EX)===false){ @unlink($tmp); throw new RuntimeException('write failed'); }
    @chmod($tmp,0664); @rename($tmp,$p);
  }
}

if (empty($_SESSION['_csrf_vc'])) $_SESSION['_csrf_vc'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['_csrf_vc'];

$err=''; $okmsg='';

$vigili = json_load_relaxed($vigiliPath);
usort($vigili, fn($a,$b)=>strcasecmp(($a['cognome']??'').' '.($a['nome']??''), ($b['cognome']??'').' '.($b['nome']??'')));

if ($_SERVER['REQUEST_METHOD']==='POST') {
  try {
    if (!hash_equals($CSRF, $_POST['csrf'] ?? '')) throw new Exception('CSRF non valido.');
    $rows = $_POST['r'] ?? [];
    if (!is_array($rows)) $rows = [];

    // indicizza per id
    $map = [];
    foreach ($vigili as $i=>$v) { $map[(int)($v['id'] ?? 0)] = $i; }

    foreach ($rows as $idStr=>$r) {
      $id = (int)$idStr;
      if (!isset($map[$id])) continue;
      $i = $map[$id];
      $u = strtolower(trim((string)($r['username'] ?? '')));
      $m = trim((string)($r['email'] ?? ''));

      if ($u !== '')  $vigili[$i]['username'] = $u;
      if ($m !== '')  $vigili[$i]['email']    = $m;
      if ($m === '')  unset($vigili[$i]['email']); // se lasci vuoto, rimuovo il campo
    }

    json_save_atomic($vigiliPath, $vigili);
    $okmsg = 'Contatti aggiornati.';
  } catch(Throwable $ex) { $err = $ex->getMessage(); }
}

// escape
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Vigili — Contatti</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4">
  <h1 class="h5 mb-3">Vigili — username &amp; email <small class="text-muted">(<?= e($slug) ?>)</small></h1>
  <?php if ($okmsg): ?><div class="alert alert-success py-2"><?= e($okmsg) ?></div><?php endif; ?>
  <?php if ($err):   ?><div class="alert alert-danger py-2"><?= e($err) ?></div><?php endif; ?>

  <form method="post" class="card shadow-sm">
    <input type="hidden" name="csrf" value="<?= e($CSRF) ?>">
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0">
        <thead>
          <tr class="table-light">
            <th style="width:70px;">ID</th>
            <th>Vigile</th>
            <th style="min-width:220px;">Username</th>
            <th style="min-width:260px;">Email</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($vigili)): ?>
            <tr><td colspan="4" class="text-muted">Nessun vigile in archivio.</td></tr>
          <?php else: foreach ($vigili as $v): ?>
            <tr>
              <td><code><?= (int)($v['id'] ?? 0) ?></code></td>
              <td><?= e(trim(($v['cognome'] ?? '').' '.($v['nome'] ?? ''))) ?></td>
              <td><input class="form-control form-control-sm" name="r[<?= (int)($v['id'] ?? 0) ?>][username]" value="<?= e($v['username'] ?? '') ?>" placeholder="es. rossi.marco"></td>
              <td><input class="form-control form-control-sm" name="r[<?= (int)($v['id'] ?? 0) ?>][email]"    value="<?= e($v['email'] ?? '') ?>"    placeholder=""></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <div class="p-3 border-top bg-light d-flex gap-2">
      <button class="btn btn-primary">Salva</button>
      <a class="btn btn-outline-secondary" href="personale.php">Torna a personale</a>
    </div>
  </form>

  <p class="mt-3 small text-muted">
    Il reset password dei volontari usa <code>vigili.json</code> per recuperare l’email del vigile con lo stesso <b>username</b> dell’utente.
  </p>
</div>
</body>
</html>