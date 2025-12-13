<?php
// vol_profile.php — profilo del vigile (modifica email, logout) — usa vigili.json

require __DIR__.'/tenant_bootstrap.php';
require __DIR__.'/storage.php';
if (session_status() === PHP_SESSION_NONE) session_start();

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$vol = $_SESSION['vol_user'] ?? null;
if (!$vol) { header('Location: vol_login.php'); exit; }

$slug = preg_replace('/[^a-z0-9_-]/i','', (string)($vol['slug'] ?? ''));
$vid  = (int)($vol['id'] ?? 0);

if (empty($_SESSION['csrf_vp'])) $_SESSION['csrf_vp'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['csrf_vp'];

// Helpers JSON
function json_load_relaxed($p){
  if (!is_file($p)) return [];
  $s = @file_get_contents($p); if ($s===false) return [];
  $s = preg_replace('/^\xEF\xBB\xBF/','',$s);
  $s = preg_replace('#/\*.*?\*/#s','',$s);
  $s = preg_replace('#//.*$#m','',$s);
  $s = preg_replace('/,\s*([\}\]])/','$1',$s);
  $j = json_decode($s,true);
  return is_array($j)?$j:[];
}
function json_save_atomic($path,$data){
  $dir = dirname($path); if(!is_dir($dir)) @mkdir($dir,0775,true);
  $json = json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
  $tmp  = $path.'.tmp_'.uniqid('',true);
  if (@file_put_contents($tmp,$json,LOCK_EX)===false) { @unlink($tmp); throw new RuntimeException('write failed'); }
  @chmod($tmp,0664); @rename($tmp,$path);
}

$vigiliPath = __DIR__."/data/{$slug}/vigili.json";
$vigili = json_load_relaxed($vigiliPath);

// trova me
$meIndex = -1;
foreach ($vigili as $i=>$v){ if ((int)($v['id']??0) === $vid){ $meIndex=$i; break; } }
$me = ($meIndex>=0) ? $vigili[$meIndex] : null;

$err=''; $ok='';
if ($_SERVER['REQUEST_METHOD']==='POST'){
  if (!hash_equals($CSRF, $_POST['csrf'] ?? '')) { http_response_code(400); die('Bad CSRF'); }
  $new = trim((string)($_POST['email'] ?? ''));
  if (!filter_var($new, FILTER_VALIDATE_EMAIL)) {
    $err = 'Inserisci un indirizzo email valido.';
  } elseif ($meIndex<0) {
    $err = 'Profilo non trovato.';
  } else {
    $vigili[$meIndex]['email'] = $new;
    json_save_atomic($vigiliPath,$vigili);
    $_SESSION['vol_user']['email']=$new;
    $me['email']=$new;
    $ok = 'Email aggiornata.';
  }
}
?>
<!doctype html>
<html lang="it"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Profilo vigile</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4" style="max-width:680px">
  <div class="card shadow-sm">
    <div class="card-body">
      <h1 class="h5 mb-3">Il mio profilo</h1>
      <?php if($err): ?><div class="alert alert-danger py-2"><?=e($err)?></div><?php endif; ?>
      <?php if($ok):  ?><div class="alert alert-success py-2"><?=e($ok)?></div><?php endif; ?>

      <dl class="row">
        <dt class="col-sm-3">Distaccamento</dt><dd class="col-sm-9"><?= e($slug) ?></dd>
        <dt class="col-sm-3">ID</dt><dd class="col-sm-9"><?= (int)$vid ?></dd>
        <dt class="col-sm-3">Email</dt><dd class="col-sm-9"><?= e($me['email'] ?? '') ?></dd>
      </dl>

      <hr>
      <form method="post" class="row g-2 align-items-end">
        <input type="hidden" name="csrf" value="<?= e($CSRF) ?>">
        <div class="col-sm-9">
          <label class="form-label">Aggiorna email</label>
          <input class="form-control" type="email" name="email" value="<?= e($me['email'] ?? '') ?>" required>
        </div>
        <div class="col-sm-3 d-grid">
          <button class="btn btn-primary">Salva</button>
        </div>
      </form>

      <div class="mt-3 d-flex gap-2">
        <a class="btn btn-outline-secondary" href="index.php">← Torna all’app</a>
        <a class="btn btn-outline-danger" href="vol_logout.php">Logout</a>
      </div>
    </div>
  </div>
</div>
</body></html>