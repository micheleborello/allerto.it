<?php
// auth_lib.php
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__.'/storage.php'; // per load_json/save_vigili
require_once __DIR__.'/utils.php';   // se ti serve

/* ========= Base32 ========= */
function b32_encode($bin){
  $alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $out=''; $v=0; $vbits=0;
  foreach (str_split($bin) as $c){ $v=($v<<8)|ord($c); $vbits+=8; while($vbits>=5){ $out.=$alphabet[($v>>($vbits-5))&31]; $vbits-=5; } }
  if($vbits>0){ $out.=$alphabet[($v<< (5-$vbits)) &31]; }
  while(strlen($out)%8) $out.='='; return $out;
}
function b32_decode($s){
  $s=strtoupper(preg_replace('/[^A-Z2-7]/','',$s)); $alphabet=array_flip(str_split('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'));
  $v=0; $vbits=0; $out='';
  foreach(str_split($s) as $c){ if(!isset($alphabet[$c])) continue; $v=($v<<5)|$alphabet[$c]; $vbits+=5; if($vbits>=8){ $out.=chr(($v>>($vbits-8))&0xFF); $vbits-=8; } }
  return $out;
}

/* ========= TOTP ========= */
function totp_code($secret_b32, $time=null, $step=30, $digits=6){
  $secret = b32_decode($secret_b32);
  $time = $time ?? time();
  $counter = pack('N*', 0).pack('N*', floor($time/$step));
  $hash = hash_hmac('sha1', $counter, $secret, true);
  $offset = ord(substr($hash, -1)) & 0x0F;
  $bin = (ord($hash[$offset]) & 0x7F) << 24 |
         (ord($hash[$offset+1]) & 0xFF) << 16 |
         (ord($hash[$offset+2]) & 0xFF) << 8 |
         (ord($hash[$offset+3]) & 0xFF);
  $otp = $bin % (10 ** $digits);
  return str_pad((string)$otp, $digits, '0', STR_PAD_LEFT);
}
function totp_verify($secret_b32, $code, $window=1, $step=30){
  $code = preg_replace('/\s+/','', (string)$code);
  if ($secret_b32==='' || $code==='') return false;
  $now = time();
  for($i=-$window; $i<=$window; $i++){
    if (hash_equals(totp_code($secret_b32, $now + $i*$step), $code)) return true;
  }
  return false;
}

/* ========= Users in vigili.json ========= */
function auth_find_user_by_email($email){
  $email = mb_strtolower(trim((string)$email));
  $vig = load_json(VIGILI_JSON);
  foreach ($vig as $u){
    $em = mb_strtolower((string)($u['email'] ?? ''));
    if ($em !== '' && $em === $email) return $u;
  }
  return null;
}
function auth_find_user_by_id($id){
  $vig = load_json(VIGILI_JSON);
  foreach ($vig as $u){ if ((int)($u['id']??0)===(int)$id) return $u; }
  return null;
}
function auth_save_user($user){
  $vig = load_json(VIGILI_JSON);
  foreach ($vig as &$u){ if ((int)($u['id']??0)===(int)($user['id']??-1)){ $u = $user; break; } }
  unset($u); save_vigili($vig);
}

/* ========= Password ========= */
function auth_set_password($userId, $password){
  $user = auth_find_user_by_id($userId); if (!$user) return false;
  $hash = password_hash($password, PASSWORD_DEFAULT); // Argon2id se disponibile
  $user['auth'] = $user['auth'] ?? [];
  $user['auth']['password_hash'] = $hash;
  auth_save_user($user);
  return true;
}
function auth_check_password($user, $password){
  $hash = $user['auth']['password_hash'] ?? '';
  return $hash && password_verify($password, $hash);
}

/* ========= Session helpers ========= */
function auth_login_session(array $user){
  // Minimale: integra con il tuo auth.php se vuoi
  $_SESSION['user'] = [
    'id' => (int)$user['id'],
    'email' => $user['email'] ?? '',
    'nome' => trim(($user['cognome']??'').' '.($user['nome']??'')),
    'is_capo' => !empty($user['is_capo']) ? 1 : 0,
  ];
  $_SESSION['mfa_ok'] = false;
}
function auth_mark_mfa_ok(){ $_SESSION['mfa_ok'] = true; }
function auth_logout(){ $_SESSION = []; if (ini_get("session.use_cookies")) { $params = session_get_cookie_params(); setcookie(session_name(), '', time()-42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]); } session_destroy(); }

/* ========= MFA guard ========= */
function require_mfa(){
  if (empty($_SESSION['user'])) { header('Location: login.php?next='.urlencode($_SERVER['REQUEST_URI'])); exit; }
  if (empty($_SESSION['mfa_ok'])) { header('Location: totp_verify.php?next='.urlencode($_SERVER['REQUEST_URI'])); exit; }
}