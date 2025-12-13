<?php
// dashboard_vigile.php — Home personale del vigile loggato (self-service) ROBUSTA
// Adattata a UI/visual stile personale.php (sidebar riepilogo, badge SIPEC, pannello scadenze, tab)

ini_set('display_errors', 1);
error_reporting(E_ALL);
ob_start();

if (session_status() === PHP_SESSION_NONE) @session_start();

/* ===== Include "soft" ===== */
@include __DIR__.'/auth.php';
@include __DIR__.'/tenant_bootstrap.php';
@include __DIR__.'/storage.php';
@include __DIR__.'/utils.php';

/* ===== Fallback helpers ===== */
if (!function_exists('auth_is_logged_in')) {
  function auth_is_logged_in(){ return !empty($_SESSION['auth_user']); }
}
if (!function_exists('auth_current_user')) {
  function auth_current_user(){ return $_SESSION['auth_user'] ?? null; }
}
if (!function_exists('tenant_active_slug')) {
  function tenant_active_slug(){ return $_SESSION['tenant_slug'] ?? ''; }
}
if (!function_exists('minuti_da_intervallo_datetime')) {
  // differenza minuti tra due ISO "YYYY-MM-DDTHH:MM:SS"
  function minuti_da_intervallo_datetime($inizio_iso, $fine_iso){
    try{
      $a = @new DateTime((string)$inizio_iso);
      $b = @new DateTime((string)$fine_iso);
      if(!$a || !$b) return 0;
      $diff = $a->diff($b);
      return (int)($diff->days*24*60 + $diff->h*60 + $diff->i);
    }catch(Throwable $e){ return 0; }
  }
}

/* ===== GATE (vigile o admin) ===== */
$vol = $_SESSION['vol_user'] ?? null;
if (!$vol) {
  if (auth_is_logged_in()) {
    // ok admin, continua
  } else {
    $slug = isset($_GET['slug']) ? preg_replace('/[^a-z0-9_-]/i','', (string)$_GET['slug']) : '';
    if ($slug === '' && function_exists('tenant_active_slug')) $slug = preg_replace('/[^a-z0-9_-]/i','', (string)tenant_active_slug());
    if ($slug === '') $slug = (string)($_SESSION['tenant_slug'] ?? 'default');
    header('Location: vol_login.php?slug='.urlencode($slug).'&next='.urlencode('dashboard_vigile.php'));
    exit;
  }
}

/* ===== SLUG ===== */
$slug = $vol['slug'] ?? ($_SESSION['tenant_slug'] ?? (function_exists('tenant_active_slug') ? tenant_active_slug() : ''));
$slug = preg_replace('/[^a-z0-9_-]/i','', (string)$slug);

/* ===== Individua la CARTELLA DATI (compat con personale.php) ===== */
$candidates = [];
if (!empty($slug)) $candidates[] = __DIR__.'/data/'.$slug;
$candidates[] = __DIR__.'/data';
$candidates[] = __DIR__;

$DATA_DIR_CANDIDATE = null;
// 1) preferisci dove esistono entrambi i file
foreach ($candidates as $c) {
  if (is_file($c.'/vigili.json') && is_file($c.'/personale.json')) { $DATA_DIR_CANDIDATE = $c; break; }
}
// 2) altrimenti dove ne esiste almeno uno
if ($DATA_DIR_CANDIDATE === null) {
  foreach ($candidates as $c) {
    if (is_file($c.'/vigili.json') || is_file($c.'/personale.json')) { $DATA_DIR_CANDIDATE = $c; break; }
  }
}
// 3) ultima spiaggia: data/ (non creare nulla ora)
if ($DATA_DIR_CANDIDATE === null) {
  $DATA_DIR_CANDIDATE = __DIR__.'/data';
}

/* Usa quella già definita se esiste, altrimenti definiscila ora */
if (!defined('DATA_DIR')) {
  define('DATA_DIR', $DATA_DIR_CANDIDATE);
}

/* Util per path */
function _path($rel){ return rtrim(DATA_DIR,'/').'/'.ltrim($rel,'/'); }
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function _json_load_relaxed($abs){
  if (!is_file($abs)) return [];
  $s=@file_get_contents($abs); if($s===false) return [];
  $s=preg_replace('/^\xEF\xBB\xBF/','',$s);            // BOM
  $s=preg_replace('/,\s*([\}\]])/m','$1',$s);          // trailing comma
  $j=json_decode($s,true);
  return is_array($j)?$j:[]; 
}
function _json_save_atomic($abs,$data){
  @mkdir(dirname($abs),0775,true);
  $tmp=$abs.'.tmp_'.bin2hex(random_bytes(4));
  $ok=@file_put_contents($tmp,json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT),LOCK_EX);
  if($ok===false){ @unlink($tmp); throw new RuntimeException('write fail'); }
  @chmod($tmp,0664);
  if(!@rename($tmp,$abs)){ @unlink($tmp); throw new RuntimeException('rename fail'); }
}
function get_first_nonempty($arr, array $paths, string $default=''){
  foreach ($paths as $p){
    $cur = $arr;
    foreach ((array)$p as $seg){
      if (!is_array($cur) || !array_key_exists($seg, $cur)) { $cur=null; break; }
      $cur = $cur[$seg];
    }
    if (is_scalar($cur) && trim((string)$cur) !== '') return trim((string)$cur);
  }
  return $default;
}
function _is_image_url($url){
  return (bool)preg_match('~\.(jpe?g|png|gif|webp|bmp|svg)$~i', (string)$url);
}

/* ===== Normalizza per matching cognome.nome ~ username ===== */
function _norm_userkey($s){
  $s = (string)$s;
  $s = strtolower($s);
  if (strpos($s,'@')!==false) $s = substr($s,0,strpos($s,'@')); // parte prima di @
  if (function_exists('iconv')) {
    $t = @iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$s);
    if ($t!==false) $s = $t;
  }
  $s = preg_replace('/[^a-z0-9]/','', $s);
  return $s;
}

/* ===== Username corrente ===== */
function current_username(){
  if (!empty($_SESSION['vol_user']['username'])) return strtolower(trim((string)$_SESSION['vol_user']['username']));
  $u = auth_current_user();
  if (is_array($u) && isset($u['username'])) return strtolower(trim((string)$u['username']));
  if (is_string($u) && $u!=='') return strtolower(trim($u));
  foreach (['auth_user','user','tenant_user'] as $k) {
    if (!empty($_SESSION[$k])) {
      if (is_array($_SESSION[$k]) && isset($_SESSION[$k]['username'])) return strtolower(trim((string)$_SESSION[$k]['username']));
      if (is_string($_SESSION[$k])) return strtolower(trim($_SESSION[$k]));
    }
  }
  return strtolower(trim((string)($_SESSION['username'] ?? '')));
}
$me_username = current_username();
$me_userkey  = _norm_userkey($me_username);

/* ===== Carica dati ===== */
$vigili       = sanitize_vigili_list(_json_load_relaxed(_path('vigili.json')));
$personaleMap = _json_load_relaxed(_path('personale.json'));
$allAdd       = _json_load_relaxed(_path('addestramenti.json'));

if (!is_array($vigili)) $vigili=[];
if (!is_array($personaleMap)) $personaleMap=[];
if (!is_array($allAdd)) $allAdd=[];

/* Normalizza PERSONALE: lista -> mappa, chiavi stringa -> id int, forza 'id' dentro al record */
$normalized = [];
// se è lista pura numerica
$isList = array_keys($personaleMap) === range(0, max(0,count($personaleMap)-1));
if ($isList) {
  foreach($personaleMap as $row){
    if (!is_array($row)) continue;
    $id = (int)($row['id'] ?? 0);
    if ($id>0) { $row['id']=$id; $normalized[$id]=$row; }
  }
} else {
  foreach ($personaleMap as $k=>$row){
    if (!is_array($row)) continue;
    $id = (int)($row['id'] ?? (is_numeric($k)? (int)$k : 0));
    if ($id>0) { $row['id']=$id; $normalized[$id]=$row; }
  }
}
$personaleMap = $normalized;

/* ===== Trova il mio profilo ===== */
$ME=null;

/* 1) Se login vigile classico con id in sessione */
$myId = (int)($_SESSION['vol_user']['id'] ?? 0);
if ($myId>0) {
  foreach($vigili as $v){ if ((int)($v['id']??0)===$myId){ $ME=$v; break; } }
}

/* 2) Se admin o sessione senza id, prova matching cognome+nome ~ username */
if(!$ME && $me_userkey!==''){
  foreach($vigili as $v){
    $cand = _norm_userkey((string)($v['cognome'] ?? '').(string)($v['nome'] ?? ''));
    if ($cand!=='' && $cand===$me_userkey){ $ME=$v; break; }
  }
}

/* 3) Se admin, consenti override da GET: ?id=NN */
if(!$ME && auth_is_logged_in()){
  $getId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
  if ($getId>0){
    foreach($vigili as $v){ if ((int)($v['id']??0)===$getId){ $ME=$v; break; } }
  }
}

/* 4) Se ancora nulla -> messaggio chiaro */
if(!$ME){
  http_response_code(404);
  echo 'Utente non collegato ad alcun profilo vigile.
        Se sei admin, aggiungi ?id=ID alla URL (es. dashboard_vigile.php?id=1)
        oppure assicurati che il tuo username corrisponda a cognome.nome del vigile.';
  exit;
}

$me_id = (int)($ME['id'] ?? 0);
if ($me_id<=0){ http_response_code(400); echo 'Profilo vigile corrotto (ID mancante).'; exit; }

/* ===== Prefill record personale se mancante ===== */
if (!isset($personaleMap[$me_id]) || !is_array($personaleMap[$me_id])) {
  $nome   = trim((string)($ME['nome'] ?? ''));
  $cogn   = trim((string)($ME['cognome'] ?? ''));
  $tel    = (string)($ME['telefono'] ?? '');
  $email  = (string)($ME['email'] ?? '');
  // indirizzo compat: supporta sia ME.indirizzo.via che campi piatti
  $via    = (string)(($ME['indirizzo']['via'] ?? ($ME['via'] ?? '')));
  $cap    = (string)(($ME['indirizzo']['cap'] ?? ($ME['cap'] ?? '')));
  $comune = (string)(($ME['indirizzo']['comune'] ?? ($ME['comune'] ?? '')));

  $personaleMap[$me_id] = [
    'id'        => $me_id,
    'contatti'  => ['telefono'=>$tel, 'email'=>$email],
    'indirizzo' => ['via'=>$via, 'cap'=>$cap, 'comune'=>$comune],
    'scadenze'  => [],
    'grado_numero' => '',
    'documento_identita' => [],
    'patente_civile' => ['categorie'=>[]],
    'patente_ministeriale' => [],
    'patenti' => [],
    'abilitazioni' => [],
    'abilitazioni_dettaglio' => [],
    'dpi' => [],
    'note' => '',
  ];
}

/* ===== Record personale per il mio id ===== */
$P = $personaleMap[$me_id] ?? [];
if (!is_array($P)) $P=[];

/* ===== Richieste TRACK: elenco standard ===== */
$TRACK_ITEMS = [
  'elmo_intervento'      => 'Elmo da intervento',
  'sottocasco_ignifugo'  => 'Sotto casco ignifugo',
  'elmo_multifunzione'   => 'Elmo multifunzione',
  'completo_antifiamma'  => 'Completo antifiamma (Giacca e pantaloni Nomex)',
  'guanti_intervento'    => 'Guanti da intervento',
  'calzature_intervento' => 'Calzature da intervento (Stivali)',
  'completo_antipioggia' => 'Completo anti pioggia (Giacca e pantaloni)',
];

/* ===== Upload base ===== */
@mkdir(_path('uploads'),0775,true);
foreach (['doc_identita','patente_civile','patente_ministeriale','abilitazioni','dpi'] as $d) @mkdir(_path('uploads/'.$d),0775,true);
function _safe_name($s){ $s=preg_replace('/[^\p{L}\p{N}\._-]+/u','_',(string)$s); return trim($s,'_'); }
function _rel_from_abs($abs){ return str_replace(rtrim(__DIR__,'/').'/', '', $abs); }
function _save_upload($field,$subdir,$prevRel=''){
  if (!isset($_FILES[$field]) || !is_array($_FILES[$field])) return $prevRel;
  if (($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return $prevRel;
  $tmp=$_FILES[$field]['tmp_name']; $name=_safe_name($_FILES[$field]['name'] ?? 'file');
  $ext=strtolower(pathinfo($name,PATHINFO_EXTENSION)); $base=pathinfo($name,PATHINFO_FILENAME);
  $destAbs = _path('uploads/'.$subdir.'/'.$base.'_'.date('Ymd_His').($ext?'.'.$ext:'')); // uploads sotto DATA_DIR
  if (!@move_uploaded_file($tmp,$destAbs)) return $prevRel;
  @chmod($destAbs,0664);
  return _rel_from_abs($destAbs);
}

/* ===== CSRF ===== */
if (empty($_SESSION['_csrf_dashv'])) $_SESSION['_csrf_dashv']=bin2hex(random_bytes(16));
$CSRF = $_SESSION['_csrf_dashv'];

$ERR=''; $MSG='';

/* ===== POST: Salvataggio ===== */
if ($_SERVER['REQUEST_METHOD']==='POST') {
  try{
    if (!hash_equals($CSRF, $_POST['_csrf'] ?? '')) throw new RuntimeException('CSRF non valido.');
    $post_id = (int)($_POST['vigile_id'] ?? 0);
    if ($post_id !== $me_id) throw new RuntimeException('Richiesta non valida.');

    // ricarica da disco e rinormalizza
    $personaleMap = _json_load_relaxed(_path('personale.json'));
    if (!is_array($personaleMap)) $personaleMap=[];
    $normalized=[];
    $isList = array_keys($personaleMap) === range(0, max(0,count($personaleMap)-1));
    if ($isList) { foreach($personaleMap as $row){ $id=(int)($row['id']??0); if($id>0){ $row['id']=$id; $normalized[$id]=$row; } } }
    else { foreach($personaleMap as $k=>$row){ if(!is_array($row)) continue; $id=(int)($row['id']??(is_numeric($k)?(int)$k:0)); if($id>0){ $row['id']=$id; $normalized[$id]=$row; } } }
    $personaleMap=$normalized;

    if (!isset($personaleMap[$me_id]) || !is_array($personaleMap[$me_id])) $personaleMap[$me_id]=[];
    $rec = $personaleMap[$me_id];

    // ----- Campi base -----
    $rec['contatti']  = $rec['contatti']  ?? [];
    $rec['indirizzo'] = $rec['indirizzo'] ?? [];
    $rec['scadenze']  = $rec['scadenze']  ?? [];

    $rec['contatti']['telefono'] = trim((string)($_POST['telefono'] ?? ''));
    $rec['contatti']['email']    = trim((string)($_POST['email'] ?? ''));
    $rec['indirizzo']['via']     = trim((string)($_POST['via'] ?? ''));
    $rec['indirizzo']['cap']     = trim((string)($_POST['cap'] ?? ''));
    $rec['indirizzo']['comune']  = trim((string)($_POST['comune'] ?? ''));

    $rec['data_nascita'] = trim((string)($_POST['data_nascita'] ?? ''));
    $rec['grado_numero'] = trim((string)($_POST['grado_numero'] ?? ''));

    // Scadenze
    $ultima_visita = trim((string)($_POST['ultima_visita_medica'] ?? ''));
    if ($ultima_visita !== '') {
      $rec['scadenze']['visita_medica_ultima'] = $ultima_visita;
      $dt = DateTime::createFromFormat('Y-m-d', $ultima_visita);
      if ($dt !== false) { $dt->add(new DateInterval('P30M')); $rec['scadenze']['visita_medica'] = $dt->format('Y-m-d'); }
    }
    $scad_pat_b = trim((string)($_POST['scadenza_patente_b'] ?? ''));
    if ($scad_pat_b !== '') $rec['scadenze']['patente_b'] = $scad_pat_b;

    // Documento identità + upload
    $rec['documento_identita'] = $rec['documento_identita'] ?? [];
    $rec['documento_identita']['tipo']     = trim((string)($_POST['doc_tipo'] ?? ''));
    $rec['documento_identita']['numero']   = trim((string)($_POST['doc_num'] ?? ''));
    $rec['documento_identita']['scadenza'] = trim((string)($_POST['doc_scad'] ?? ''));
    $prev_doc_front = (string)($rec['documento_identita']['file_fronte'] ?? ($rec['documento_identita']['file'] ?? ''));
    $prev_doc_back  = (string)($rec['documento_identita']['file_retro'] ?? '');
    $rec['documento_identita']['file_fronte'] = _save_upload('doc_file_fronte', 'doc_identita', $prev_doc_front);
    $rec['documento_identita']['file_retro']  = _save_upload('doc_file_retro',  'doc_identita', $prev_doc_back);

    // Patente civile + upload
    $pc_cat  = [];
    if (!empty($_POST['pat_civ_cat']) && is_array($_POST['pat_civ_cat'])) {
      $pc_cat = array_values(array_filter(array_map('trim', $_POST['pat_civ_cat']), fn($x)=>$x!==''));
    }
    $rec['patente_civile'] = $rec['patente_civile'] ?? [];
    $rec['patente_civile']['numero']    = trim((string)($_POST['pat_civ_num'] ?? ''));
    $rec['patente_civile']['scadenza']  = trim((string)($_POST['pat_civ_scad'] ?? ''));
    $rec['patente_civile']['categorie'] = $pc_cat;
    $prev_pc_front = (string)($rec['patente_civile']['file_fronte'] ?? ($rec['patente_civile']['file'] ?? ''));
    $prev_pc_back  = (string)($rec['patente_civile']['file_retro'] ?? '');
    $rec['patente_civile']['file_fronte'] = _save_upload('pat_civ_file_fronte', 'patente_civile', $prev_pc_front);
    $rec['patente_civile']['file_retro']  = _save_upload('pat_civ_file_retro',  'patente_civile', $prev_pc_back);

    // Patente ministeriale (gradi + file)
    $patenti = [];
    if (!empty($_POST['patenti_sel']) && is_array($_POST['patenti_sel'])) {
      $sel = array_values(array_filter(array_map('trim', $_POST['patenti_sel']), fn($x)=>$x!==''));
      $hasOthers = count(array_diff($sel, ['Nessuna'])) > 0;
      $patenti = $hasOthers ? array_values(array_diff($sel, ['Nessuna'])) : (in_array('Nessuna', $sel, true) ? ['Nessuna'] : []);
    }
    $rec['patenti'] = $patenti;
    $rec['patente_ministeriale'] = $rec['patente_ministeriale'] ?? [];
    $prev_pm_front = (string)($rec['patente_ministeriale']['file_fronte'] ?? '');
    $prev_pm_back  = (string)($rec['patente_ministeriale']['file_retro']  ?? '');
    $rec['patente_ministeriale']['file_fronte'] = _save_upload('pat_min_file_fronte', 'patente_ministeriale', $prev_pm_front);
    $rec['patente_ministeriale']['file_retro']  = _save_upload('pat_min_file_retro',  'patente_ministeriale', $prev_pm_back);

    // Abilitazioni dettaglio + upload multipli
    $abil_simple = array_values(array_filter(array_map('trim', explode(',', (string)($_POST['abilitazioni'] ?? ''))), fn($x)=>$x!==''));    
    $abil_rows = [];
    if (!empty($_POST['abil_rows']) && is_array($_POST['abil_rows'])) {
      foreach ($_POST['abil_rows'] as $k => $row) {
        $nome = trim((string)($row['nome'] ?? '')); if ($nome==='') continue;
        $abil_rows[] = ['nome'=>$nome,'numero'=>trim((string)($row['numero'] ?? '')),'scadenza'=>trim((string)($row['scadenza'] ?? '')),'file'=>''];
      }
    }
    $prev_abil_rows = (array)($rec['abilitazioni_dettaglio'] ?? []);
    foreach ($abil_rows as $k=>$row) {
      $prevFile = isset($prev_abil_rows[$k]['file']) ? (string)$prev_abil_rows[$k]['file'] : '';
      $field = 'abil_file_'.$k;
      if (isset($_FILES[$field])) {
        $_FILES['abil_file'] = $_FILES[$field];
        $abil_rows[$k]['file'] = _save_upload('abil_file', 'abilitazioni', $prevFile);
        unset($_FILES['abil_file']);
      } else {
        $abil_rows[$k]['file'] = $prevFile;
      }
    }
    $rec['abilitazioni'] = $abil_simple;
    $rec['abilitazioni_dettaglio'] = $abil_rows;

    // DPI + upload multipli
    $dpi_rows = [];
    if (!empty($_POST['dpi_rows']) && is_array($_POST['dpi_rows'])) {
      foreach ($_POST['dpi_rows'] as $k=>$row) {
        $item = trim((string)($row['item'] ?? '')); if ($item==='') continue;
        $dpi_rows[] = [
          'item'=>$item,'taglia'=>trim((string)($row['taglia'] ?? '')),
          'assegnato'=>trim((string)($row['assegnato'] ?? '')),
          'scadenza'=>trim((string)($row['scadenza'] ?? '')),
          'quantita'=>(int)($row['quantita'] ?? 1),
          'note'=>trim((string)($row['note'] ?? '')),
          'file'=>'',
        ];
      }
    }
    $prev_dpi_rows = (array)($rec['dpi'] ?? []);
    foreach ($dpi_rows as $k=>$row) {
      $prevFile = isset($prev_dpi_rows[$k]['file']) ? (string)$prev_dpi_rows[$k]['file'] : '';
      $field = 'dpi_file_'.$k;
      if (isset($_FILES[$field])) {
        $_FILES['dpi_file'] = $_FILES[$field];
        $dpi_rows[$k]['file'] = _save_upload('dpi_file', 'dpi', $prevFile);
        unset($_FILES['dpi_file']);
      } else {
        $dpi_rows[$k]['file'] = $prevFile;
      }
    }
    $rec['dpi']  = $dpi_rows;

    // Richieste a TRACK (richiesto/non richiesto)
    $track_req = [];
    foreach ($TRACK_ITEMS as $k=>$lbl) {
      $v = strtolower((string)($_POST['track_req'][$k] ?? ''));
      $track_req[$k] = ($v === 'si');
    }
    $rec['track_richieste'] = $track_req;

    $rec['note'] = trim((string)($_POST['note'] ?? ''));

    // Salva
    $personaleMap[$me_id] = $rec;
    _json_save_atomic(_path('personale.json'), $personaleMap);

    $MSG='Dati aggiornati correttamente.';
    $P = $rec;
  } catch(Throwable $ex){
    $ERR = $ex->getMessage();
  }
}

/* ===== Addestramenti: aggregazioni ===== */
function rec_minutes($r){
  if (isset($r['minuti']) && $r['minuti']!=='') return (int)$r['minuti'];
  $data = (string)($r['data'] ?? '');
  $i = $r['inizio_dt'] ?? ($data ? ($data.'T'.substr((string)($r['inizio'] ?? '00:00'),0,5).':00') : null);
  $f = $r['fine_dt']   ?? ($data ? ($data.'T'.substr((string)($r['fine']   ?? '00:00'),0,5).':00') : null);
  if (!$i || !$f) return 0;
  try{ return minuti_da_intervallo_datetime($i,$f); }catch(Throwable $e){ return 0; }
}
$miei = array_values(array_filter($allAdd, fn($r)=> (int)($r['vigile_id'] ?? 0) === $me_id));
usort($miei, function($a,$b){
  $ad=$a['inizio_dt'] ?? (($a['data']??'').'T'.substr((string)($a['inizio']??'00:00'),0,5).':00');
  $bd=$b['inizio_dt'] ?? (($b['data']??'').'T'.substr((string)($b['inizio']??'00:00'),0,5).':00');
  return strcmp($bd,$ad);
});
$byYM = []; $totByYM = []; $years=[];
foreach($miei as $r){
  $d = (string)($r['data'] ?? substr((string)($r['inizio_dt'] ?? ''),0,10));
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$d)) continue;
  $y = substr($d,0,4); $m=substr($d,5,2);
  $years[$y]=1; $byYM[$y][$m][] = $r; $totByYM[$y][$m] = ($totByYM[$y][$m] ?? 0) + rec_minutes($r);
}
$years = array_keys($years); rsort($years);
$selYear = $_GET['anno'] ?? ($years[0] ?? date('Y'));
$selMonth= $_GET['mese'] ?? ''; if ($selMonth!=='' && !preg_match('/^\d{2}$/',$selMonth)) $selMonth='';

$totalMin = 0; foreach($miei as $r){ $totalMin += rec_minutes($r); }
function fmt_hhmm($min){ $h=intdiv((int)$min,60); $m=((int)$min)%60; return sprintf('%d:%02d',$h,$m); }
function fmt_date_it($ymd){
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$ymd)) return $ymd;
  try{
    if (class_exists('IntlDateFormatter')) {
      $dt = DateTime::createFromFormat('Y-m-d',$ymd);
      if(!$dt) return $ymd;
      $fmt = new IntlDateFormatter('it_IT', IntlDateFormatter::MEDIUM, IntlDateFormatter::NONE, $dt->getTimezone()->getName(), IntlDateFormatter::GREGORIAN, 'd MMM y');
      $s = $fmt->format($dt);
      return $s !== false ? mb_strtolower($s) : $ymd;
    }
  }catch(Throwable $e){}
  $mesi = ['01'=>'gen','02'=>'feb','03'=>'mar','04'=>'apr','05'=>'mag','06'=>'giu','07'=>'lug','08'=>'ago','09'=>'set','10'=>'ott','11'=>'nov','12'=>'dic'];
  $y=substr($ymd,0,4); $m=substr($ymd,5,2); $d=ltrim(substr($ymd,8,2),'0');
  return $d.' '.($mesi[$m] ?? $m).' '.$y;
}

/* ===== Dati intestazione ===== */
$nomeCognome = trim(($ME['nome'] ?? '').' '.($ME['cognome'] ?? ''));
$grado       = trim((string)($ME['grado'] ?? ($P['grado'] ?? '')));
$emailV      = trim((string)($ME['email'] ?? ($P['contatti']['email'] ?? '')));
$attivo      = (int)($ME['attivo'] ?? 1) === 1;

// SIPEC robusto
$cf        = get_first_nonempty($P, [['cf'], ['codice_fiscale'], ['cod_fiscale'], ['CF']]);
$codSede   = get_first_nonempty($P, [['cod_sede'], ['codice_sede'], ['sede_cod']]);
$ingresso  = get_first_nonempty($P, [['ingresso']]);
$uscita    = get_first_nonempty($P, [['uscita']]);
$idoneita  = get_first_nonempty($P, [['idoneita']], 'operativo');

/* ===== Derivati per pannelli ===== */
$scad_vis  = trim((string)($P['scadenze']['visita_medica'] ?? ''));
$ul_visita = trim((string)($P['scadenze']['visita_medica_ultima'] ?? ($P['scadenze']['visita_medica_data'] ?? '')));
$scad_patB = trim((string)($P['scadenze']['patente_b'] ?? ''));

$pc_front  = (string)($P['patente_civile']['file_fronte'] ?? ($P['patente_civile']['file'] ?? ''));
$pc_back   = (string)($P['patente_civile']['file_retro'] ?? '');
$di_front  = (string)($P['documento_identita']['file_fronte'] ?? ($P['documento_identita']['file'] ?? ''));
$di_back   = (string)($P['documento_identita']['file_retro'] ?? '');
$pm_front  = (string)($P['patente_ministeriale']['file_fronte'] ?? '');
$pm_back   = (string)($P['patente_ministeriale']['file_retro'] ?? '');

/* Helper avatar iniziali */
function initials($name){
  $n = preg_split('/\s+/u', trim((string)$name), -1, PREG_SPLIT_NO_EMPTY);
  if (!$n) return 'VV';
  $f = mb_substr($n[0],0,1);
  $l = mb_substr(end($n),0,1);
  return mb_strtoupper($f.$l,'UTF-8');
}
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>La mia scheda | AllertoGest</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  body{background:#f7f9fc}
  .card{box-shadow:0 8px 24px rgba(0,0,0,.06)}
  .file-thumb{max-height:110px;max-width:100%;border:1px solid #e5e7eb;border-radius:.5rem;display:block}
  .subtle{color:#6b7280}
  .pill{display:inline-block;padding:.25rem .5rem;border:1px solid #e5e7eb;border-radius:999px;font-size:.75rem;color:#374151;background:#fff}
  .avatar{
    width:64px;height:64px;border-radius:50%;
    background:linear-gradient(135deg,#e5f0ff,#f0fdfa);
    display:flex;align-items:center;justify-content:center;font-weight:700;color:#1f2937;border:1px solid #e5e7eb
  }
  .stat-chip{display:inline-flex;align-items:center;gap:.35rem;padding:.25rem .5rem;border:1px solid #e5e7eb;border-radius:999px;background:#fff;font-size:.8rem;color:#334155}
  .chip-ok{background:#ecfdf5;border-color:#a7f3d0}
  .chip-warn{background:#fffbeb;border-color:#fde68a}
  .chip-bad{background:#fef2f2;border-color:#fecaca}
  .thumb-box img{object-fit:cover;border:1px solid #e5e7eb;border-radius:.5rem}
  .nav-tabs .nav-link{font-weight:600}
  .side-label{font-size:.75rem;color:#6b7280;margin:0}
  .side-value{font-weight:600;color:#111827}
</style>
</head>
<body>

<?php
// ===== MENU GLOBALE (se presente) =====
$_MENU_ACTIVE = 'dashboard_vigile';
$menuPath = __DIR__.'/_menu.php';
if (is_file($menuPath)) { include $menuPath; }
?>

<div class="container py-4">
  <!-- Header -->
  <div class="d-flex align-items-center justify-content-between mb-3">
    <div class="d-flex align-items-center gap-3">
      <div class="avatar"><?= h(initials($nomeCognome ?: $me_username)) ?></div>
      <div>
        <h1 class="h4 mb-0"><?= h($nomeCognome ?: $me_username) ?></h1>
        <div class="subtle small">
          <?= $grado ? ('<span class="me-2">Grado: <strong>'.h($grado).'</strong></span>') : '' ?>
          <?= $attivo? '<span class="badge text-bg-success-subtle border">ATTIVO</span>' : '<span class="badge text-bg-secondary">NON ATTIVO</span>' ?>
        </div>
      </div>
    </div>
    <div class="d-flex gap-2">
      <a class="btn btn-outline-secondary btn-sm" href="index.php<?= $slug?('?slug='.urlencode($slug)) : '' ?>">Inserimento addestramenti</a>
      <a class="btn btn-outline-secondary btn-sm" href="riepilogo.php<?= $slug?('?slug='.urlencode($slug)) : '' ?>">Riepiloghi</a>
      <a class="btn btn-outline-secondary btn-sm" href="vol_logout.php<?= $slug?('?slug='.urlencode($slug)) : '' ?>">Esci</a>
    </div>
  </div>

  <?php if (!empty($ERR)): ?><div class="alert alert-danger"><?= h($ERR) ?></div><?php endif; ?>
  <?php if (!empty($MSG)): ?><div class="alert alert-success"><?= h($MSG) ?></div><?php endif; ?>

  <div class="row g-4">
    <!-- Sidebar riepilogo (stile personale.php) -->
    <div class="col-lg-4">
      <div class="card mb-3">
        <div class="card-header"><strong>Riepilogo</strong></div>
        <div class="card-body">
          <div class="mb-2">
            <p class="side-label">Codice Fiscale (SIPEC)</p>
            <div class="side-value"><?= $cf? h($cf) : '<span class="text-muted">—</span>' ?></div>
          </div>
          <div class="mb-2">
            <p class="side-label">Cod. Sede (SIPEC)</p>
            <div class="side-value"><?= $codSede? h($codSede) : '<span class="text-muted">—</span>' ?></div>
          </div>
          <div class="mb-2">
            <p class="side-label">Idoneità (SIPEC)</p>
            <div class="side-value"><?= h(strtoupper(str_replace('_',' ',$idoneita))) ?></div>
          </div>
          <div class="mb-2">
            <p class="side-label">Ingresso / Uscita (SIPEC)</p>
            <div class="side-value">
              <?= $ingresso? h($ingresso) : '—' ?> / <?= $uscita? h($uscita) : '—' ?>
            </div>
          </div>
          <hr>
          <div class="mb-2">
            <p class="side-label">Contatti</p>
            <div class="side-value">
              <?php $tel_show = (string)($P['contatti']['telefono'] ?? '');
              $mail_show = $emailV; ?>
              <div><?= $tel_show? h($tel_show) : '<span class="text-muted">—</span>' ?></div>
              <div><?= $mail_show? h($mail_show) : '<span class="text-muted">—</span>' ?></div>
            </div>
          </div>
          <div class="mb-2">
            <p class="side-label">Indirizzo</p>
            <div class="side-value">
              <?php
                $via = (string)($P['indirizzo']['via'] ?? '');
                $cap = (string)($P['indirizzo']['cap'] ?? '');
                $com = (string)($P['indirizzo']['comune'] ?? '');
                $addr = trim(($via?($via.', '):'').($cap?($cap.' '):'').$com);
                echo $addr? h($addr) : '<span class="text-muted">—</span>';
              ?>
            </div>
          </div>
          <hr>
          <div class="d-flex flex-wrap gap-2">
            <?php
              $chips=[];
              if (!empty($P['grado_numero'])) $chips[] = ['label'=>'Tesserino','val'=>(string)$P['grado_numero']];
              $cats = (array)($P['patente_civile']['categorie'] ?? []);
              if ($cats) $chips[] = ['label'=>'Patente Civile','val'=>implode('·',$cats)];
              $pmin = (array)($P['patenti'] ?? []);
              if ($pmin) $chips[] = ['label'=>'Patente Min.','val'=>implode('·',$pmin)];
              foreach($chips as $c){
                echo '<span class="stat-chip"><span class="text-muted">'.$c['label'].':</span> '.h($c['val']).'</span>';
              }
              if (!$chips) echo '<span class="text-muted">Nessun dettaglio extra</span>';
            ?>
          </div>
        </div>
      </div>

      <!-- Pannello scadenze rapido -->
      <div class="card">
        <div class="card-header"><strong>Scadenze</strong></div>
        <div class="card-body">
          <?php
            // helper stato scadenza
            function chip_scad($label,$date){
              if (!$date) return '<span class="stat-chip">'.$label.': —</span>';
              $today = new DateTime('today');
              $d = DateTime::createFromFormat('Y-m-d',$date);
              if(!$d) return '<span class="stat-chip">'.$label.': '.h($date).'</span>';
              $diff = (int)$today->diff($d)->format('%r%a'); // giorni
              $cls = 'chip-ok';
              if ($diff <= 30 && $diff >= 0) $cls='chip-warn';
              if ($diff < 0) $cls='chip-bad';
              return '<span class="stat-chip '.$cls.'">'.$label.': '.h($date).'</span>';
            }
            echo '<div class="vstack gap-2">';
            echo chip_scad('Visita medica', $scad_vis);
            echo chip_scad('Patente B', $scad_patB);
            echo '</div>';
            if ($ul_visita) {
              echo '<div class="small subtle mt-2">Ultima visita: '.h($ul_visita).'</div>';
            }
          ?>
          <hr>
          <div class="small subtle">Ore addestramento totali</div>
          <div class="h5 mb-0"><?= h(fmt_hhmm($totalMin)) ?></div>
        </div>
      </div>
    </div>

    <!-- Main content -->
    <div class="col-lg-8">
      <ul class="nav nav-tabs mb-3" role="tablist">
        <li class="nav-item" role="presentation">
          <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-profilo" type="button" role="tab">Profilo</button>
        </li>
        <li class="nav-item" role="presentation">
          <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-documenti" type="button" role="tab">Documenti</button>
        </li>
        <li class="nav-item" role="presentation">
          <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-abil" type="button" role="tab">Abilitazioni & DPI</button>
        </li>
        <li class="nav-item" role="presentation">
          <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-add" type="button" role="tab">Addestramenti</button>
        </li>
      </ul>

      <div class="tab-content">
        <!-- ===================== TAB: PROFILO (FORM) ===================== -->
        <div class="tab-pane fade show active" id="tab-profilo" role="tabpanel">
          <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
              <strong>I miei dati</strong>
              <span class="subtle small">I campi marcati (SIPEC) sono in sola lettura</span>
            </div>
            <div class="card-body">
              <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="_csrf" value="<?= h($CSRF) ?>">
                <input type="hidden" name="vigile_id" value="<?= (int)$me_id ?>">

                <!-- Contatti / anagrafica -->
                <div class="row g-3">
                  <div class="col-lg-4">
                    <label class="form-label">Telefono</label>
                    <input type="text" class="form-control" name="telefono" value="<?= h($P['contatti']['telefono'] ?? '') ?>">
                  </div>
                  <div class="col-lg-4">
                    <label class="form-label">Email</label>
                    <input type="email" class="form-control" name="email" value="<?= h($P['contatti']['email'] ?? ($ME['email'] ?? '')) ?>">
                  </div>
                  <div class="col-lg-4">
                    <label class="form-label">Data di nascita</label>
                    <input type="date" class="form-control" name="data_nascita" value="<?= h($P['data_nascita'] ?? '') ?>">
                  </div>
                </div>

                <div class="row g-3 mt-1">
                  <div class="col-lg-6">
                    <label class="form-label">Indirizzo (via)</label>
                    <input type="text" class="form-control" name="via" value="<?= h($P['indirizzo']['via'] ?? '') ?>">
                  </div>
                  <div class="col-lg-2">
                    <label class="form-label">CAP</label>
                    <input type="text" class="form-control" name="cap" value="<?= h($P['indirizzo']['cap'] ?? '') ?>">
                  </div>
                  <div class="col-lg-4">
                    <label class="form-label">Comune</label>
                    <input type="text" class="form-control" name="comune" value="<?= h($P['indirizzo']['comune'] ?? '') ?>">
                  </div>
                </div>

                <div class="row g-3 mt-1">
                  <div class="col-lg-3">
                    <label class="form-label">Codice Fiscale (SIPEC)</label>
                    <input type="text" class="form-control" value="<?= h($cf) ?>" disabled>
                  </div>
                  <div class="col-lg-3">
                    <label class="form-label">N° Tesserino</label>
                    <input type="text" class="form-control" name="grado_numero" value="<?= h($P['grado_numero'] ?? '') ?>">
                  </div>
                  <div class="col-lg-3">
                    <label class="form-label">Cod. Sede (SIPEC)</label>
                    <input type="text" class="form-control" value="<?= h($codSede) ?>" disabled>
                  </div>
                  <div class="col-lg-3">
                    <label class="form-label">Grado (SIPEC/admin)</label>
                    <input type="text" class="form-control" value="<?= h($grado) ?>" disabled>
                  </div>
                </div>

                <div class="row g-3 mt-1">
                  <div class="col-lg-3">
                    <label class="form-label">Ingresso (SIPEC)</label>
                    <input type="date" class="form-control" value="<?= h($ingresso) ?>" disabled>
                  </div>
                  <div class="col-lg-3">
                    <label class="form-label">Uscita (SIPEC)</label>
                    <input type="date" class="form-control" value="<?= h($uscita) ?>" disabled>
                  </div>
                  <div class="col-lg-3">
                    <label class="form-label">Idoneità (SIPEC)</label>
                    <input type="text" class="form-control" value="<?= h(strtoupper(str_replace('_',' ',$idoneita))) ?>" disabled>
                  </div>
                  <div class="col-lg-3">
                    <label class="form-label">Scadenza Patente (se gestita)</label>
                    <input type="date" class="form-control" name="scadenza_patente_b" value="<?= h($P['scadenze']['patente_b'] ?? '') ?>">
                  </div>
                </div>

                <!-- Visita medica -->
                <div class="row g-3 mt-1">
                  <div class="col-lg-4">
                    <label class="form-label">Ultima visita medica</label>
                    <input type="date" class="form-control" id="ultima_visita" name="ultima_visita_medica" value="<?= h($ul_visita) ?>">
                    <div class="form-text">La scadenza viene calcolata automaticamente (+30 mesi).</div>
                  </div>
                  <div class="col-lg-4">
                    <label class="form-label">Scadenza visita medica (derivata)</label>
                    <input type="date" class="form-control" id="scadenza_vis" value="<?= h($scad_vis) ?>" disabled>
                  </div>
                </div>

                <div class="d-flex justify-content-end mt-3">
                  <button class="btn btn-primary">Salva i miei dati</button>
                </div>
              </form>
            </div>
          </div>
        </div>

        <!-- ===================== TAB: DOCUMENTI (galleria+upload) ===================== -->
        <div class="tab-pane fade" id="tab-documenti" role="tabpanel">
          <div class="card mb-4">
            <div class="card-header"><strong>Documento d’identità</strong></div>
            <div class="card-body">
              <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="_csrf" value="<?= h($CSRF) ?>">
                <input type="hidden" name="vigile_id" value="<?= (int)$me_id ?>">
                <div class="row g-3 align-items-end">
                  <div class="col-md-3">
                    <label class="form-label">Tipo</label>
                    <input type="text" class="form-control" name="doc_tipo" value="<?= h($P['documento_identita']['tipo'] ?? '') ?>">
                  </div>
                  <div class="col-md-3">
                    <label class="form-label">Numero</label>
                    <input type="text" class="form-control" name="doc_num" value="<?= h($P['documento_identita']['numero'] ?? '') ?>">
                  </div>
                  <div class="col-md-3">
                    <label class="form-label">Scadenza</label>
                    <input type="date" class="form-control" name="doc_scad" value="<?= h($P['documento_identita']['scadenza'] ?? '') ?>">
                  </div>
                </div>
                <div class="row g-3 mt-1">
                  <div class="col-md-3">
                    <label class="form-label">Fronte (jpg/png/pdf)</label>
                    <input type="file" class="form-control" name="doc_file_fronte" accept="image/*,.pdf">
                    <?php if ($di_front): ?>
                      <div class="mt-2 thumb-box">
                        <?php if (_is_image_url($di_front)): ?><img src="<?= h($di_front) ?>" alt="Doc fronte" class="file-thumb mb-1"><?php endif; ?>
                        <div class="small"><a href="<?= h($di_front) ?>" target="_blank" class="pill">Apri/Scarica fronte</a></div>
                      </div>
                    <?php endif; ?>
                  </div>
                  <div class="col-md-3">
                    <label class="form-label">Retro (jpg/png/pdf)</label>
                    <input type="file" class="form-control" name="doc_file_retro" accept="image/*,.pdf">
                    <?php if ($di_back): ?>
                      <div class="mt-2 thumb-box">
                        <?php if (_is_image_url($di_back)): ?><img src="<?= h($di_back) ?>" alt="Doc retro" class="file-thumb mb-1"><?php endif; ?>
                        <div class="small"><a href="<?= h($di_back) ?>" target="_blank" class="pill">Apri/Scarica retro</a></div>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>

                <hr class="my-3">

                <div class="h6 mb-2">Patente civile</div>
                <div class="row g-3 align-items-end">
                  <div class="col-md-3">
                    <label class="form-label">Numero</label>
                    <input type="text" class="form-control" name="pat_civ_num" value="<?= h($P['patente_civile']['numero'] ?? '') ?>">
                  </div>
                  <div class="col-md-3">
                    <label class="form-label">Scadenza</label>
                    <input type="date" class="form-control" name="pat_civ_scad" value="<?= h($P['patente_civile']['scadenza'] ?? '') ?>">
                  </div>
                  <div class="col-md-3">
                    <label class="form-label">Fronte (jpg/png/pdf)</label>
                    <input type="file" class="form-control" name="pat_civ_file_fronte" accept="image/*,.pdf">
                    <?php if ($pc_front): ?>
                      <div class="mt-2 thumb-box">
                        <?php if (_is_image_url($pc_front)): ?><img src="<?= h($pc_front) ?>" alt="Patente civile fronte" class="file-thumb mb-1"><?php endif; ?>
                        <div class="small"><a href="<?= h($pc_front) ?>" target="_blank" class="pill">Apri/Scarica fronte</a></div>
                      </div>
                    <?php endif; ?>
                  </div>
                  <div class="col-md-3">
                    <label class="form-label">Retro (jpg/png/pdf)</label>
                    <input type="file" class="form-control" name="pat_civ_file_retro" accept="image/*,.pdf">
                    <?php if ($pc_back): ?>
                      <div class="mt-2 thumb-box">
                        <?php if (_is_image_url($pc_back)): ?><img src="<?= h($pc_back) ?>" alt="Patente civile retro" class="file-thumb mb-1"><?php endif; ?>
                        <div class="small"><a href="<?= h($pc_back) ?>" target="_blank" class="pill">Apri/Scarica retro</a></div>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>
                <div class="mt-2">
                  <label class="form-label d-block">Categorie</label>
                  <div class="d-flex flex-wrap gap-3">
                    <?php
                      $CIVIL_CATS = ['AM','A1','A2','A','B1','B','BE','C1','C1E','C','CE','D1','D1E','D','DE'];
                      $selCats = (array)($P['patente_civile']['categorie'] ?? []);
                      foreach($CIVIL_CATS as $cat):
                        $checked = in_array($cat, $selCats, true) ? 'checked' : '';
                    ?>
                      <label class="form-check form-check-inline m-0">
                        <input class="form-check-input" type="checkbox" name="pat_civ_cat[]" value="<?= h($cat) ?>" <?= $checked ?>>
                        <span class="form-check-label"><?= h($cat) ?></span>
                      </label>
                    <?php endforeach; ?>
                  </div>
                </div>

                <hr class="my-3">

                <div class="h6 mb-2">Patente ministeriale</div>
                <div class="row g-3">
                  <div class="col-md-6">
                    <label class="form-label">Gradi abilitati</label>
                    <div class="d-flex flex-wrap gap-3">
                      <?php $PAT_MIN_OPTIONS = ['Nessuna','1° grado','2° grado','3° grado','Nautica'];
                      foreach ($PAT_MIN_OPTIONS as $lbl):
                        $ck = in_array($lbl,(array)($P['patenti'] ?? []),true) ? 'checked' : '';
                      ?>
                        <label class="form-check form-check-inline m-0">
                          <input class="form-check-input pat-min" type="checkbox" name="patenti_sel[]" value="<?= h($lbl) ?>" <?= $ck ?>>
                          <span class="form-check-label"><?= h($lbl) ?></span>
                        </label>
                      <?php endforeach; ?>
                    </div>
                    <div class="form-text">“Nessuna” è esclusiva: se la selezioni, le altre si disattivano.</div>
                  </div>
                  <div class="col-md-3">
                    <label class="form-label">Fronte (jpg/png/pdf)</label>
                    <input type="file" class="form-control" name="pat_min_file_fronte" accept="image/*,.pdf">
                    <?php if ($pm_front): ?>
                      <div class="mt-2 thumb-box">
                        <?php if (_is_image_url($pm_front)): ?><img src="<?= h($pm_front) ?>" alt="Patente ministeriale fronte" class="file-thumb mb-1"><?php endif; ?>
                        <div class="small"><a href="<?= h($pm_front) ?>" target="_blank" class="pill">Apri/Scarica fronte</a></div>
                      </div>
                    <?php endif; ?>
                  </div>
                  <div class="col-md-3">
                    <label class="form-label">Retro (jpg/png/pdf)</label>
                    <input type="file" class="form-control" name="pat_min_file_retro" accept="image/*,.pdf">
                    <?php if ($pm_back): ?>
                      <div class="mt-2 thumb-box">
                        <?php if (_is_image_url($pm_back)): ?><img src="<?= h($pm_back) ?>" alt="Patente ministeriale retro" class="file-thumb mb-1"><?php endif; ?>
                        <div class="small"><a href="<?= h($pm_back) ?>" target="_blank" class="pill">Apri/Scarica retro</a></div>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>

                <div class="d-flex justify-content-end mt-3">
                  <button class="btn btn-primary">Salva documenti</button>
                </div>
              </form>
            </div>
          </div>
        </div>

        <!-- ===================== TAB: ABILITAZIONI & DPI ===================== -->
        <div class="tab-pane fade" id="tab-abil" role="tabpanel">
          <div class="card mb-4">
            <div class="card-header"><strong>Abilitazioni ministeriali</strong></div>
            <div class="card-body">
              <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="_csrf" value="<?= h($CSRF) ?>">
                <input type="hidden" name="vigile_id" value="<?= (int)$me_id ?>">
                <div class="mb-3">
                  <label class="form-label">Lista (separate da virgola)</label>
                  <input type="text" class="form-control" name="abilitazioni" id="f_abilitazioni"
                        value="<?= h(implode(',', (array)($P['abilitazioni'] ?? []))) ?>" placeholder="APS, AS, NBCR, ...">
                </div>

                <div id="abil_rows" class="vstack gap-2">
                  <?php
                  $abil_rows = (array)($P['abilitazioni_dettaglio'] ?? []);
                  if (empty($abil_rows)) $abil_rows=[['nome'=>'','numero'=>'','scadenza'=>'','file'=>'']];
                  foreach ($abil_rows as $i=>$r):
                    $r_nome = h((string)($r['nome']??'')); $r_num=h((string)($r['numero']??'')); $r_sca=h((string)($r['scadenza']??'')); $r_file=(string)($r['file']??'');
                  ?>
                  <div class="border rounded p-2">
                    <div class="row g-2">
                      <div class="col-md-4">
                        <label class="form-label">Abilitazione</label>
                        <input class="form-control" name="abil_rows[<?= $i ?>][nome]" value="<?= $r_nome ?>">
                      </div>
                      <div class="col-md-3">
                        <label class="form-label">Numero</label>
                        <input class="form-control" name="abil_rows[<?= $i ?>][numero]" value="<?= $r_num ?>">
                      </div>
                      <div class="col-md-3">
                        <label class="form-label">Scadenza</label>
                        <input type="date" class="form-control" name="abil_rows[<?= $i ?>][scadenza]" value="<?= $r_sca ?>">
                      </div>
                      <div class="col-md-2">
                        <label class="form-label">File</label>
                        <input type="file" class="form-control" name="abil_file_<?= $i ?>" accept=".pdf,image/*">
                        <?php if ($r_file): ?>
                          <div class="form-text">
                            <?php if (_is_image_url($r_file)): ?><img src="<?= h($r_file) ?>" class="file-thumb mt-1"><?php endif; ?>
                            <a href="<?= h($r_file) ?>" target="_blank">Apri/Scarica</a>
                          </div>
                        <?php endif; ?>
                      </div>
                    </div>
                  </div>
                  <?php endforeach; ?>
                </div>
                <button class="btn btn-sm btn-outline-primary mt-2" type="button" id="btnAddAbil">+ Aggiungi abilitazione (dettaglio)</button>

                <hr class="my-4">

                <div class="h6 mb-2">Vestiario / DPI</div>
                <div id="dpi_rows" class="vstack gap-2">
                  <?php
                  $dpi_rows = (array)($P['dpi'] ?? []);
                  if (empty($dpi_rows)) $dpi_rows=[['item'=>'','taglia'=>'','assegnato'=>'','scadenza'=>'','quantita'=>1,'note'=>'','file'=>'']];
                  foreach ($dpi_rows as $j=>$r):
                    $i_item = h((string)($r['item']??'')); $i_taglia=h((string)($r['taglia']??''));
                    $i_ass  = h((string)($r['assegnato']??'')); $i_sca=h((string)($r['scadenza']??''));
                    $i_qta  = (int)($r['quantita']??1); $i_note=h((string)($r['note']??'')); $i_file=(string)($r['file']??'');
                  ?>
                  <div class="border rounded p-2">
                    <div class="row g-2">
                      <div class="col-md-4">
                        <label class="form-label">Articolo</label>
                        <input class="form-control" name="dpi_rows[<?= $j ?>][item]" value="<?= $i_item ?>">
                      </div>
                      <div class="col-md-2">
                        <label class="form-label">Taglia</label>
                        <input class="form-control" name="dpi_rows[<?= $j ?>][taglia]" value="<?= $i_taglia ?>">
                      </div>
                      <div class="col-md-2">
                        <label class="form-label">Assegnato il</label>
                        <input type="date" class="form-control" name="dpi_rows[<?= $j ?>][assegnato]" value="<?= $i_ass ?>">
                      </div>
                      <div class="col-md-2">
                        <label class="form-label">Scadenza</label>
                        <input type="date" class="form-control" name="dpi_rows[<?= $j ?>][scadenza]" value="<?= $i_sca ?>">
                      </div>
                      <div class="col-md-1">
                        <label class="form-label">Q.tà</label>
                        <input type="number" min="1" class="form-control" name="dpi_rows[<?= $j ?>][quantita]" value="<?= (int)$i_qta ?>">
                      </div>
                      <div class="col-md-3">
                        <label class="form-label">File</label>
                        <input type="file" class="form-control" name="dpi_file_<?= $j ?>" accept=".pdf,image/*">
                        <?php if ($i_file): ?>
                          <div class="form-text">
                            <?php if (_is_image_url($i_file)): ?><img src="<?= h($i_file) ?>" class="file-thumb mt-1"><?php endif; ?>
                            <a href="<?= h($i_file) ?>" target="_blank">Apri/Scarica</a>
                          </div>
                        <?php endif; ?>
                      </div>
                      <div class="col-12">
                        <label class="form-label">Note</label>
                        <input class="form-control" name="dpi_rows[<?= $j ?>][note]" value="<?= $i_note ?>">
                      </div>
                    </div>
                  </div>
                  <?php endforeach; ?>
                </div>
                <button class="btn btn-sm btn-outline-primary mt-2" type="button" id="btnAddDpi">+ Aggiungi DPI/Vestiario</button>

                <hr class="my-4">

                <div class="d-flex align-items-center justify-content-between">
                  <div class="h6 mb-0">Richieste a TRACK</div>
                  <button type="button" class="btn btn-sm btn-outline-secondary" onclick="printTrackCard()">Stampa riepilogo (PDF)</button>
                </div>
                <div class="text-muted small mt-1">Riportare di seguito il numero totale dei capi di vestiario e DPI per i quali sono presenti le relative richieste sul portale TRACK.</div>
                <div class="text-muted small mb-3">(non inserire altre richieste se non risultano registrate sul portale TRACK)</div>
                <?php
                  $trackReq = (array)($P['track_richieste'] ?? []);
                  $trackNome = trim((string)(($ME['cognome'] ?? '').' '.($ME['nome'] ?? '')));
                  $trackGrado = trim((string)$grado);
                  $trackDataComp = date('d/m/Y');
                ?>
                <div id="trackCard" class="border rounded p-3 bg-light-subtle"
                     data-nominativo="<?= h($trackNome) ?>"
                     data-grado="<?= h($trackGrado) ?>"
                     data-data="<?= h($trackDataComp) ?>">
                  <div class="mb-2 small">
                    <div><strong>Nominativo:</strong> <?= h($trackNome ?: '---') ?></div>
                    <div><strong>Grado:</strong> <?= h($trackGrado ?: '---') ?></div>
                    <div><strong>Data compilazione:</strong> <?= h($trackDataComp) ?></div>
                  </div>
                  <ul class="mb-0">
                    <?php foreach ($TRACK_ITEMS as $k=>$lbl):
                      $isReq = !empty($trackReq[$k]);
                    ?>
                      <li class="mb-3">
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                          <span><?= h($lbl) ?></span>
                          <div class="d-flex align-items-center gap-3">
                            <label class="form-check form-check-inline m-0">
                              <input class="form-check-input" type="radio" name="track_req[<?= h($k) ?>]" value="si" <?= $isReq ? 'checked' : '' ?>>
                              <span class="form-check-label">Richiesto</span>
                            </label>
                            <label class="form-check form-check-inline m-0">
                              <input class="form-check-input" type="radio" name="track_req[<?= h($k) ?>]" value="no" <?= !$isReq ? 'checked' : '' ?>>
                              <span class="form-check-label">Non richiesto</span>
                            </label>
                          </div>
                        </div>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                </div>

                <hr class="my-4">
                <div class="h6 mb-2">Note</div>
                <textarea class="form-control" rows="4" name="note" id="f_note"><?= h($P['note'] ?? '') ?></textarea>

                <div class="d-flex justify-content-end mt-3">
                  <button class="btn btn-primary">Salva abilitazioni & DPI</button>
                </div>
              </form>
            </div>
          </div>
        </div>

        <!-- ===================== TAB: ADDESTRAMENTI ===================== -->
        <div class="tab-pane fade" id="tab-add" role="tabpanel">
          <!-- STAT -->
          <div class="alert alert-info d-flex align-items-center justify-content-between">
            <div><strong>Ore totali addestramento:</strong> <?= h(fmt_hhmm($totalMin)) ?></div>
            <div class="small subtle">Somma di tutte le sessioni in archivio</div>
          </div>

          <!-- FILTRI -->
          <form class="row g-2 align-items-end mb-3" method="get">
            <div class="col-auto">
              <label class="form-label">Anno</label>
              <select class="form-select" name="anno">
                <?php foreach($years as $y): ?>
                  <option value="<?= h($y) ?>" <?= $selYear==$y?'selected':'' ?>><?= h($y) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-auto">
              <label class="form-label">Mese</label>
              <select class="form-select" name="mese">
                <option value="">— tutti —</option>
                <?php for($m=1;$m<=12;$m++): $mm=str_pad((string)$m,2,'0',STR_PAD_LEFT); ?>
                  <option value="<?= $mm ?>" <?= $selMonth===$mm?'selected':'' ?>><?= $mm ?></option>
                <?php endfor; ?>
              </select>
            </div>
            <div class="col-auto">
              <button class="btn btn-outline-primary">Applica</button>
            </div>
          </form>

          <!-- TABELLA -->
          <div class="card">
            <div class="card-header"><strong>I miei addestramenti</strong></div>
            <div class="card-body p-0">
              <?php
                $rowsShown = 0;
                if (!empty($byYM[$selYear])) {
                  $months = $byYM[$selYear];
                  krsort($months);
                  foreach($months as $mm=>$rows){
                    if ($selMonth!=='' && $mm!==$selMonth) continue;
                    $monthTot = (int)($totByYM[$selYear][$mm] ?? 0);
                    $rowsShown += count($rows);
              ?>
                <div class="px-3 pt-3">
                  <div class="d-flex align-items-center justify-content-between mb-2">
                    <h6 class="mb-0">Anno <?= h($selYear) ?> · Mese <?= h($mm) ?></h6>
                    <div class="small subtle">Totale mese: <strong><?= h(fmt_hhmm($monthTot)) ?></strong></div>
                  </div>
                  <div class="table-responsive mb-3">
                    <table class="table table-sm align-middle mb-0">
                      <thead class="table-light">
                        <tr>
                          <th style="width:140px">Data</th>
                          <th style="width:90px">Inizio</th>
                          <th style="width:90px">Fine</th>
                          <th style="width:110px">Durata</th>
                          <th>Attività</th>
                          <th>Note</th>
                          <th style="width:90px">Recupero</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach($rows as $r):
                          $data   = (string)($r['data'] ?? substr((string)($r['inizio_dt'] ?? ''),0,10));
                          $inizio = (string)($r['inizio'] ?? substr((string)($r['inizio_dt'] ?? ''),11,5));
                          $fine   = (string)($r['fine']   ?? substr((string)($r['fine_dt']   ?? ''),11,5));
                          $min    = rec_minutes($r);
                          $att    = trim((string)($r['attivita'] ?? ($r['titolo'] ?? '')));
                          $note   = trim((string)($r['note'] ?? ''));
                          $rec    = (int)($r['recupero'] ?? 0) === 1;
                        ?>
                        <tr>
                          <td><?= h(fmt_date_it($data)) ?></td>
                          <td><?= h(substr($inizio,0,5)) ?></td>
                          <td><?= h(substr($fine,0,5)) ?></td>
                          <td><?= h(fmt_hhmm($min)) ?></td>
                          <td><?= $att ? h($att) : '<span class="text-muted">—</span>' ?></td>
                          <td><?= $note ? h($note) : '<span class="text-muted">—</span>' ?></td>
                          <td><?= $rec ? '<span class="badge text-bg-warning-subtle border">Sì</span>' : '<span class="text-muted">No</span>' ?></td>
                        </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                </div>
              <?php
                  }
                }
                if ($rowsShown===0){
                  echo '<div class="p-4 text-center text-muted">Nessun addestramento per i filtri selezionati.</div>';
                }
              ?>
            </div>
          </div>
        </div>
      </div><!-- /tab-content -->
    </div><!-- /col-lg-8 -->
  </div><!-- /row -->
</div><!-- /container -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
  // Calcolo scadenza visita (auto +30 mesi)
  (function(){
    function addMonthsISO(dateStr, months){
      if(!dateStr) return '';
      var a=dateStr.split('-'); if(a.length!==3) return '';
      var y=+a[0], m=(+a[1])-1, d=+a[2];
      var dt=new Date(y,m,d); if(isNaN(dt.getTime())) return '';
      dt.setMonth(dt.getMonth()+months);
      var yy=dt.getFullYear(), mm=String(dt.getMonth()+1).padStart(2,'0'), dd=String(dt.getDate()).padStart(2,'0');
      return yy+'-'+mm+'-'+dd;
    }
    var ul = document.getElementById('ultima_visita');
    var sc = document.getElementById('scadenza_vis');
    if (ul && sc){ ul.addEventListener('change', function(){ sc.value = addMonthsISO(ul.value, 30); }); }
  })();

  // Aggiungi righe dinamiche Abilitazioni Dettaglio / DPI
  (function(){
    var abilWrap = document.getElementById('abil_rows');
    var btnAddAbil = document.getElementById('btnAddAbil');
    if (btnAddAbil && abilWrap){
      btnAddAbil.addEventListener('click', function(){
        var idx = abilWrap.querySelectorAll('.border.rounded').length;
        var html = ''
        + '<div class="border rounded p-2">'
        + '  <div class="row g-2">'
        + '    <div class="col-md-4"><label class="form-label">Abilitazione</label>'
        + '      <input class="form-control" name="abil_rows['+idx+'][nome]"></div>'
        + '    <div class="col-md-3"><label class="form-label">Numero</label>'
        + '      <input class="form-control" name="abil_rows['+idx+'][numero]"></div>'
        + '    <div class="col-md-3"><label class="form-label">Scadenza</label>'
        + '      <input type="date" class="form-control" name="abil_rows['+idx+'][scadenza]"></div>'
        + '    <div class="col-md-2"><label class="form-label">File</label>'
        + '      <input type="file" class="form-control" name="abil_file_'+idx+'" accept=".pdf,image/*"></div>'
        + '  </div>'
        + '</div>';
        abilWrap.insertAdjacentHTML('beforeend', html);
      });
    }

    var dpiWrap = document.getElementById('dpi_rows');
    var btnAddDpi = document.getElementById('btnAddDpi');
    if (btnAddDpi && dpiWrap){
      btnAddDpi.addEventListener('click', function(){
        var idx = dpiWrap.querySelectorAll('.border.rounded').length;
        var html = ''
        + '<div class="border rounded p-2">'
        + '  <div class="row g-2">'
        + '    <div class="col-md-4"><label class="form-label">Articolo</label>'
        + '      <input class="form-control" name="dpi_rows['+idx+'][item]"></div>'
        + '    <div class="col-md-2"><label class="form-label">Taglia</label>'
        + '      <input class="form-control" name="dpi_rows['+idx+'][taglia]"></div>'
        + '    <div class="col-md-2"><label class="form-label">Assegnato il</label>'
        + '      <input type="date" class="form-control" name="dpi_rows['+idx+'][assegnato]"></div>'
        + '    <div class="col-md-2"><label class="form-label">Scadenza</label>'
        + '      <input type="date" class="form-control" name="dpi_rows['+idx+'][scadenza]"></div>'
        + '    <div class="col-md-1"><label class="form-label">Q.tà</label>'
        + '      <input type="number" min="1" class="form-control" name="dpi_rows['+idx+'][quantita]" value="1"></div>'
        + '    <div class="col-md-3"><label class="form-label">File</label>'
        + '      <input type="file" class="form-control" name="dpi_file_'+idx+'" accept=".pdf,image/*"></div>'
        + '    <div class="col-12"><label class="form-label">Note</label>'
        + '      <input class="form-control" name="dpi_rows['+idx+'][note]"></div>'
        + '  </div>'
        + '</div>';
        dpiWrap.insertAdjacentHTML('beforeend', html);
      });
    }

    // Patenti ministeriali: “Nessuna” esclusiva
    document.addEventListener('change', function(ev){
      var cb = ev.target;
      if (!cb || cb.name !== 'patenti_sel[]') return;
      var all = Array.from(document.querySelectorAll('input[name="patenti_sel[]"]'));
      var isNone = (cb.value === 'Nessuna');
      if (isNone && cb.checked) {
        all.forEach(x=>{ if (x.value!=='Nessuna') x.checked=false; });
      } else if (!isNone && cb.checked) {
        var none = all.find(x=>x.value==='Nessuna');
        if (none) none.checked = false;
      }
    });

    // Stampa solo la card TRACK (usa stampa browser -> PDF)
    window.printTrackCard = function(){
      var card = document.getElementById('trackCard');
      if (!card) return;
      var nominativo = card.dataset.nominativo || '';
      var grado = card.dataset.grado || '';
      var dataComp = card.dataset.data || '';
      var w = window.open('', '_blank', 'width=900,height=700');
      if (!w) return;
      var html = ''
        + '<html><head><title>Richieste a TRACK</title>'
        + '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">'
        + '</head><body class="p-3">'
        + '<h4 class="mb-3">Richieste a TRACK</h4>'
        + '<div class="mb-3 small">'
        + '  <div><strong>Nominativo:</strong> '+(nominativo || '---')+'</div>'
        + '  <div><strong>Grado:</strong> '+(grado || '---')+'</div>'
        + '  <div><strong>Data compilazione:</strong> '+(dataComp || '')+'</div>'
        + '</div>'
        + '<div class="text-muted small">Riportare di seguito il numero totale dei capi di vestiario e DPI per i quali sono presenti le relative richieste sul portale TRACK.</div>'
        + '<div class="text-muted small mb-3">(non inserire altre richieste se non risultano registrate sul portale TRACK)</div>'
        + card.outerHTML
        + '</body></html>';
      w.document.write(html);
      w.document.close();
      w.focus();
      w.onload = function(){ w.print(); w.close(); };
    };
  })();
</script>
</body>
</html>
