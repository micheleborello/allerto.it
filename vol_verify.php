<?php
// vol_verify.php — Verifica OTP di accesso vigile + remember-me

require __DIR__.'/tenant_bootstrap.php';
require __DIR__.'/storage.php';
require_once __DIR__.'/mfa_lib.php';

if (session_status() === PHP_SESSION_NONE) session_start();

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$slug = isset($_GET['slug']) ? preg_replace('/[^a-z0-9_-]/i','', $_GET['slug']) : '';
$next = $_GET['next'] ?? 'index.php';

if (empty($_SESSION['csrf_volv'])) $_SESSION['csrf_volv'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['csrf_volv'];

// remember-me helpers
$cookieName = 'volrm';
$rmDays = 90;
function tokens_path($slug){ return __DIR__."/data/{$slug}/_vol_tokens.json"; }
function load_tokens($slug){
  $p=tokens_path($slug); if(!is_file($p)) return [];
  $j=@file_get_contents($p); $a=$j?json_decode($j,true):[];
  $now=time();
  $a=is_array($a)?array_values(array_filter($a,fn($r)=> (int)($r['exp']??0) > $now)):[];
  return $a;
}
function save_tokens($slug,$a){
  $p=tokens_path($slug);
  if(!is_dir(dirname($p))) @mkdir(dirname($p),0775,true);
  @file_put_contents($p,json_encode(array_values($a),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));
  @chmod($p,0664);
}

$err = '';

if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!hash_equals($CSRF, $_POST['csrf'] ?? '')) { http_response_code(400); die('Bad CSRF'); }
  $code = trim((string)($_POST['code'] ?? ''));

  $real = (string)($_SESSION['vol_otp'] ?? '');
  $gen  = (int)($_SESSION['vol_otp_gen'] ?? 0);
  $tmp  = $_SESSION['vol_user_tmp'] ?? null;

  if (!$tmp || empty($tmp['id']) || ($tmp['slug'] ?? '') !== $slug) {
    $err = 'Sessione non valida. Rifai il login.';
  } elseif ($real === '' || (time()-$gen) > (defined('MFA_OTP_TTL')?MFA_OTP_TTL:600)) {
    $err = 'Codice scaduto. Richiedi un nuovo codice.';
  } elseif (!preg_match('/^\d{6}$/', $code) || !hash_equals($real, $code)) {
    $err = 'Codice errato.';
  } else {
    // OK: crea sessione "vigile"
    $_SESSION['vol_user'] = [
      'id'   => (int)$tmp['id'],
      'slug' => (string)$tmp['slug'],
      'email'=> (string)($tmp['email'] ?? ''),
      'ts'   => time(),
    ];
    // remember-me?
    if (!empty($_SESSION['vol_remember'])) {
      $raw  = bin2hex(random_bytes(32));
      $hash = hash('sha256',$raw);
      $arr  = load_tokens($slug);
      $arr[] = ['h'=>$hash,'user'=>(int)$tmp['id'],'exp'=> time()+$rmDays*86400];
      save_tokens($slug,$arr);
      // cookie HttpOnly
      setcookie(
        $cookieName,
        $slug.':'.$raw,
        [
          'expires'  => time()+$rmDays*86400,
          'path'     => '/',
          'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off'),
          'httponly' => true,
          'samesite' => 'Lax',
        ]
      );
    }

    unset($_SESSION['vol_otp'], $_SESSION['vol_otp_gen'], $_SESSION['vol_user_tmp'], $_SESSION['vol_remember']);
    header('Location: '.$next);
    exit;
  }
}
?>
<!doctype html>
<html lang="it"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Verifica codice</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>body{background:#f7f9fc}</style>
</head>
<body class="d-flex align-items-center justify-content-center" style="min-height:100vh;">
<div class="container" style="max-width:420px">
  <div class="card shadow-sm">
    <div class="card-body">
      <h1 class="h5 mb-3">Inserisci il codice</h1>
      <p class="text-muted">Controlla la tua email. Il codice scade in 10 minuti.</p>

      <?php if ($err): ?><div class="alert alert-danger py-2"><?= e($err) ?></div><?php endif; ?>

      <form method="post" class="vstack gap-2">
        <input type="hidden" name="csrf" value="<?= e($CSRF) ?>">
        <div>
          <label class="form-label">Codice a 6 cifre</label>
          <input type="text" name="code" class="form-control" inputmode="numeric" pattern="\d{6}" maxlength="6" required placeholder="••••••">
        </div>
        <button class="btn btn-success w-100">Accedi</button>
      </form>
    </div>
  </div>
  <p class="text-center mt-3"><a class="small text-decoration-none" href="vol_login.php?slug=<?= urlencode($slug) ?>&next=<?= urlencode($next) ?>">Non hai ricevuto il codice?</a></p>
</div>
</body></html>