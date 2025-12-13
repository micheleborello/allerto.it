<?php
// vol_pwd_reset.php — Password dimenticata / Primo accesso (volontari) con OTP via email

ob_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) session_start();

/* ====== BOOTSTRAP TENANT (se esiste) ====== */
@include __DIR__.'/tenant_bootstrap.php';

/* ====== CONFIG MAIL (mittente OTP) ====== */
if (!defined('MAIL_FROM')) {
  define('MAIL_FROM', 'mail@allerto.it'); // la tua casella dedicata
}
// Opzionale: forzare destinazione in test
// if (!defined('MAIL_TO_OVERRIDE')) define('MAIL_TO_OVERRIDE', 'tuo@test.it');

/* ====== UTILS ====== */
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function hval($s){ return str_replace(["\r","\n"], ' ', trim((string)$s)); }
function mimehdr($s){
  if (function_exists('mb_encode_mimeheader')) return mb_encode_mimeheader($s,'UTF-8','B',"\r\n");
  return '=?UTF-8?B?'.base64_encode($s).'?=';
}
function normalize_ascii_username(string $s): string {
  if (function_exists('iconv')) {
    $s = iconv('UTF-8', 'ASCII//TRANSLIT', $s);
  }
  $s = strtolower($s);
  $s = preg_replace('/[^a-z0-9]+/', '', $s);
  return $s;
}
function username_from_vigile(array $v): string {
  $nome = normalize_ascii_username((string)($v['nome'] ?? ''));
  $cogn = normalize_ascii_username((string)($v['cognome'] ?? ''));
  if ($nome === '' && $cogn === '') return '';
  return ($cogn ?: 'user').'.'.($nome ?: 'user');
}
function tenant_display_name(){
  if (function_exists('tenant_active_name')) {
    $n = trim((string)tenant_active_name());
    if ($n !== '') return 'Distaccamento di '.$n;
  }
  return 'AllertoGest';
}

/* ====== DISTACCAMENTI: elenco per tendina ====== */
function read_json_relaxed($file){
  if (!is_file($file)) return [];
  $s = @file_get_contents($file);
  if ($s === false) return [];
  $s = preg_replace('/^\xEF\xBB\xBF/', '', $s);
  $s = preg_replace('/,\s*([\}\]])/', '$1', $s);
  $j = json_decode($s, true);
  return is_array($j) ? $j : [];
}
function load_distaccamenti_for_select(): array {
  $out = [];
  // 1) da caserme.json in root (se presente)
  $cfg = read_json_relaxed(__DIR__.'/caserme.json');
  if ($cfg) {
    foreach ($cfg as $r) {
      $slug = preg_replace('/[^a-z0-9_-]/i','', (string)($r['slug'] ?? ''));
      $name = trim((string)($r['name'] ?? ''));
      if ($slug && is_dir(__DIR__.'/data/'.$slug)) {
        $out[$slug] = ['slug'=>$slug,'name'=> ($name !== '' ? $name : $slug)];
      }
    }
  }
  // 2) qualsiasi cartella data/<slug> con users.json
  foreach (glob(__DIR__.'/data/*', GLOB_ONLYDIR) ?: [] as $dir) {
    $slug = basename($dir);
    if (!preg_match('/^[a-z0-9_-]+$/i', $slug)) continue;
    if (is_file($dir.'/users.json') && !isset($out[$slug])) {
      $out[$slug] = ['slug'=>$slug,'name'=>$slug];
    }
  }
  uasort($out, fn($a,$b)=>strcasecmp($a['name'],$b['name']));
  return array_values($out);
}
$DIST = load_distaccamenti_for_select();

/* ====== SLUG DISTACCAMENTO (POST > GET > SESSION > default) ====== */
$slug = '';
if (isset($_POST['slug'])) {
  $slug = preg_replace('/[^a-z0-9_-]/i','', (string)$_POST['slug']);
} elseif (isset($_GET['slug'])) {
  $slug = preg_replace('/[^a-z0-9_-]/i','', (string)$_GET['slug']);
} elseif (isset($_SESSION['tenant_slug'])) {
  $slug = preg_replace('/[^a-z0-9_-]/i','', (string)$_SESSION['tenant_slug']);
}
if ($slug === '') $slug = 'default';

// Se c'è un solo distaccamento e lo slug è vuoto/default, usa quello
if (($slug === '' || $slug === 'default') && count($DIST) === 1) {
  $slug = $DIST[0]['slug'];
}

/* ====== ROUTING BACK ====== */
$next = $_GET['next'] ?? ($_POST['next'] ?? ('vol_login.php?slug='.$slug));

/* ====== PATHS DIPENDENTI DA SLUG ====== */
$dataDir       = __DIR__."/data/{$slug}";
$usersPath     = $dataDir.'/users.json';
$vigiliPath    = $dataDir.'/vigili.json';
$personalePath = $dataDir.'/personale.json';
$codesPath     = $dataDir.'/_pwd_codes.json';

/* ====== CSRF ====== */
if (empty($_SESSION['_csrf_pwd'])) $_SESSION['_csrf_pwd'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['_csrf_pwd'];

/* ====== JSON helpers ====== */
function json_load_relaxed($p){
  if (!is_file($p)) return [];
  $s = @file_get_contents($p); if ($s===false) return [];
  $s = preg_replace('/^\xEF\xBB\xBF/', '', $s);
  $s = preg_replace('/,\s*([\}\]])/m', '$1', $s);
  $j = json_decode($s, true);
  return is_array($j) ? $j : [];
}
function json_save_atomic($p,$data){
  @mkdir(dirname($p),0775,true);
  $j = json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
  if ($j === false) throw new RuntimeException('JSON encode error: '.json_last_error_msg());
  $tmp = $p.'.tmp_'.bin2hex(random_bytes(6));
  if (@file_put_contents($tmp,$j,LOCK_EX)===false){ @unlink($tmp); throw new RuntimeException('write failed'); }
  @chmod($tmp,0664); @rename($tmp,$p);
}

/* ====== USERS loader ====== */
function load_users_norm($p){
  $rows = json_load_relaxed($p);
  if (!is_array($rows)) return [];
  if (array_values($rows) !== $rows) {
    $tmp=[];
    foreach ($rows as $k=>$v) if (is_array($v)) {
      $v['username'] = strtolower(trim($v['username'] ?? (string)$k));
      $tmp[] = $v;
    }
    $rows=$tmp;
  }
  $out=[]; $seen=[];
  foreach ($rows as $u){
    $un=strtolower(trim((string)($u['username']??'')));
    if($un===''||isset($seen[$un])) continue; $seen[$un]=1;
    $out[]=[
      'username'=>$un,
      'password_hash'=>(string)($u['password_hash']??''),
      'is_capo'=>!empty($u['is_capo'])?1:0,
      'perms'=>is_array($u['perms']??[])?array_values(array_unique(array_map('strval',$u['perms']))):[],
    ];
  }
  usort($out,fn($a,$b)=>strcasecmp($a['username'],$b['username']));
  return $out;
}

/* ====== Email lookup: vigili.json -> personale.json ====== */
function find_email_for_username($vigiliPath, $personalePath, $username){
  $username = strtolower(trim((string)$username));
  if ($username==='') return null;

  $vig = json_load_relaxed($vigiliPath);
  if (!is_array($vig)) $vig = [];

  $match = null;

  // 1) match diretto sul campo username nel vigile (se presente)
  foreach ($vig as $v){
    $u = strtolower(trim((string)($v['username'] ?? '')));
    if ($u !== '' && $u === $username) { $match = $v; break; }
  }

  // 2) fallback: username calcolato da cognome.nome (come nel login)
  if (!$match) {
    foreach ($vig as $v){
      $cand = username_from_vigile($v);
      if ($cand !== '' && $cand === $username) { $match = $v; break; }
    }
  }

  $vid = null;
  if ($match) {
    $em  = trim((string)($match['email'] ?? ''));
    if (filter_var($em, FILTER_VALIDATE_EMAIL)) return $em;
    $vid = (int)($match['id'] ?? 0);
  }

  if ($vid) {
    $pers = json_load_relaxed($personalePath);
    if (is_array($pers)) {
      $cand = $pers[$vid] ?? ($pers[(string)$vid] ?? null);
      if (is_array($cand)) {
        $em2 = trim((string)($cand['contatti']['email'] ?? ''));
        if (filter_var($em2, FILTER_VALIDATE_EMAIL)) return $em2;
      }
    }
  }
  return null;
}

/* ====== OTP store/load ====== */
function load_codes($path){
  $a=json_load_relaxed($path);
  $now=time();
  return is_array($a)?array_values(array_filter($a,fn($r)=> (int)($r['exp']??0) > $now)):[];
}
function save_codes($path,$a){ json_save_atomic($path,$a); }

/* ====== INVIO OTP ====== */
function send_otp_email_best($to,$code,$slug){
  if (defined('MAIL_TO_OVERRIDE') && MAIL_TO_OVERRIDE) $to = MAIL_TO_OVERRIDE;

  $fromEmail = hval(MAIL_FROM);
  $fromName  = mimehdr(tenant_display_name());
  $subject   = mimehdr("Codice per reimpostare la password");
  $bodyText  = "Ciao,\n\nil tuo codice per reimpostare la password per il distaccamento \"{$slug}\" è: {$code}\n\nIl codice scade in 10 minuti.\n";

  $headers = [];
  $headers[] = 'From: '.$fromName.' <'.$fromEmail.'>';
  $headers[] = 'Reply-To: '.$fromEmail;
  $headers[] = 'MIME-Version: 1.0';
  $headers[] = 'Content-Type: text/plain; charset=UTF-8';
  $params = '-f '.escapeshellarg($fromEmail);
  @ini_set('sendmail_from', $fromEmail);

  $logDir = __DIR__."/data/{$slug}";
  @mkdir($logDir, 0775, true);
  @file_put_contents($logDir.'/_last_pwd_code.txt', "To: {$to}\nCode: {$code}\nWhen: ".date('c')."\n", LOCK_EX);
  $logLine = '['.date('Y-m-d H:i:s')."] to={$to} env={$fromEmail} subj=\"Codice reset\" ";

  $ok = @mail($to, $subject, $bodyText, implode("\r\n", $headers), $params);
  @file_put_contents($logDir.'/_mail.log', $logLine.'php_mail='.($ok?'OK':'FAIL')."\n", FILE_APPEND);

  return $ok;
}

/* ====== Stato UI ====== */
$step = isset($_SESSION['_pwd_reset_ctx']) && is_array($_SESSION['_pwd_reset_ctx']) ? 2 : 1;
$err=''; $ok='';

/* ====== POST ====== */
try {
  if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!hash_equals($CSRF, $_POST['csrf'] ?? '')) throw new Exception('CSRF non valido.');

    // STEP 1: richiedi codice
    if (($_POST['do'] ?? '') === 'request') {
      // throttle semplice
      $lastReq = (int)($_SESSION['_pwd_last_req_ts'] ?? 0);
      if (time() - $lastReq < 60) throw new Exception('Troppi tentativi ravvicinati. Riprova tra qualche secondo.');

      $username = strtolower(trim((string)($_POST['username'] ?? '')));
      if ($username==='') throw new Exception('Inserisci lo username.');

      if (!is_file($usersPath)) throw new Exception('Archivio utenti non trovato per questo Distaccamento.');
      $users = load_users_norm($usersPath);

      $exists=false;
      foreach($users as $u){ if($u['username']===$username){ $exists=true; break; } }
      if(!$exists) throw new Exception('Utente non trovato in questo Distaccamento. Verifica di aver scelto quello corretto.');

      $email = find_email_for_username($vigiliPath, $personalePath, $username);
      if(!$email) throw new Exception('Email assente per questo utente. Inseriscila in “Gestione personale” o nel profilo vigile.');

      $code = str_pad((string)random_int(0,999999),6,'0',STR_PAD_LEFT);
      $ttl  = 10*60;
      $_SESSION['_pwd_reset_ctx'] = [
        'slug'=>$slug,'username'=>$username,'email'=>$email,
        'code'=>$code,'gen'=>time(),'exp'=>time()+$ttl
      ];
      $_SESSION['_pwd_last_req_ts'] = time();

      $codes = load_codes($codesPath);
      $codes = array_values(array_filter($codes, fn($r)=> !($r['slug']===$slug && strtolower($r['username']??'')===$username)));
      $codes[] = ['slug'=>$slug,'username'=>$username,'email'=>$email,'code'=>$code,'exp'=>time()+$ttl];
      save_codes($codesPath,$codes);

      @send_otp_email_best($email,$code,$slug);

      $ok='Ti abbiamo inviato un codice a 6 cifre via email. Controlla anche la cartella spam.';
      $step=2;
    }

    // STEP 2: verifica codice + reset
    if (($_POST['do'] ?? '') === 'reset') {
      $ctx = $_SESSION['_pwd_reset_ctx'] ?? null;
      if (!$ctx || ($ctx['slug']??'') !== $slug) throw new Exception('Sessione reset non valida. Seleziona il distaccamento corretto e richiedi un nuovo codice.');

      $code = trim((string)($_POST['code'] ?? ''));
      $pwd1 = (string)($_POST['pwd1'] ?? '');
      $pwd2 = (string)($_POST['pwd2'] ?? '');

      if (!preg_match('/^\d{6}$/',$code)) throw new Exception('Inserisci il codice a 6 cifre.');
      if ($pwd1==='' || $pwd2==='') throw new Exception('Inserisci e ripeti la nuova password.');
      if ($pwd1 !== $pwd2) throw new Exception('Le password non coincidono.');
      if (strlen($pwd1) < 8) throw new Exception('La password deve avere almeno 8 caratteri.');

      $okCode=false;
      foreach(load_codes($codesPath) as $r){
        if ($r['slug']===$slug && strtolower($r['username']??'')===strtolower($ctx['username']??'') && (string)$r['code']===$code) { $okCode=true; break; }
      }
      if(!$okCode){
        if (time() <= (int)($ctx['exp']??0) && hash_equals((string)$ctx['code'],$code)) $okCode=true;
      }
      if(!$okCode) throw new Exception('Codice non valido o scaduto.');

      $users = load_users_norm($usersPath);
      $found=false;
      foreach ($users as &$u){
        if ($u['username']===$ctx['username']) { $u['password_hash']=password_hash($pwd1,PASSWORD_DEFAULT); $found=true; break; }
      }
      unset($u);
      if(!$found) throw new Exception('Utente non trovato durante l’aggiornamento.');
      json_save_atomic($usersPath,$users);

      $codes = array_values(array_filter(load_codes($codesPath), fn($r)=> !($r['slug']===$slug && strtolower($r['username']??'')===strtolower($ctx['username']))));
      save_codes($codesPath,$codes);
      unset($_SESSION['_pwd_reset_ctx']);

      if (ob_get_length()) { @ob_end_clean(); }
      header('Location: '.('vol_login.php?slug='.rawurlencode($slug).'&next='.rawurlencode($next).'&msg=pwd_ok'), true, 303);
      exit;
    }
  }
} catch(Throwable $ex){
  $err = $ex->getMessage();
  $step = isset($_SESSION['_pwd_reset_ctx']) ? 2 : 1;
}
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Password dimenticata / Primo accesso</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>body{background:#f7f9fc}</style>
</head>
<body class="d-flex align-items-center justify-content-center" style="min-height:100vh;">
<div class="container" style="max-width:520px">
  <div class="card shadow-sm">
    <div class="card-body">
      <h1 class="h5 mb-3">Password dimenticata / Primo accesso</h1>

      <?php if ($err): ?><div class="alert alert-danger py-2"><?= e($err) ?></div><?php endif; ?>
      <?php if ($ok):  ?><div class="alert alert-success py-2"><?= e($ok) ?></div><?php endif; ?>

      <?php
        // helper: render select distaccamento (allo step 2 è solo disabilitata)
        $soloUno = count($DIST) === 1 ? ($DIST[0]['slug'] ?? '') : '';
        function render_slug_select($DIST,$slug,$disabled=false,$soloUno=''){
          if (empty($DIST)) {
            echo '<div class="alert alert-warning">Nessun Distaccamento trovato. Contatta l\'amministratore.</div>';
            echo '<input type="hidden" name="slug" value="default">';
            return;
          }
          $style = $soloUno ? 'style="display:none;"' : '';
          echo '<div class="mb-2" '.$style.'>';
          echo '  <label class="form-label">Distaccamento</label>';
          echo '  <select class="form-select" name="slug" '.($disabled?'disabled':'required').'>';
          if (!$soloUno) {
            $selNone = ($slug==='' || $slug==='default') ? ' selected' : '';
            echo '    <option value="" disabled'.$selNone.'>— scegli —</option>';
          }
          foreach ($DIST as $d) {
            $sel = ($slug === $d['slug'] || ($soloUno && $soloUno===$d['slug'])) ? ' selected' : '';
            echo '    <option value="'.e($d['slug']).'"'.$sel.'>'.e($d['name']).'</option>';
          }
          echo '  </select>';
          echo '  <div class="form-text">Seleziona il Distaccamento corretto per trovare lo username.</div>';
          echo '</div>';
          // SOLO se la select è disabilitata o nascosta (un solo distaccamento), metto l'hidden
          if ($disabled || $soloUno) {
            echo '<input type="hidden" name="slug" value="'.e($slug).'">';
          }
        }
      ?>

      <?php if ($step===1): ?>
        <form method="post" class="vstack gap-2">
          <input type="hidden" name="csrf" value="<?= e($CSRF) ?>">
          <input type="hidden" name="do" value="request">
          <input type="hidden" name="next" value="<?= e($next) ?>">

          <?php render_slug_select($DIST, $slug, false, $soloUno); ?>

          <div>
            <label class="form-label">Username</label>
            <input name="username" class="form-control" autocomplete="username" required>
            <div class="form-text">
              Riceverai un codice a 6 cifre via email (se presente nell’anagrafica del vigile).
            </div>
          </div>
          <button class="btn btn-primary w-100 mt-2">Invia codice</button>
        </form>
      <?php else: ?>
        <form method="post" class="vstack gap-2">
          <input type="hidden" name="csrf" value="<?= e($CSRF) ?>">
          <input type="hidden" name="do" value="reset">
          <input type="hidden" name="next" value="<?= e($next) ?>">

          <?php render_slug_select($DIST, $slug, true, $soloUno); ?>

          <div>
            <label class="form-label">Codice a 6 cifre</label>
            <input name="code" class="form-control" inputmode="numeric" pattern="\d{6}" maxlength="6" required placeholder="••••••">
          </div>
          <div>
            <label class="form-label">Nuova password</label>
            <input name="pwd1" type="password" class="form-control" autocomplete="new-password" required>
          </div>
          <div>
            <label class="form-label">Ripeti nuova password</label>
            <input name="pwd2" type="password" class="form-control" autocomplete="new-password" required>
          </div>
          <button class="btn btn-success w-100 mt-2">Imposta password</button>
        </form>

        <form method="post" class="text-center mt-2">
          <input type="hidden" name="csrf" value="<?= e($CSRF) ?>">
          <input type="hidden" name="do" value="request">
          <input type="hidden" name="slug" value="<?= e($slug) ?>">
          <input type="hidden" name="next" value="<?= e($next) ?>">
          <button class="btn btn-link btn-sm">Non hai ricevuto il codice? Reinvia</button>
        </form>
      <?php endif; ?>

      <div class="mt-3 small">
        <a href="vol_login.php?slug=<?= urlencode($slug) ?>&next=<?= urlencode($next) ?>">Torna al login</a>
      </div>
    </div>
  </div>
</div>
</body>
</html>
