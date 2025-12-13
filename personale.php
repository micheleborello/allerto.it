<?php
// personale.php — menu + tenant + normalizzazione + PULL SIPEC + campi SIPEC readonly + fix link Doc. + colonna Ingresso più larga

ob_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

require __DIR__.'/auth.php';
require __DIR__.'/tenant_bootstrap.php';

if (function_exists('require_tenant_user')) { require_tenant_user(); }
if (function_exists('require_perm')) { require_perm('view:personale'); require_perm('edit:personale'); }
if (!function_exists('require_mfa')) { function require_mfa(){} }
require_mfa();

if (session_status() === PHP_SESSION_NONE) @session_start();

/* ===== SLUG ===== */
$slug = $_GET['slug']
  ?? ($_SESSION['tenant_slug'] ?? (function_exists('tenant_active_slug') ? tenant_active_slug() : ''));
$slug = preg_replace('/[^a-z0-9_-]/i','', (string)$slug);

/* ===== BASE DIR ===== */
if (!defined('DATA_DIR')) {
  if ($slug !== '') define('DATA_DIR', __DIR__.'/data/'.$slug);
  else              define('DATA_DIR', __DIR__.'/data');
}
@mkdir(DATA_DIR, 0775, true);

if (!defined('VIGILI_JSON'))     define('VIGILI_JSON',     DATA_DIR.'/vigili.json');
if (!defined('PERSONALE_JSON'))  define('PERSONALE_JSON',  DATA_DIR.'/personale.json');

/* ===== Helpers JSON ===== */
if (!function_exists('load_json_relaxed')) {
  function load_json_relaxed($path){
    if (!is_file($path)) return [];
    $s=@file_get_contents($path); if($s===false) return [];
    $s=preg_replace('/^\xEF\xBB\xBF/','',$s);
    $s=preg_replace('/,\s*([\}\]])/m','$1',$s);
    $j=json_decode($s,true);
    return is_array($j)?$j:[];
  }
}
if (!function_exists('save_json_atomic')) {
  function save_json_atomic($path,$data){
    @mkdir(dirname($path),0775,true);
    $tmp = $path.'.tmp';
    $ok  = @file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    if ($ok === false) throw new RuntimeException("Impossibile scrivere $tmp");
    if (!@rename($tmp, $path)) throw new RuntimeException("Impossibile rinominare $tmp in $path");
    @chmod($path, 0664);
  }
}

/* ===== Normalizzazione personale.json ===== */
function _ensure_personale_is_map($arr){
  if (!is_array($arr)) return [];
  if (array_key_exists('0', $arr) && is_array($arr['0'])) {
    foreach ($arr['0'] as $k=>$v) if (is_array($v)) $arr[$k]=$v;
    unset($arr['0']);
  }
  $keys = array_keys($arr);
  $isList = $keys === range(0, max(0, count($arr)-1));
  if ($isList) {
    $map=[];
    foreach ($arr as $row) {
      if (is_array($row)) {
        $id=(int)($row['id']??0);
        if ($id>0) $map[$id]=$row;
      }
    }
    $arr=$map;
  }
  foreach ($arr as $k=>$v) if (!is_array($v)) unset($arr[$k]);
  return $arr;
}

/* ===== Load/Save ===== */
if (!function_exists('load_vigili'))  { function load_vigili(){ return sanitize_vigili_list(load_json_relaxed(VIGILI_JSON)); } }
if (!function_exists('save_vigili'))  { function save_vigili($rows){ save_json_atomic(VIGILI_JSON,$rows); } }
if (!function_exists('load_personale')){
  function load_personale(){ return _ensure_personale_is_map(load_json_relaxed(PERSONALE_JSON)); }
}
if (!function_exists('save_personale')){ function save_personale($map){ save_json_atomic(PERSONALE_JSON,$map); } }

/* ===== Polyfills ===== */
if (!function_exists('str_starts_with')) {
  function str_starts_with($haystack, $needle) {
    if ($needle === '') return true;
    return strpos($haystack, $needle) === 0;
  }
}

/* ===== Upload ===== */
const UP_SUB_DOC_ID       = 'doc_identita';
const UP_SUB_PAT_CIVILE   = 'patente_civile';
const UP_SUB_PAT_MIN      = 'patente_ministeriale';
const UP_SUB_ABIL         = 'abilitazioni';
const UP_SUB_DPI          = 'dpi';
const UP_SUB_NOTE_DOCS    = 'note_docs';

$UPLOAD_BASE = DATA_DIR . '/uploads';
@mkdir($UPLOAD_BASE, 0775, true);
@mkdir($UPLOAD_BASE.'/'.UP_SUB_DOC_ID, 0775, true);
@mkdir($UPLOAD_BASE.'/'.UP_SUB_PAT_CIVILE, 0775, true);
@mkdir($UPLOAD_BASE.'/'.UP_SUB_PAT_MIN, 0775, true);
@mkdir($UPLOAD_BASE.'/'.UP_SUB_ABIL, 0775, true);
@mkdir($UPLOAD_BASE.'/'.UP_SUB_DPI, 0775, true);
@mkdir($UPLOAD_BASE.'/'.UP_SUB_NOTE_DOCS, 0775, true);

/* ===== Opzioni UI ===== */
if (!isset($PAT_MIN_OPTIONS)) { $PAT_MIN_OPTIONS = ['Nessuna','1° grado','2° grado','3° grado','Nautica']; }
if (!isset($GRADO_OPTIONS))   { $GRADO_OPTIONS   = ['','ASP.','ASP. DEC.','VIG.','CSV.','CRV.','FTAV.']; }
if (!isset($CIVIL_CATS))      { $CIVIL_CATS      = ['AM','A1','A2','A','B1','B','BE','C1','C1E','C','CE','D1','D1E','D','DE']; }
if (!isset($TRACK_ITEMS)) {
  $TRACK_ITEMS = [
    'elmo_intervento'      => 'Elmo da intervento',
    'sottocasco_ignifugo'  => 'Sotto casco ignifugo',
    'elmo_multifunzione'   => 'Elmo multifunzione',
    'completo_antifiamma'  => 'Completo antifiamma (Giacca e pantaloni Nomex)',
    'guanti_intervento'    => 'Guanti da intervento',
    'calzature_intervento' => 'Calzature da intervento (Stivali)',
    'completo_antipioggia' => 'Completo anti pioggia (Giacca e pantaloni)',
  ];
}

/* ===== Utils file ===== */
const REALLY_UNLINK_FILES = true;
function safe_name($s){ $s=preg_replace('/[^\p{L}\p{N}\._-]+/u','_', (string)$s); return trim($s,'_'); }
function rel_from_abs($abs){ return str_replace(__DIR__.'/', '', $abs); }
function abs_from_rel($rel){ return __DIR__.'/'.$rel; }
function maybe_unlink($rel){
  if(!REALLY_UNLINK_FILES) return;
  if(!$rel) return;
  $abs = abs_from_rel($rel);
  if(str_starts_with($abs, DATA_DIR) && is_file($abs)) @unlink($abs);
}

function handle_upload($field,$subdir,$prevRel=''){
  if (!isset($_FILES[$field]) || !is_array($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return $prevRel;
  $tmp=$_FILES[$field]['tmp_name'];
  $name=safe_name($_FILES[$field]['name'] ?? 'file');
  $ext=strtolower(pathinfo($name, PATHINFO_EXTENSION));
  $base=pathinfo($name, PATHINFO_FILENAME);
  $destAbs = DATA_DIR.'/uploads/'.$subdir.'/'.$base.'_'.date('Ymd_His').($ext?'.'.$ext:'');
  if (!@move_uploaded_file($tmp,$destAbs)) return $prevRel;
  @chmod($destAbs,0664);
  return rel_from_abs($destAbs);
}
function handle_uploads_multi($field, $subdir, $prevList = []) {
  if (empty($_FILES[$field])) return is_array($prevList)?$prevList:[];
  $files = $_FILES[$field];
  $saved = is_array($prevList) ? $prevList : [];
  $count = is_array($files['name']) ? count($files['name']) : 0;
  for ($i=0; $i<$count; $i++) {
    $err = $files['error'][$i] ?? UPLOAD_ERR_NO_FILE;
    if ($err !== UPLOAD_ERR_OK) continue;
    $tmp  = $files['tmp_name'][$i];
    $name = safe_name($files['name'][$i] ?? ('file_'.$i));
    $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $base = pathinfo($name, PATHINFO_FILENAME);
    $destAbs = DATA_DIR.'/uploads/'.$subdir.'/'.$base.'_'.date('Ymd_His').'_'.$i.($ext?'.'.$ext:'');
    if (@move_uploaded_file($tmp, $destAbs)) {
      @chmod($destAbs, 0664);
      $saved[] = rel_from_abs($destAbs);
    }
  }
  return $saved;
}

/* ===== SIPEC helpers ===== */
if (!defined('SIPEC_URL')) define('SIPEC_URL', 'https://allerto.vvf.to.it/allerto/sipec');
$__DATA_BASE   = dirname(DATA_DIR);
$COD_MAP_FILE  = $__DATA_BASE . '/cod_sede_map.json';

function _parse_sipec_date_smart($s){
  $s = trim((string)$s);
  if ($s==='') return null;
  if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{2,4})$/', $s, $m)) {
    $d=(int)$m[1]; $mo=(int)$m[2]; $y=(int)$m[3]; if($y<100) $y+=2000;
    if (checkdate($mo,$d,$y)) return sprintf('%04d-%02d-%02d',$y,$mo,$d);
  }
  $map = ['GEN'=>'01','FEB'=>'02','MAR'=>'03','APR'=>'04','MAG'=>'05','GIU'=>'06','LUG'=>'07','AGO'=>'08','SET'=>'09','OTT'=>'10','NOV'=>'11','DIC'=>'12'];
  if (preg_match('/^(\d{1,2})-([A-Z]{3})-(\d{2})$/u', strtoupper($s), $m)) {
    $d=(int)$m[1]; $mo=$map[$m[2]]??null; $y=2000+(int)$m[3];
    if ($mo && checkdate((int)$mo,$d,$y)) return sprintf('%04d-%02d-%02d',$y,(int)$mo,$d);
  }
  return null;
}
function _map_grado($qualifica){
  $q = strtoupper(trim((string)$qualifica));
  return match($q){
    'VV'  => 'VIG.',
    'CSV' => 'CSV.',
    'CRV' => 'CRV.',
    'FTAV'=> 'FTAV.',
    default => $q,
  };
}
function _ministeriale_from_specials(array $specials){
  $grades = [];
  $expDates = [];
  foreach ($specials as $sp){
    $cod = strtoupper((string)($sp['codice'] ?? ''));
    $fine = _parse_sipec_date_smart((string)($sp['fine'] ?? ''));
    if ($cod==='AM1'){ $grades[]='1° grado'; if($fine) $expDates[]=$fine; }
    if ($cod==='AM2'){ $grades[]='2° grado'; if($fine) $expDates[]=$fine; }
    if ($cod==='AM3'){ $grades[]='3° grado'; if($fine) $expDates[]=$fine; }
  }
  $grades = array_values(array_unique($grades));
  sort($grades);
  $expiry = null;
  if ($expDates){ rsort($expDates); $expiry = $expDates[0]; }
  return [$grades, $expiry];
}
function _resolve_sede_name($cod, $codMap){
  $cod = trim((string)$cod);
  if ($cod==='') return '';
  $row = $codMap[$cod] ?? null;
  if (!is_array($row)) return 'Distaccamento '.$cod;
  foreach (['name','nome','label','denominazione'] as $k){
    if (!empty($row[$k])) return (string)$row[$k];
  }
  return 'Distaccamento '.$cod;
}

/* ===== Load data ===== */
try { $vigili = load_vigili(); }       catch(Throwable $e){ $vigili=[]; }
try { $personale = load_personale(); } catch(Throwable $e){ $personale=[]; }
if (!is_array($vigili)) $vigili=[];
if (!is_array($personale)) $personale=[];
usort($vigili, fn($a,$b)=>strcmp(($a['cognome']??'').' '.($a['nome']??''), ($b['cognome']??'').' '.($b['nome']??'')));

$err = $_GET['err'] ?? '';
$msg = $_GET['msg'] ?? '';

/* ===== POST: PULL SIPEC ===== */
if ($_SERVER['REQUEST_METHOD']==='POST' && (($_POST['action']??'')==='sipec_pull')) {
  $id = (int)($_POST['vigile_id'] ?? 0);
  $qs = ($slug!=='' ? '&slug='.urlencode($slug) : '');
  if ($id<=0) { header('Location: personale.php?err=sipec_param'.$qs); exit; }

  $vigileCorrente = null;
  foreach ($vigili as $vv) { if ((int)($vv['id']??0)===$id) { $vigileCorrente=$vv; break; } }
  if (!$vigileCorrente) { header('Location: personale.php?err=sipec_notfound'.$qs); exit; }

  $codMap = load_json_relaxed($COD_MAP_FILE);

  $ctx = stream_context_create(['http'=>['timeout'=>15,'ignore_errors'=>true]]);
  $raw = @file_get_contents(SIPEC_URL,false,$ctx);
  if ($raw===false || trim($raw)==='') { header('Location: personale.php?err=sipec_down'.$qs); exit; }
  $arr = json_decode($raw,true);
  if (!is_array($arr)) { header('Location: personale.php?err=sipec_json'.$qs); exit; }

  $rowsOk = [];
  $slugAttivo = (string)$slug;
  foreach ($arr as $r) {
    $cod = trim((string)($r['cod_sede']??''));
    if ($cod==='') continue;
    $mapSlug = '';
    if (isset($codMap[$cod]['slug'])) $mapSlug = preg_replace('/[^a-z0-9_-]/i','',(string)$codMap[$cod]['slug']);
    if ($mapSlug==='') $mapSlug = 's'.$cod;
    if (strcasecmp($mapSlug,$slugAttivo)===0) $rowsOk[] = $r;
  }

  $CF  = strtoupper(trim((string)($vigileCorrente['cf']??'')));
  $USR = strtolower(trim((string)($vigileCorrente['username']??'')));
  $NOM = trim((string)($vigileCorrente['nome']??''));
  $COG = trim((string)($vigileCorrente['cognome']??''));

  $match = null;
  if ($CF!=='') {
    foreach ($rowsOk as $r) { if (strtoupper(trim((string)($r['cf']??''))) === $CF) { $match = $r; break; } }
  }
  if (!$match && $USR!=='') {
    foreach ($rowsOk as $r) { if (strtolower(trim((string)($r['user']??''))) === $USR) { $match = $r; break; } }
  }
  if (!$match) {
    foreach ($rowsOk as $r) {
      $rn = trim((string)($r['nome']??'')); $rc = trim((string)($r['cognome']??''));
      if (strcasecmp($rn,$NOM)===0 && strcasecmp($rc,$COG)===0) { $match=$r; break; }
    }
  }
  if (!$match) { header('Location: personale.php?err=sipec_no_match'.$qs); exit; }

  foreach ($vigili as &$vv) {
    if ((int)($vv['id']??0)!==$id) continue;
    $newNome    = trim((string)($match['nome']??''));
    $newCognome = trim((string)($match['cognome']??''));
    $newCF      = strtoupper(trim((string)($match['cf']??'')));
    if ($newNome!=='')    $vv['nome']=$newNome;
    if ($newCognome!=='') $vv['cognome']=$newCognome;
    if ($newCF!=='')      $vv['cf']=$newCF;
    $vv['attivo']=1;
    $vv['grado']= _map_grado((string)($match['qualifica']??($vv['grado']??'')));
    break;
  }
  unset($vv);
  save_vigili($vigili);

  if (!isset($personale[$id]) || !is_array($personale[$id])) $personale[$id]=[];
  $P = &$personale[$id];

  $t1 = trim((string)($match['telefoni'][0]['tel1']??'')); if ($t1!=='' && $t1!=='-') $P['contatti']['telefono']=$t1;
  $t2 = trim((string)($match['telefoni'][0]['tel2']??'')); if ($t2!=='' && $t2!=='-') $P['contatti']['telefono1']=$t2;
  $t3 = trim((string)($match['telefoni'][0]['tel3']??'')); if ($t3!=='' && $t3!=='-') $P['contatti']['telefono2']=$t3;

  $via = trim((string)($match['via']??'')); if ($via!=='') $P['indirizzo']['via']=$via;
  $cap = trim((string)($match['cap']??'')); if ($cap!=='') $P['indirizzo']['cap']=$cap;
  $com = trim((string)($match['residenza_comune']??'')); if ($com!=='') $P['indirizzo']['comune']=$com;

  $P['grado'] = _map_grado((string)($match['qualifica']??($P['grado']??'')));
  $P['cf'] = strtoupper(trim((string)($match['cf']??($P['cf']??''))));
  $cod_sede = trim((string)($match['cod_sede']??'')); if ($cod_sede!=='') $P['cod_sede']=$cod_sede;
  $cod_sede_agg = trim((string)($match['cod_sede_aggregato']??'')); if ($cod_sede_agg!=='') $P['cod_sede_aggregato']=$cod_sede_agg;

  $codMap = load_json_relaxed($COD_MAP_FILE);
  if (!empty($P['cod_sede_aggregato'])) {
    $P['cod_sede_aggregato_nome'] = _resolve_sede_name($P['cod_sede_aggregato'], $codMap);
  }

  $ultima   = _parse_sipec_date_smart((string)($match['data_certificazione']??''));
  $scadenza = _parse_sipec_date_smart((string)($match['data_certificazione_scadenza']??''));
  if ($ultima!==null)   $P['scadenze']['visita_medica_ultima'] = $ultima;
  if ($scadenza!==null) $P['scadenze']['visita_medica']        = $scadenza;

  [$grades, $expiry] = _ministeriale_from_specials((array)($match['specializzazioni']??[]));
  if ($grades) $P['patenti'] = $grades;
  if ($expiry) $P['scadenze']['patente_b'] = $expiry;

  $inizioQual = _parse_sipec_date_smart((string)($match['dataInizioQualifica']??''));
  if ($inizioQual) $P['data_inizio_qualifica'] = $inizioQual;

  $ing = _parse_sipec_date_smart((string)($match['dataAssunzione']??''));
  if ($ing) $P['ingresso'] = $ing;

  unset($P);
  save_personale($personale);

  header('Location: personale.php?msg=sipec_ok'.($slug!==''?'&slug='.urlencode($slug):'')); exit;
}

/* ===== POST: SALVA ===== */
if ($_SERVER['REQUEST_METHOD']==='POST' && (($_POST['action']??'')!=='sipec_pull')){
  $id=(int)($_POST['vigile_id']??0);
  $qs = ($slug!==''?'&slug='.urlencode($slug):'');
  if ($id<=0){ header('Location: personale.php?err=param'.$qs); exit; }

  $telefono=trim((string)($_POST['telefono']??''));
  $email   =trim((string)($_POST['email']??''));
  $via     =trim((string)($_POST['via']??''));
  $cap     =trim((string)($_POST['cap']??''));
  $comune  =trim((string)($_POST['comune']??''));
  $ingresso=trim((string)($_POST['ingresso']??''));
  $uscita  =trim((string)($_POST['uscita']??''));
  $idoneita=in_array(($_POST['idoneita']??''),['operativo','non_operativo','sospeso'],true)?$_POST['idoneita']:'operativo';

  $data_nascita = trim((string)($_POST['data_nascita'] ?? ''));
  $grado        = trim((string)($_POST['grado'] ?? ''));
  $grado_numero = trim((string)($_POST['grado_numero'] ?? ''));
  $data_inizio_qualifica = trim((string)($_POST['data_inizio_qualifica'] ?? ''));
  $is_capo_form = isset($_POST['is_capo'])?1:0;

  $pat_civ_num = trim((string)($_POST['patente_civile_num'] ?? ''));
  $pat_civ_scad= trim((string)($_POST['patente_civile_scad'] ?? ''));
  $pat_civ_cat = [];
  if (!empty($_POST['patente_civile_cat']) && is_array($_POST['patente_civile_cat'])) {
    $pat_civ_cat = array_values(array_filter(array_map('trim', $_POST['patente_civile_cat']), fn($x)=>$x!==''));
  }

  $doc_tipo = trim((string)($_POST['doc_tipo'] ?? ''));
  $doc_num  = trim((string)($_POST['doc_num'] ?? ''));
  $doc_scad = trim((string)($_POST['doc_scad'] ?? ''));

  $patenti=[];
  if (!empty($_POST['patenti_sel']) && is_array($_POST['patenti_sel'])) {
    $sel = array_values(array_filter(array_map('trim', $_POST['patenti_sel']), fn($x)=>$x!==''));
    $hasOthers = count(array_diff($sel, ['Nessuna']))>0;
    $patenti = $hasOthers ? array_values(array_diff($sel, ['Nessuna'])) : (in_array('Nessuna',$sel,true)?['Nessuna']:[]);
  } else {
    $patenti = array_values(array_filter(array_map('trim', explode(',', (string)($_POST['patenti'] ?? ''))), fn($x)=>$x!==''));
  }

  $abilit_simple = array_values(array_filter(array_map('trim', explode(',', (string)($_POST['abilitazioni'] ?? ''))), fn($x)=>$x!==''));

  $abil_rows=[];
  if (!empty($_POST['abil_rows']) && is_array($_POST['abil_rows'])) {
    foreach ($_POST['abil_rows'] as $row){
      $nome=trim((string)($row['nome']??'')); if($nome==='') continue;
      $abil_rows[]=['nome'=>$nome,'numero'=>trim((string)($row['numero']??'')),'scadenza'=>trim((string)($row['scadenza']??'')),'file'=>''];
    }
  }

  $dpi_rows=[];
  if (!empty($_POST['dpi_rows']) && is_array($_POST['dpi_rows'])) {
    foreach ($_POST['dpi_rows'] as $row){
      $item=trim((string)($row['item']??'')); if($item==='') continue;
      $dpi_rows[]=[
        'item'=>$item,'taglia'=>trim((string)($row['taglia']??'')),
        'assegnato'=>trim((string)($row['assegnato']??'')),
        'scadenza'=>trim((string)($row['scadenza']??'')),
        'quantita'=>(int)($row['quantita']??1),
        'note'=>trim((string)($row['note']??'')),
        'file'=>'',
      ];
    }
  }

  $note = trim((string)($_POST['note'] ?? ''));

  if (!isset($personale[$id]) || !is_array($personale[$id])) $personale[$id]=[];
  $personale[$id]['contatti']  = $personale[$id]['contatti']  ?? [];
  $personale[$id]['indirizzo'] = $personale[$id]['indirizzo'] ?? [];
  $personale[$id]['scadenze']  = $personale[$id]['scadenze']  ?? [];

  if ($telefono!=='') $personale[$id]['contatti']['telefono']=$telefono;
  $personale[$id]['contatti']['email']=$email;
  if ($via!=='') $personale[$id]['indirizzo']['via']=$via;
  if ($cap!=='') $personale[$id]['indirizzo']['cap']=$cap;
  if ($comune!=='') $personale[$id]['indirizzo']['comune']=$comune;
  if ($ingresso!=='') $personale[$id]['ingresso']=$ingresso;
  $personale[$id]['uscita']=$uscita;
  $personale[$id]['idoneita']=$idoneita;

  if ($patenti) $personale[$id]['patenti']=$patenti;
  $personale[$id]['abilitazioni']=$abilit_simple;

  $ultima_visita=trim((string)($_POST['ultima_visita_medica']??''));
  if ($ultima_visita!==''){
    $personale[$id]['scadenze']['visita_medica_ultima']=$ultima_visita;
    $dt=DateTime::createFromFormat('Y-m-d',$ultima_visita);
    if($dt!==false){ $dt->add(new DateInterval('P30M')); $personale[$id]['scadenze']['visita_medica']=$dt->format('Y-m-d'); }
  } elseif (array_key_exists('scadenza_visita', $_POST)) {
    $personale[$id]['scadenze']['visita_medica']=trim((string)$_POST['scadenza_visita']);
  }
  if (array_key_exists('scadenza_patente_b', $_POST)) {
    $personale[$id]['scadenze']['patente_b']=trim((string)$_POST['scadenza_patente_b']);
  }

  $personale[$id]['data_nascita']=$data_nascita;
  if ($grado!=='') $personale[$id]['grado']=$grado;
  $personale[$id]['grado_numero']=$grado_numero;
  if ($data_inizio_qualifica!=='') $personale[$id]['data_inizio_qualifica']=$data_inizio_qualifica;

  $personale[$id]['documento_identita'] = $personale[$id]['documento_identita'] ?? [];
  $personale[$id]['documento_identita']['tipo']=$doc_tipo;
  $personale[$id]['documento_identita']['numero']=$doc_num;
  $personale[$id]['documento_identita']['scadenza']=$doc_scad;
  $prev_doc_front=(string)($personale[$id]['documento_identita']['file_fronte'] ?? ($personale[$id]['documento_identita']['file'] ?? ''));
  $prev_doc_back =(string)($personale[$id]['documento_identita']['file_retro']  ?? '');
  $personale[$id]['documento_identita']['file_fronte']=handle_upload('doc_file_fronte',UP_SUB_DOC_ID,$prev_doc_front);
  $personale[$id]['documento_identita']['file_retro'] =handle_upload('doc_file_retro', UP_SUB_DOC_ID,$prev_doc_back);

  $personale[$id]['patente_civile'] = $personale[$id]['patente_civile'] ?? [];
  $personale[$id]['patente_civile']['numero']=$pat_civ_num;
  $personale[$id]['patente_civile']['scadenza']=$pat_civ_scad;
  $personale[$id]['patente_civile']['categorie']=$pat_civ_cat;
  $prev_pc_front=(string)($personale[$id]['patente_civile']['file_fronte'] ?? ($personale[$id]['patente_civile']['file'] ?? ''));
  $prev_pc_back =(string)($personale[$id]['patente_civile']['file_retro']  ?? '');
  $personale[$id]['patente_civile']['file_fronte']=handle_upload('pat_civ_file_fronte',UP_SUB_PAT_CIVILE,$prev_pc_front);
  $personale[$id]['patente_civile']['file_retro'] =handle_upload('pat_civ_file_retro', UP_SUB_PAT_CIVILE,$prev_pc_back);

  $prev_pm_front=(string)($personale[$id]['patente_ministeriale']['file_fronte'] ?? ($personale[$id]['patente_ministeriale_file'] ?? ''));
  $prev_pm_back =(string)($personale[$id]['patente_ministeriale']['file_retro']  ?? '');
  $personale[$id]['patente_ministeriale'] = $personale[$id]['patente_ministeriale'] ?? [];
  $personale[$id]['patente_ministeriale']['file_fronte']=handle_upload('pat_min_file_fronte',UP_SUB_PAT_MIN,$prev_pm_front);
  $personale[$id]['patente_ministeriale']['file_retro'] =handle_upload('pat_min_file_retro', UP_SUB_PAT_MIN,$prev_pm_back);
  $personale[$id]['patente_ministeriale_file'] = $personale[$id]['patente_ministeriale']['file_fronte'] ?: ($personale[$id]['patente_ministeriale_file'] ?? '');

  $prev_abil_rows=(array)($personale[$id]['abilitazioni_dettaglio']??[]);
  foreach($abil_rows as $k=>$row){
    $prevFile = isset($prev_abil_rows[$k]['file']) ? (string)$prev_abil_rows[$k]['file'] : '';
    $field='abil_file_'.$k;
    if (isset($_FILES[$field])){ $_FILES['abil_file']=$_FILES[$field]; $abil_rows[$k]['file']=handle_upload('abil_file',UP_SUB_ABIL,$prevFile); unset($_FILES['abil_file']); }
    else { $abil_rows[$k]['file']=$prevFile; }
  }
  $personale[$id]['abilitazioni_dettaglio']=$abil_rows;

  $prev_dpi_rows=(array)($personale[$id]['dpi']??[]);
  foreach($dpi_rows as $k=>$row){
    $prevFile = isset($prev_dpi_rows[$k]['file']) ? (string)$prev_dpi_rows[$k]['file'] : '';
    $field='dpi_file_'.$k;
    if (isset($_FILES[$field])){ $_FILES['dpi_file']=$_FILES[$field]; $dpi_rows[$k]['file']=handle_upload('dpi_file',UP_SUB_DPI,$prevFile); unset($_FILES['dpi_file']); }
    else { $dpi_rows[$k]['file']=$prevFile; }
  }
  $personale[$id]['dpi']=$dpi_rows;

  $track_req = [];
  if (!empty($TRACK_ITEMS) && is_array($TRACK_ITEMS)) {
    foreach ($TRACK_ITEMS as $k=>$lbl) {
      $v = strtolower((string)($_POST['track_req'][$k] ?? ''));
      $track_req[$k] = ($v === 'si');
    }
  }
  $personale[$id]['track_richieste'] = $track_req;

  $personale[$id]['note']=$note;

  $note_docs_rows=[];
  if (!empty($_POST['doc_extra_rows']) && is_array($_POST['doc_extra_rows'])) {
    foreach ($_POST['doc_extra_rows'] as $i=>$r){
      $titolo = trim((string)($r['titolo']??''));
      $tipo   = trim((string)($r['tipo']??''));
      $descr  = trim((string)($r['descrizione']??''));
      if ($titolo==='' && $tipo==='' && $descr==='') {
        $prevFiles = (array)($personale[$id]['note_docs'][$i]['files'] ?? []);
        $newFiles  = handle_uploads_multi('doc_extra_files_'.$i, UP_SUB_NOTE_DOCS, $prevFiles);
        if (count($newFiles)>count($prevFiles)) {
          $note_docs_rows[] = ['titolo'=>'','tipo'=>'','descrizione'=>'','files'=>$newFiles];
        }
        continue;
      }
      $prevFiles = (array)($personale[$id]['note_docs'][$i]['files'] ?? []);
      $files = handle_uploads_multi('doc_extra_files_'.$i, UP_SUB_NOTE_DOCS, $prevFiles);
      $note_docs_rows[] = ['titolo'=>$titolo,'tipo'=>$tipo,'descrizione'=>$descr,'files'=>$files];
    }
  }
  if (!isset($personale[$id]['note_docs']) || !is_array($personale[$id]['note_docs'])) { $personale[$id]['note_docs'] = []; }
  $personale[$id]['note_docs'] = $note_docs_rows;

  if (!empty($_POST['del_doc_file_fronte'])) { maybe_unlink($personale[$id]['documento_identita']['file_fronte'] ?? ''); $personale[$id]['documento_identita']['file_fronte'] = ''; }
  if (!empty($_POST['del_doc_file_retro']))  { maybe_unlink($personale[$id]['documento_identita']['file_retro'] ?? '');  $personale[$id]['documento_identita']['file_retro']  = ''; }
  if (!empty($_POST['del_pc_file_fronte']))  { maybe_unlink($personale[$id]['patente_civile']['file_fronte'] ?? '');     $personale[$id]['patente_civile']['file_fronte']     = ''; }
  if (!empty($_POST['del_pc_file_retro']))   { maybe_unlink($personale[$id]['patente_civile']['file_retro'] ?? '');      $personale[$id]['patente_civile']['file_retro']      = ''; }
  if (!empty($_POST['del_pm_file_fronte']))  {
    maybe_unlink($personale[$id]['patente_ministeriale']['file_fronte'] ?? ($personale[$id]['patente_ministeriale_file'] ?? ''));
    $personale[$id]['patente_ministeriale']['file_fronte'] = '';
    $personale[$id]['patente_ministeriale_file'] = '';
  }
  if (!empty($_POST['del_pm_file_retro']))   { maybe_unlink($personale[$id]['patente_ministeriale']['file_retro'] ?? ''); $personale[$id]['patente_ministeriale']['file_retro'] = ''; }

  if (!empty($_POST['del_abil_file']) && is_array($_POST['del_abil_file'])) {
    foreach ($_POST['del_abil_file'] as $k => $flag) {
      if ($flag) { $prev = $personale[$id]['abilitazioni_dettaglio'][$k]['file'] ?? ''; maybe_unlink($prev); if (isset($personale[$id]['abilitazioni_dettaglio'][$k])) $personale[$id]['abilitazioni_dettaglio'][$k]['file'] = ''; }
    }
  }
  if (!empty($_POST['del_dpi_file']) && is_array($_POST['del_dpi_file'])) {
    foreach ($_POST['del_dpi_file'] as $k => $flag) {
      if ($flag) { $prev = $personale[$id]['dpi'][$k]['file'] ?? ''; maybe_unlink($prev); if (isset($personale[$id]['dpi'][$k])) $personale[$id]['dpi'][$k]['file'] = ''; }
    }
  }
  if (!empty($_POST['doc_extra_del']) && is_array($_POST['doc_extra_del'])) {
    foreach ($_POST['doc_extra_del'] as $i => $toDelList) {
      $toDel = array_filter((array)$toDelList, fn($x)=>is_string($x)&&$x!=='');
      if (!$toDel) continue;
      $files = (array)($personale[$id]['note_docs'][$i]['files'] ?? []);
      $keep  = [];
      foreach ($files as $rel) {
        if (in_array($rel, $toDel, true)) { maybe_unlink($rel); } else { $keep[] = $rel; }
      }
      if (isset($personale[$id]['note_docs'][$i])) $personale[$id]['note_docs'][$i]['files'] = $keep;
    }
  }

  try{
    $vigiliAll=load_vigili();
    $username_post=isset($_POST['username'])?strtolower(trim((string)$_POST['username'])):''; 
    foreach($vigiliAll as &$vv){
      if((int)($vv['id']??0)===$id){
        $vv['is_capo']=$is_capo_form?1:0;
        if($email!=='' && filter_var($email,FILTER_VALIDATE_EMAIL)) $vv['email']=$email;
        if($username_post!=='') $vv['username']=$username_post;
        break;
      }
    }
    unset($vv);
    save_vigili($vigiliAll);
  }catch(Throwable $e){ error_log('Sync su vigili.json fallito: '.$e->getMessage()); }

  try{
    save_personale($personale);
    header('Location: personale.php?msg=saved'.($slug!==''?'&slug='.urlencode($slug):'')); exit;
  }catch(Throwable $e){
    error_log('Salvataggio personale.json fallito: '.$e->getMessage());
    header('Location: personale.php?err=save'.($slug!==''?'&slug='.urlencode($slug):'')); exit;
  }
}

/* ===== UTILS VIEW ===== */
function pget($arr,$path,$def=''){ $cur=$arr; foreach(explode('.',$path) as $k){ if(!is_array($cur) || !array_key_exists($k,$cur)) return $def; $cur=$cur[$k]; } return is_scalar($cur)?(string)$cur:$def; }
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Gestione personale (dati extra)</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  .sipec-hl { border: 1px dashed #d39e00 !important; border-radius: .5rem; padding: .5rem .5rem .25rem .5rem; background: rgba(255, 243, 205, .45); }
  .sipec-badge { font-size:.75rem; }
  .sipec-readonly { background: #fff8e1 !important; }
  .legend-sipec small { font-size: .9rem; }
  /* === Larghezza maggiore per la colonna Ingresso === */
  th.col-ingresso, td.col-ingresso { min-width: 12.5rem; } /* ~200px: più largo e leggibile */
</style>
</head>
<body class="bg-light">

<?php
$_MENU_ACTIVE = 'personale';
$menuPath = __DIR__.'/_menu.php';
if (is_file($menuPath)) { include $menuPath; }
?>

<div class="container py-4">

  <div class="sticky-top bg-white border rounded-3 p-2 mb-3" style="top:.5rem; box-shadow:0 2px 8px rgba(0,0,0,.03);">
    <div class="d-flex flex-wrap align-items-center gap-2">
      <a class="btn btn-outline-secondary" href="vigili.php<?= $slug!==''?'?slug='.urlencode($slug):'' ?>">Nuovo vigile ⚙️</a>
      <a class="btn btn-outline-secondary" href="vigili_contatti.php<?= $slug!==''?'?slug='.urlencode($slug):'' ?>">Gestione Mail Vigili ⚙️</a>
      <span class="ms-auto fw-semibold">Gestione personale</span>
    </div>
  </div>

  <h1 class="mb-2">Gestione personale</h1>
  <p class="text-muted mb-1">Dati anagrafico-operativi e documentali. Modifica con “Modifica”.</p>
  <p class="legend-sipec text-warning-emphasis">
    <small>🔒 I campi <span class="badge text-bg-warning sipec-badge">SIPEC</span> sono bloccati: aggiornali solo con <strong>“Aggiorna da SIPEC”</strong>.</small>
  </p>

  <?php if ($err): ?>
    <div class="alert alert-danger">
      <?php echo match($err) {
        'param' => 'Parametro non valido.',
        'save'  => 'Errore nel salvataggio del file personale.json.',
        'sipec_param'    => 'Parametro non valido (SIPEC).',
        'sipec_notfound' => 'Vigile non trovato (SIPEC).',
        'sipec_down'     => 'SIPEC non raggiungibile.',
        'sipec_json'     => 'Risposta SIPEC non valida.',
        'sipec_no_match' => 'Nessuna corrispondenza in SIPEC per questo utente nella sede attiva.',
        default => 'Errore.'
      }; ?>
    </div>
  <?php endif; ?>

  <?php if ($msg==='saved'): ?>
    <div class="alert alert-success">Dati aggiornati correttamente.</div>
  <?php endif; ?>
  <?php if ($msg==='sipec_ok'): ?>
    <div class="alert alert-success">Aggiornato da SIPEC: ok.</div>
  <?php endif; ?>

  <div class="card shadow-sm">
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-sm align-middle">
          <thead>
            <tr>
              <th>#</th>
              <th>Vigile</th>
              <th>Anagrafica</th>
              <th>Contatti</th>
              <th>Indirizzo</th>
              <th class="col-ingresso">Ingresso</th>
              <th>Idoneità</th>
              <th>Doc.</th>
              <th class="text-end">Azioni</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($vigili as $i=>$v):
              $id   = (int)$v['id'];
              $p    = $personale[$id] ?? [];
              $nome_plain = trim(($v['cognome'] ?? '').' '.($v['nome'] ?? ''));
              $nome_html  = h($nome_plain);
              $grado_disp = $p['grado'] ?? ($v['grado'] ?? '');
              $grado      = h($grado_disp);

              $tel  = h(pget($p,'contatti.telefono'));
              $tel1 = h(pget($p,'contatti.telefono1'));
              $tel2 = h(pget($p,'contatti.telefono2'));
              $mail = h(pget($p,'contatti.email'));

              $via  = h(pget($p,'indirizzo.via'));
              $cap  = h(pget($p,'indirizzo.cap'));
              $com  = h(pget($p,'indirizzo.comune'));
              $ing  = h(pget($p,'ingresso'));
              $ido  = pget($p,'idoneita','operativo');
              $badge = $ido==='operativo' ? 'success' : ($ido==='sospeso' ? 'warning' : 'danger');

              $nasc = h((string)($p['data_nascita'] ?? ''));
              $grado_num = h((string)($p['grado_numero'] ?? ''));
              $inizio_qual = h((string)($p['data_inizio_qualifica'] ?? ''));

              $cf   = h((string)($p['cf'] ?? ''));
              $sede = h((string)($p['cod_sede'] ?? ''));
              $sedeAgg = h((string)($p['cod_sede_aggregato'] ?? ''));
              $sedeAggNome = h((string)($p['cod_sede_aggregato_nome'] ?? ''));

              // ====== ICON LINKS (FIX percorsi) ======
              $docI = $p['documento_identita'] ?? [];
              $doc_front = (string)($docI['file_fronte'] ?? ($docI['file'] ?? ''));
              $docI_link = $doc_front !== '' ? '<a href="'.h($doc_front).'" target="_blank" title="Documento identità (fronte)">🪪</a>' : '';

              $pc   = $p['patente_civile'] ?? [];
              $pc_front = (string)($pc['file_fronte'] ?? ($pc['file'] ?? ''));
              $pc_link = $pc_front !== '' ? '<a href="'.h($pc_front).'" target="_blank" title="Patente civile (fronte)">🚗</a>' : '';

              $pm_front = (string)(($p['patente_ministeriale']['file_fronte'] ?? '') ?: ($p['patente_ministeriale_file'] ?? ''));
              $pm_link  = $pm_front !== '' ? '<a href="'.h($pm_front).'" target="_blank" title="Patente ministeriale (fronte)">🚒</a>' : '';

              $abil_det  = (array)($p['abilitazioni_dettaglio'] ?? []);
              $abil_link = count($abil_det)>0 ? '<span title="Abilitazioni con file">🎓×'.count($abil_det).'</span>' : '';

              $dpi_list  = (array)($p['dpi'] ?? []);
              $dpi_badge = count($dpi_list)>0 ? '<span title="DPI/vestiario">🧰×'.count($dpi_list).'</span>' : '';

              $isCapoBadge = !empty($v['is_capo']) ? ' <span class="badge text-bg-warning">Capo</span>' : '';
            ?>
            <tr>
              <td><?= $i+1 ?></td>
              <td>
                <?= $nome_html ?><?= $isCapoBadge ?>
                <?= $grado ? '<small class="text-muted"> ['.$grado.']</small>' : '' ?>
              </td>
              <td class="small">
                <?php if ($nasc): ?>🎂 <?= $nasc ?><br><?php endif; ?>
                <?php if ($grado_num): ?># <?= $grado_num ?><br><?php endif; ?>
                <?php if ($inizio_qual): ?>Qualifica dal: <?= $inizio_qual ?><br><?php endif; ?>
                <?php if ($cf): ?>CF: <?= $cf ?><br><?php endif; ?>
                <?php if ($sede): ?>Sede: <?= $sede ?><br><?php endif; ?>
                <?php if ($sedeAgg): ?>Aggregato: <?= $sedeAggNome ? ($sedeAggNome.' ('.$sedeAgg.')') : $sedeAgg ?><?php endif; ?>
              </td>
              <td class="small">
                <?php if ($tel): ?>📞 <?= $tel ?><br><?php endif; ?>
                <?php if ($tel1 && $tel1!==$tel): ?>📞1 <?= $tel1 ?><br><?php endif; ?>
                <?php if ($tel2 && $tel2!==$tel && $tel2!==$tel1): ?>📞2 <?= $tel2 ?><br><?php endif; ?>
                <?php if ($mail): ?>✉️ <?= $mail ?><?php endif; ?>
              </td>
              <td class="small">
                <?= $via ?>
                <?= ($cap||$com) ? '<br>'.($cap? $cap.' ' : '').$com : '' ?>
              </td>
              <td class="col-ingresso"><span class="small"><?= $ing ?: '—' ?></span></td>
              <td><span class="badge text-bg-<?= $badge ?>"><?= h(strtoupper(str_replace('_',' ',$ido))) ?></span></td>
              <td class="small">
                <?= $docI_link ?> <?= $pc_link ?> <?= $pm_link ?> <?= $abil_link ?> <?= $dpi_badge ?>
              </td>
              <td class="text-end">
                <button
                  type="button"
                  class="btn btn-sm btn-outline-primary btn-edit"
                  data-id="<?= $id ?>"
                  data-nome="<?= h($nome_plain) ?>"
                  data-bs-toggle="modal" data-bs-target="#modalEdit">
                  Modifica
                </button>

                <form method="post" class="d-inline" onsubmit="return confirm('Aggiornare i dati di <?= h($nome_plain) ?> da SIPEC?');">
                  <input type="hidden" name="action" value="sipec_pull">
                  <input type="hidden" name="vigile_id" value="<?= $id ?>">
                  <button class="btn btn-sm btn-outline-warning">Aggiorna da SIPEC</button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($vigili)): ?>
              <tr><td colspan="10" class="text-muted">Nessun vigile presente.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
      <div class="text-muted small">Suggerimento: le icone in “Doc.” aprono i file <em>fronte</em>; c’è il fallback ai vecchi campi singoli.</div>
    </div>
  </div>
</div>

<!-- MODALE EDIT (immutata salvo fix minori) -->
<div class="modal fade" id="modalEdit" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl">
    <form method="post" class="modal-content" enctype="multipart/form-data">
      <input type="hidden" name="vigile_id" id="f_id">
      <div class="modal-header">
        <h5 class="modal-title">Modifica dati personale — <span id="f_nome"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
      </div>

      <div class="modal-body">
        <ul class="nav nav-tabs" role="tablist">
          <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-anag" type="button" role="tab">Anagrafica</button></li>
          <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-doc" type="button" role="tab">Documenti</button></li>
          <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-dpi" type="button" role="tab">Vestiario / DPI</button></li>
          <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-note" type="button" role="tab">Note</button></li>
        </ul>

        <div class="tab-content pt-3">
          <!-- ANAGRAFICA -->
          <div class="tab-pane fade show active" id="tab-anag" role="tabpanel">
            <div class="row g-3">
              <div class="col-md-4 sipec-hl">
                <label class="form-label">Telefono <span class="badge text-bg-warning sipec-badge">SIPEC</span></label>
                <input type="text" class="form-control sipec-readonly" name="telefono" id="f_tel" readonly>
                <div class="form-text" id="f_tel_extra"></div>
              </div>
              <div class="col-md-4">
                <label class="form-label">Email</label>
                <input type="email" class="form-control" name="email" id="f_mail">
              </div>
              <div class="col-md-4">
                <label class="form-label">Data di nascita</label>
                <input type="date" class="form-control" name="data_nascita" id="f_nascita">
              </div>

              <div class="col-md-8 sipec-hl">
                <label class="form-label">Indirizzo (via) <span class="badge text-bg-warning sipec-badge">SIPEC</span></label>
                <input type="text" class="form-control sipec-readonly" name="via" id="f_via" readonly>
              </div>
              <div class="col-md-2 sipec-hl">
                <label class="form-label">CAP <span class="badge text-bg-warning sipec-badge">SIPEC</span></label>
                <input type="text" class="form-control sipec-readonly" name="cap" id="f_cap" readonly>
              </div>
              <div class="col-md-2 sipec-hl">
                <label class="form-label">Comune <span class="badge text-bg-warning sipec-badge">SIPEC</span></label>
                <input type="text" class="form-control sipec-readonly" name="comune" id="f_comune" readonly>
              </div>

              <div class="col-md-4 sipec-hl">
                <label class="form-label">Data Decreto <span class="badge text-bg-warning sipec-badge">SIPEC</span></label>
                <input type="date" class="form-control sipec-readonly" name="ingresso" id="f_ingresso" readonly>
              </div>
              <div class="col-md-4">
                <label class="form-label">Data uscita (se cessato)</label>
                <input type="date" class="form-control" name="uscita" id="f_uscita">
              </div>
              <div class="col-md-4">
                <label class="form-label">Idoneità</label>
                <select class="form-select" name="idoneita" id="f_idoneita">
                  <option value="operativo">Operativo</option>
                  <option value="sospeso">Sospeso</option>
                  <option value="non_operativo">Non Operativo</option>
                </select>
              </div>

              <div class="col-md-4 sipec-hl">
                <label class="form-label">Grado <span class="badge text-bg-warning sipec-badge">SIPEC</span></label>
                <select class="form-select sipec-readonly" name="grado" id="f_grado" disabled>
                  <?php foreach ($GRADO_OPTIONS as $opt): ?>
                    <option value="<?= h($opt) ?>"><?= $opt === '' ? '—' : h($opt) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-4">
                <label class="form-label">N° tesserino</label>
                <input type="text" class="form-control" name="grado_numero" id="f_grado_numero">
              </div>
              <div class="col-md-4 sipec-hl">
                <label class="form-label">Data inizio qualifica <span class="badge text-bg-warning sipec-badge">SIPEC</span></label>
                <input type="date" class="form-control sipec-readonly" name="data_inizio_qualifica" id="f_inizio_qualifica" readonly>
              </div>

              <div class="col-md-6 sipec-hl">
                <label class="form-label">Data ultima visita medica <span class="badge text-bg-warning sipec-badge">SIPEC</span></label>
                <input type="date" class="form-control sipec-readonly" name="ultima_visita_medica" id="f_scad_ultima" readonly>
              </div>

              <div class="col-md-6 sipec-hl">
                <label class="form-label">Scadenza visita medica <span class="badge text-bg-warning sipec-badge">SIPEC</span></label>
                <input type="date" class="form-control sipec-readonly" name="scadenza_visita" id="f_scad_visita" readonly>
              </div>
              <div class="col-md-6 sipec-hl">
                <label class="form-label">Scadenza patente ministeriale <span class="badge text-bg-warning sipec-badge">SIPEC</span></label>
                <input type="date" class="form-control sipec-readonly" name="scadenza_patente_b" id="f_scad_pb" readonly>
              </div>

              <div class="col-md-6 sipec-hl">
                <label class="form-label">Aggregazione <span class="badge text-bg-warning sipec-badge">SIPEC</span></label>
                <div class="input-group">
                  <span class="input-group-text">Cod</span>
                  <input type="text" class="form-control sipec-readonly" id="f_cod_sede_aggregato" value="" readonly>
                  <span class="input-group-text">Nome</span>
                  <input type="text" class="form-control sipec-readonly" id="f_cod_sede_aggregato_nome" value="" readonly>
                </div>
                <div class="form-text">Sede di aggregazione rilevata da SIPEC.</div>
              </div>

              <div class="col-md-4">
                <div class="form-check mt-4">
                  <input class="form-check-input" type="checkbox" id="f_is_capo" name="is_capo">
                  <label class="form-check-label" for="f_is_capo">Capo distaccamento</label>
                </div>
              </div>
            </div>
          </div>

          <!-- DOCUMENTI -->
          <div class="tab-pane fade" id="tab-doc" role="tabpanel">
            <div class="row g-3">
              <div class="col-12"><h6 class="mb-0">Documento d’identità</h6><hr class="mt-2"></div>
              <div class="col-md-3">
                <label class="form-label">Tipo</label>
                <input type="text" class="form-control" name="doc_tipo" id="f_doc_tipo" placeholder="C.I., Passaporto">
              </div>
              <div class="col-md-3">
                <label class="form-label">Numero</label>
                <input type="text" class="form-control" name="doc_num" id="f_doc_num">
              </div>
              <div class="col-md-3">
                <label class="form-label">Scadenza</label>
                <input type="date" class="form-control" name="doc_scad" id="f_doc_scad">
              </div>
              <div class="col-md-3">
                <label class="form-label">Foto fronte (JPG/PNG/PDF)</label>
                <input type="file" class="form-control" name="doc_file_fronte" accept="image/*,.pdf">
                <div class="form-text" id="f_doc_link_fronte"></div>
              </div>
              <div class="col-md-3">
                <label class="form-label">Foto retro (JPG/PNG/PDF)</label>
                <input type="file" class="form-control" name="doc_file_retro" accept="image/*,.pdf">
                <div class="form-text" id="f_doc_link_retro"></div>
              </div>

              <div class="col-12"><h6 class="mb-0 mt-3">Patente civile</h6><hr class="mt-2"></div>
              <div class="col-md-4">
                <label class="form-label">Numero</label>
                <input type="text" class="form-control" name="patente_civile_num" id="f_pc_num">
              </div>
              <div class="col-md-4">
                <label class="form-label">Scadenza</label>
                <input type="date" class="form-control" name="patente_civile_scad" id="f_pc_scad">
              </div>
              <div class="col-md-4">
                <label class="form-label">Foto fronte (JPG/PNG/PDF)</label>
                <input type="file" class="form-control" name="pat_civ_file_fronte" accept="image/*,.pdf">
                <div class="form-text" id="f_pc_link_fronte"></div>
              </div>
              <div class="col-md-4">
                <label class="form-label">Foto retro (JPG/PNG/PDF)</label>
                <input type="file" class="form-control" name="pat_civ_file_retro" accept="image/*,.pdf">
                <div class="form-text" id="f_pc_link_retro"></div>
              </div>
              <div class="col-12">
                <label class="form-label d-block">Categorie (multi-selezione)</label>
                <div class="d-flex flex-wrap gap-3" id="pc_categorie_box">
                 <?php foreach ((array)$CIVIL_CATS as $cat): ?>
                    <label class="form-check form-check-inline m-0">
                      <input class="form-check-input" type="checkbox" name="patente_civile_cat[]" value="<?= h($cat) ?>">
                      <span class="form-check-label"><?= h($cat) ?></span>
                    </label>
                  <?php endforeach; ?>
                </div>
              </div>

              <div class="col-12"><h6 class="mb-0 mt-3">Patente ministeriale</h6><hr class="mt-2"></div>
              <div class="col-md-6 sipec-hl">
                <label class="form-label">Gradi abilitati (multi-selezione) <span class="badge text-bg-warning sipec-badge">SIPEC</span></label>
                <div class="d-flex flex-wrap gap-3" id="patenti_box">
                <?php foreach ((array)$PAT_MIN_OPTIONS as $lbl): ?>
                    <label class="form-check form-check-inline m-0">
                      <input class="form-check-input pat-min sipec-readonly" type="checkbox" name="patenti_sel[]" value="<?= h($lbl) ?>" disabled>
                      <span class="form-check-label"><?= h($lbl) ?></span>
                    </label>
                  <?php endforeach; ?>
                </div>
                <div class="form-text">Selezione bloccata: deriva da AM1/AM2/AM3 in SIPEC.</div>
              </div>
              <div class="col-md-6">
                <label class="form-label d-block">File patente ministeriale</label>
                <div class="row g-2">
                  <div class="col-md-6">
                    <label class="form-label">Foto fronte (JPG/PNG/PDF)</label>
                    <input type="file" class="form-control" name="pat_min_file_fronte" accept="image/*,.pdf">
                    <div class="form-text" id="f_pm_link_fronte"></div>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label">Foto retro (JPG/PNG/PDF)</label>
                    <input type="file" class="form-control" name="pat_min_file_retro" accept="image/*,.pdf">
                    <div class="form-text" id="f_pm_link_retro"></div>
                  </div>
                </div>
              </div>

              <div class="col-12"><h6 class="mb-0 mt-3">Abilitazioni ministeriali</h6><hr class="mt-2"></div>
              <div class="col-12">
                <label class="form-label">Lista (separate da virgola)</label>
                <input type="text" class="form-control" name="abilitazioni" id="f_abilitazioni" placeholder="APS, AS, NBCR, ...">
              </div>
              <div class="col-12">
                <div id="abil_rows" class="vstack gap-2 mt-2"></div>
                <button class="btn btn-sm btn-outline-primary mt-1" type="button" id="btnAddAbil">+ Aggiungi abilitazione (dettaglio)</button>
              </div>
            </div>
          </div>

          <!-- DPI -->
          <div class="tab-pane fade" id="tab-dpi" role="tabpanel">
            <div id="dpi_rows" class="vstack gap-2"></div>
            <button class="btn btn-sm btn-outline-primary mt-1" type="button" id="btnAddDpi">+ Aggiungi DPI/Vestiario</button>

            <hr class="my-3">

            <div class="d-flex align-items-center justify-content-between">
              <div class="h6 mb-0">Richieste a TRACK</div>
              <button type="button" class="btn btn-sm btn-outline-secondary" id="btnTrackPrint">Stampa riepilogo (PDF)</button>
            </div>
            <div class="text-muted small mt-1">Riportare di seguito il numero totale dei capi di vestiario e DPI per i quali sono presenti le relative richieste sul portale TRACK.</div>
            <div class="text-muted small mb-3">(non inserire altre richieste se non risultano registrate sul portale TRACK)</div>
            <div id="trackCard" class="border rounded p-3 bg-light-subtle"
                 data-nominativo=""
                 data-grado=""
                 data-data="<?= h(date('d/m/Y')) ?>">
              <div class="mb-2 small">
                <div><strong>Nominativo:</strong> <span data-track-nome>---</span></div>
                <div><strong>Grado:</strong> <span data-track-grado>---</span></div>
                <div><strong>Data compilazione:</strong> <span data-track-data><?= h(date('d/m/Y')) ?></span></div>
              </div>
              <ul class="mb-0">
                <?php foreach ((array)$TRACK_ITEMS as $k=>$lbl): ?>
                  <li class="mb-3">
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                      <span><?= h($lbl) ?></span>
                      <div class="d-flex align-items-center gap-3">
                        <label class="form-check form-check-inline m-0">
                          <input class="form-check-input" type="radio" name="track_req[<?= h($k) ?>]" value="si">
                          <span class="form-check-label">Richiesto</span>
                        </label>
                        <label class="form-check form-check-inline m-0">
                          <input class="form-check-input" type="radio" name="track_req[<?= h($k) ?>]" value="no" checked>
                          <span class="form-check-label">Non richiesto</span>
                        </label>
                      </div>
                    </div>
                  </li>
                <?php endforeach; ?>
              </ul>
            </div>
          </div>

          <!-- NOTE -->
          <div class="tab-pane fade" id="tab-note" role="tabpanel">
            <div class="mb-3">
              <label class="form-label">Note</label>
              <textarea class="form-control" rows="4" name="note" id="f_note"></textarea>
            </div>

            <div class="d-flex align-items-center justify-content-between">
              <h6 class="mb-0">Documenti personalizzati</h6>
              <button class="btn btn-sm btn-outline-primary" type="button" id="btnAddDocExtra">+ Aggiungi documento</button>
            </div>
            <hr class="mt-2">
            <div id="doc_extra_rows" class="vstack gap-2"></div>
          </div>

        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annulla</button>
        <button class="btn btn-primary">Salva dati</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal Anteprima file -->
<div class="modal fade" id="modalPreview" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h6 class="modal-title" id="previewTitle">Anteprima</h6>
        <a id="previewDownload" class="btn btn-sm btn-outline-primary ms-2" href="#" download>⬇ Scarica</a>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
      </div>
      <div class="modal-body">
        <div id="previewImgWrap" class="text-center d-none">
          <img id="previewImg" src="" alt="preview" class="img-fluid rounded shadow-sm">
        </div>
        <div id="previewEmbedWrap" class="ratio ratio-16x9 d-none">
          <iframe id="previewEmbed" src="" title="document preview"></iframe>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
// ===== DUMP DATI =====
window.PERSONALE = <?php
  $lite=[];
  foreach ($personale as $pid=>$p){
    $lite[(string)$pid]=[
      'contatti'=>[
        'telefono'=>$p['contatti']['telefono']??'',
        'telefono1'=>$p['contatti']['telefono1']??'',
        'telefono2'=>$p['contatti']['telefono2']??'',
        'email'=>$p['contatti']['email']??'',
      ],
      'indirizzo'=>[
        'via'=>$p['indirizzo']['via']??'',
        'cap'=>$p['indirizzo']['cap']??'',
        'comune'=>$p['indirizzo']['comune']??'',
      ],
      'ingresso'=>$p['ingresso']??'',
      'uscita'=>$p['uscita']??'',
      'idoneita'=>$p['idoneita']??'operativo',
      'data_nascita'=>$p['data_nascita']??'',
      'grado'=>$p['grado']??'',
      'grado_numero'=>$p['grado_numero']??'',
      'data_inizio_qualifica'=>$p['data_inizio_qualifica']??'',
      'scadenze'=>[
        'visita_medica'=>$p['scadenze']['visita_medica']??'',
        'visita_medica_ultima'=>$p['scadenze']['visita_medica_ultima']??'',
        'visita_medica_data'=>$p['scadenze']['visita_medica_data']??'',
        'patente_b'=>$p['scadenze']['patente_b']??'',
      ],
      'patenti'=>$p['patenti']??[],
      'abilitazioni'=>$p['abilitazioni']??[],
      'documento_identita'=>[
        'tipo'=>$p['documento_identita']['tipo']??'',
        'numero'=>$p['documento_identita']['numero']??'',
        'scadenza'=>$p['documento_identita']['scadenza']??'',
        'file'=>$p['documento_identita']['file']??'',
        'file_fronte'=>$p['documento_identita']['file_fronte']??'',
        'file_retro'=>$p['documento_identita']['file_retro']??'',
      ],
      'patente_civile'=>[
        'numero'=>$p['patente_civile']['numero']??'',
        'scadenza'=>$p['patente_civile']['scadenza']??'',
        'categorie'=> isset($p['patente_civile']['categorie']) ? (array)$p['patente_civile']['categorie'] : [],
        'file'=>$p['patente_civile']['file']??'',
        'file_fronte'=>$p['patente_civile']['file_fronte']??'',
        'file_retro'=>$p['patente_civile']['file_retro']??'',
      ],
      'patente_ministeriale_file'=>$p['patente_ministeriale_file']??'',
      'patente_ministeriale'=>[
        'file_fronte'=>$p['patente_ministeriale']['file_fronte']??'',
        'file_retro'=>$p['patente_ministeriale']['file_retro']??'',
      ],
      'abilitazioni_dettaglio'=>$p['abilitazioni_dettaglio']??[],
      'dpi'=>$p['dpi']??[],
      'note'=>$p['note']??'',
      'note_docs'=>$p['note_docs']??[],
      'track_richieste'=>$p['track_richieste']??[],
      'cf'=>$p['cf']??'',
      'cod_sede'=>$p['cod_sede']??'',
      'cod_sede_aggregato'=>$p['cod_sede_aggregato']??'',
      'cod_sede_aggregato_nome'=>$p['cod_sede_aggregato_nome']??'',
    ];
  }
  echo json_encode($lite, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
?>;

window.VIGILI_IS_CAPO = <?php
  $mapCapo=[];
  foreach($vigili as $vv){ $mapCapo[(string)((int)$vv['id'])]=!empty($vv['is_capo'])?1:0; }
  echo json_encode($mapCapo, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_FORCE_OBJECT);
?>;

function escAttr(x){return String(x||'').replace(/"/g,'&quot;');}
function abilRowTpl(i,r){r=r||{};
return ''+
'<div class="border rounded p-2 position-relative">'+
'  <div class="row g-2">'+
'    <div class="col-md-4"><label class="form-label">Abilitazione</label><input class="form-control" name="abil_rows['+i+'][nome]" value="'+escAttr(r.nome)+'"></div>'+
'    <div class="col-md-3"><label class="form-label">Numero</label><input class="form-control" name="abil_rows['+i+'][numero]" value="'+escAttr(r.numero)+'"></div>'+
'    <div class="col-md-3"><label class="form-label">Scadenza</label><input type="date" class="form-control" name="abil_rows['+i+'][scadenza]" value="'+(r.scadenza||'')+'"></div>'+
'    <div class="col-md-2"><label class="form-label">File</label><input type="file" class="form-control" name="abil_file_'+i+'" accept=".pdf,image/*"><div class="form-text"></div></div>'+
'  </div>'+
'</div>'; }
function dpiRowTpl(i,r){r=r||{};
return ''+
'<div class="border rounded p-2 position-relative">'+
'  <div class="row g-2">'+
'    <div class="col-md-4"><label class="form-label">Articolo</label><input class="form-control" name="dpi_rows['+i+'][item]" value="'+escAttr(r.item)+'"></div>'+
'    <div class="col-md-2"><label class="form-label">Taglia</label><input class="form-control" name="dpi_rows['+i+'][taglia]" value="'+escAttr(r.taglia)+'"></div>'+
'    <div class="col-md-2"><label class="form-label">Assegnato il</label><input type="date" class="form-control" name="dpi_rows['+i+'][assegnato]" value="'+(r.assegnato||'')+'"></div>'+
'    <div class="col-md-2"><label class="form-label">Scadenza</label><input type="date" class="form-control" name="dpi_rows['+i+'][scadenza]" value="'+(r.scadenza||'')+'"></div>'+
'    <div class="col-md-1"><label class="form-label">Q.tà</label><input type="number" min="1" class="form-control" name="dpi_rows['+i+'][quantita]" value="'+(r.quantita||1)+'"></div>'+
'    <div class="col-md-3"><label class="form-label">File</label><input type="file" class="form-control" name="dpi_file_'+i+'" accept=".pdf,image/*"><div class="form-text"></div></div>'+
'    <div class="col-12"><label class="form-label">Note</label><input class="form-control" name="dpi_rows['+i+'][note]" value="'+escAttr(r.note)+'"></div>'+
'  </div>'+
'</div>'; }
function docExtraRowTpl(i,r){ r=r||{};
  var titolo=escAttr(r.titolo||''), tipo=escAttr(r.tipo||''), descr=escAttr(r.descrizione||'');
  return ''+
'<div class="border rounded p-2">'+
'  <div class="row g-2">'+
'    <div class="col-md-4"><label class="form-label">Titolo</label><input class="form-control" name="doc_extra_rows['+i+'][titolo]" value="'+titolo+'"></div>'+
'    <div class="col-md-3"><label class="form-label">Tipo documento</label><input class="form-control" name="doc_extra_rows['+i+'][tipo]" value="'+tipo+'" placeholder="Es. Verbale, Foto sopralluogo"></div>'+
'    <div class="col-md-3"><label class="form-label">Allegati (multi)</label><input class="form-control" type="file" name="doc_extra_files_'+i+'[]" accept=".pdf,image/*" multiple></div>'+
'    <div class="col-md-2 d-flex align-items-end"><button type="button" class="btn btn-outline-danger w-100 btn-docextra-delrow" data-rowindex="'+i+'">Rimuovi riga</button></div>'+
'    <div class="col-12"><label class="form-label">Descrizione</label><textarea class="form-control" name="doc_extra_rows['+i+'][descrizione]" rows="2">'+descr+'</textarea></div>'+
'  </div>'+
'</div>';
}

function isImageUrl(url){ return /\.(png|jpe?g|gif|webp|bmp|svg)$/i.test(url||''); }
function openPreview(url, title){
  var isImg = isImageUrl(url);
  var m = document.getElementById('modalPreview');
  document.getElementById('previewTitle').textContent = title || 'Anteprima';
  var a = document.getElementById('previewDownload'); a.href = url; a.setAttribute('download','');
  var iw = document.getElementById('previewImgWrap'), ie = document.getElementById('previewEmbedWrap');
  var img = document.getElementById('previewImg'), emb = document.getElementById('previewEmbed');
  if(isImg){ iw.classList.remove('d-none'); ie.classList.add('d-none'); img.src = url; }
  else { iw.classList.add('d-none'); ie.classList.remove('d-none'); emb.src = url; }
  bootstrap.Modal.getOrCreateInstance(m).show();
}

function singleFileWidget(url, delName){
  if(!url) return '';
  var title = url.split('/').pop();
  var buttons =
    '<div class="d-flex align-items-center gap-2 mt-1">'+
      '<button class="btn btn-sm btn-outline-secondary" type="button" data-preview="'+url+'" data-title="'+title+'">👁 Apri</button>'+
      '<a class="btn btn-sm btn-outline-primary" href="'+url+'" download>⬇ Scarica</a>'+
      (delName?'<button class="btn btn-sm btn-outline-danger" type="button" data-del-target="'+delName+'">🗑 Rimuovi</button>':'')+
    '</div>';
  var thumb = isImageUrl(url) ? ('<img src="'+url+'" class="img-thumbnail mt-1" style="max-height:90px">') : ('<a href="'+url+'" target="_blank">'+title+'</a>');
  return '<div class="file-widget" data-url="'+url+'">'+thumb+buttons+'</div>';
}
function multiFileWidget(urls, delArrayFieldName){
  urls = Array.isArray(urls) ? urls : [];
  if(!urls.length) return '';
  return urls.map(function(u){
    var title = u.split('/').pop();
    var delName = delArrayFieldName + '[]';
    var buttons =
      '<div class="d-flex align-items-center gap-2 mt-1">'+
        '<button class="btn btn-sm btn-outline-secondary" type="button" data-preview="'+u+'" data-title="'+title+'">👁 Apri</button>'+
        '<a class="btn btn-sm btn-outline-primary" href="'+u+'" download>⬇ Scarica</a>'+
        '<button class="btn btn-sm btn-outline-danger" type="button" data-del-array="'+delName+'" data-del-url="'+u+'">🗑 Rimuovi</button>'+
      '</div>';
    var thumb = isImageUrl(u) ? ('<img src="'+u+'" class="img-thumbnail mt-1" style="max-height:90px">') : ('<a href="'+u+'" target="_blank">'+title+'</a>');
    return '<div class="file-widget" data-url="'+u+'">'+thumb+buttons+'</div>';
  }).join('');
}

function addMonthsYYYYMMDD(s,m){if(!s)return'';var a=s.split('-');if(a.length!==3)return'';var y=+a[0],mo=(+a[1])-1,d=+a[2];if(isNaN(y)||isNaN(mo)||isNaN(d))return'';var dt=new Date(y,mo,d);dt.setMonth(dt.getMonth()+m);var y2=dt.getFullYear(),m2=String(dt.getMonth()+1).padStart(2,'0'),d2=String(dt.getDate()).padStart(2,'0');return y2+'-'+m2+'-'+d2;}

/* SIPEC lock */
function lockSipecInput(id){ var el=document.getElementById(id); if(!el) return; el.classList.add('sipec-readonly'); el.setAttribute('readonly','readonly'); }
function lockSipecSelect(id){ var el=document.getElementById(id); if(!el) return; el.classList.add('sipec-readonly'); el.setAttribute('disabled','disabled'); }
function lockSipecCheckboxes(name){ document.querySelectorAll('input[name="'+name+'"]').forEach(function(cb){ cb.setAttribute('disabled','disabled'); cb.classList.add('sipec-readonly'); }); }

function initPersonaleUI(){
  document.addEventListener('click', function(ev){
    var btn = ev.target.closest ? ev.target.closest('.btn-edit') : null;
    if (!btn) return;
    var id   = btn.getAttribute('data-id') || '';
    var nome = btn.getAttribute('data-nome') || '';
    var f_id=document.getElementById('f_id'); if(f_id) f_id.value=id;
    var f_nome=document.getElementById('f_nome'); if(f_nome) f_nome.textContent=nome;
    var p=(window.PERSONALE&&window.PERSONALE[id])?window.PERSONALE[id]:{};
    function setVal(i,v){var el=document.getElementById(i); if(el) el.value=v||'';}
    function setSelectValue(i,v){var el=document.getElementById(i); if(!el)return; for(var j=0;j<el.options.length;j++){ if(el.options[j].value===String(v||'')){ el.selectedIndex=j; break; } } }

    setVal('f_tel', p.contatti && p.contatti.telefono);
    setVal('f_mail',p.contatti && p.contatti.email);
    var extra=[]; if(p.contatti&&p.contatti.telefono1&&p.contatti.telefono1!==p.contatti.telefono) extra.push('📞1 '+p.contatti.telefono1);
    if(p.contatti&&p.contatti.telefono2&&p.contatti.telefono2!==p.contatti.telefono&&p.contatti.telefono2!==p.contatti.telefono1) extra.push('📞2 '+p.contatti.telefono2);
    var telExtra=document.getElementById('f_tel_extra'); if(telExtra) telExtra.innerHTML=extra.join('<br>');

    setVal('f_via',p.indirizzo && p.indirizzo.via);
    setVal('f_cap',p.indirizzo && p.indirizzo.cap);
    setVal('f_comune',p.indirizzo && p.indirizzo.comune);
    setVal('f_ingresso',p.ingresso);
    setVal('f_uscita',p.uscita);
    setVal('f_idoneita',p.idoneita||'operativo');
    setVal('f_note', p.note);
    setVal('f_nascita',p.data_nascita);
    setSelectValue('f_grado',p.grado);
    setVal('f_grado_numero',p.grado_numero);
    setVal('f_inizio_qualifica',p.data_inizio_qualifica);

    // sincronizza stato "Capo distaccamento" dalla mappa PHP -> JS
(function(){
  var fcap = document.getElementById('f_is_capo');
  if (!fcap) return;
  var isCapo = false;
  if (window.VIGILI_IS_CAPO && Object.prototype.hasOwnProperty.call(window.VIGILI_IS_CAPO, String(id))) {
    isCapo = !!window.VIGILI_IS_CAPO[String(id)];
  }
  fcap.checked = isCapo;
})();

    var fsv=document.getElementById('f_scad_visita');
    var fsb=document.getElementById('f_scad_pb');
    var ful=document.getElementById('f_scad_ultima');
    var ultima=(p.scadenze && (p.scadenze.visita_medica_ultima || p.scadenze.visita_medica_data)) || '';
    if(ful) ful.value=ultima;
    if(ful && fsv){ fsv.value=(p.scadenze && p.scadenze.visita_medica)||''; }
    if(fsb){ fsb.value=(p.scadenze && p.scadenze.patente_b)||''; }

    lockSipecInput('f_tel');
    lockSipecInput('f_via'); lockSipecInput('f_cap'); lockSipecInput('f_comune');
    lockSipecInput('f_ingresso');
    lockSipecSelect('f_grado');
    lockSipecInput('f_inizio_qualifica');
    lockSipecInput('f_scad_ultima'); lockSipecInput('f_scad_visita'); lockSipecInput('f_scad_pb');
    lockSipecCheckboxes('patenti_sel[]');

    setVal('f_cod_sede_aggregato', p.cod_sede_aggregato);
    setVal('f_cod_sede_aggregato_nome', p.cod_sede_aggregato_nome);

    var di=p.documento_identita||{};
    var docFrontWrap=document.getElementById('f_doc_link_fronte'); 
    if(docFrontWrap){ docFrontWrap.innerHTML = singleFileWidget(di.file_fronte||di.file||'', 'del_doc_file_fronte'); }
    var docBackWrap=document.getElementById('f_doc_link_retro'); 
    if(docBackWrap){ docBackWrap.innerHTML  = singleFileWidget(di.file_retro||'', 'del_doc_file_retro'); }

    var pc=p.patente_civile||{};
    setVal('f_pc_num',pc.numero); setVal('f_pc_scad',pc.scadenza);
    document.querySelectorAll('input[name="patente_civile_cat[]"]').forEach(cb=>{ cb.checked=Array.isArray(pc.categorie)?pc.categorie.includes(cb.value):false; });
    var pcFrontWrap=document.getElementById('f_pc_link_fronte'); 
    if(pcFrontWrap){ pcFrontWrap.innerHTML = singleFileWidget(pc.file_fronte||pc.file||'', 'del_pc_file_fronte'); }
    var pcBackWrap=document.getElementById('f_pc_link_retro'); 
    if(pcBackWrap){ pcBackWrap.innerHTML  = singleFileWidget(pc.file_retro||'', 'del_pc_file_retro'); }

    var pmFrontWrap=document.getElementById('f_pm_link_fronte');
    if(pmFrontWrap){ 
      var pmFrontUrl=(p.patente_ministeriale && p.patente_ministeriale.file_fronte) || p.patente_ministeriale_file || '';
      pmFrontWrap.innerHTML = singleFileWidget(pmFrontUrl, 'del_pm_file_fronte'); 
    }
    var pmBackWrap=document.getElementById('f_pm_link_retro');
    if(pmBackWrap){ 
      var pmBackUrl=(p.patente_ministeriale && p.patente_ministeriale.file_retro) || '';
      pmBackWrap.innerHTML = singleFileWidget(pmBackUrl, 'del_pm_file_retro'); 
    }

    var abilWrap=document.getElementById('abil_rows');
    if(abilWrap){
      abilWrap.innerHTML='';
      var abil=Array.isArray(p.abilitazioni_dettaglio)?p.abilitazioni_dettaglio:[];
      for(var i=0;i<abil.length;i++){ abilWrap.insertAdjacentHTML('beforeend', abilRowTpl(i,abil[i])); }
      if(abil.length===0) abilWrap.insertAdjacentHTML('beforeend', abilRowTpl(0));
      var rows = abilWrap.querySelectorAll('.border.rounded');
      rows.forEach(function(row, i){
        var fw = row.querySelector('.form-text');
        if(!fw) return;
        var url = (abil[i] && abil[i].file) ? abil[i].file : '';
        if(url){ fw.innerHTML = singleFileWidget(url, 'del_abil_file['+i+']'); }
      });
    }

    var dpiWrap=document.getElementById('dpi_rows');
    if(dpiWrap){
      dpiWrap.innerHTML='';
      var dpis=Array.isArray(p.dpi)?p.dpi:[];
      for(var j=0;j<dpis.length;j++){ dpiWrap.insertAdjacentHTML('beforeend', dpiRowTpl(j,dpis[j])); }
      if(dpis.length===0) dpiWrap.insertAdjacentHTML('beforeend', dpiRowTpl(0));
      var rows = dpiWrap.querySelectorAll('.border.rounded');
      rows.forEach(function(row, i){
        var fw = row.querySelector('.form-text');
        if(!fw) return;
        var url = (dpis[i] && dpis[i].file) ? dpis[i].file : '';
        if(url){ fw.innerHTML = singleFileWidget(url, 'del_dpi_file['+i+']'); }
      });
    }

    var trackCard=document.getElementById('trackCard');
    if(trackCard){
      var trackName = nome || '';
      var trackGrado = p.grado || '';
      var trackData = trackCard.getAttribute('data-data') || new Date().toLocaleDateString('it-IT');
      trackCard.setAttribute('data-nominativo', trackName);
      trackCard.setAttribute('data-grado', trackGrado);
      var tn = trackCard.querySelector('[data-track-nome]'); if(tn) tn.textContent = trackName || '---';
      var tg = trackCard.querySelector('[data-track-grado]'); if(tg) tg.textContent = trackGrado || '---';
      var td = trackCard.querySelector('[data-track-data]'); if(td) td.textContent = trackData || '';
    }
    var trackReq = p.track_richieste || {};
    document.querySelectorAll('#trackCard input[name^="track_req["]').forEach(function(rb){
      var m = rb.name.match(/^track_req\[(.+)\]$/);
      var key = m ? m[1] : '';
      var isYes = !!trackReq[key];
      if (rb.value === 'si') rb.checked = isYes;
      if (rb.value === 'no') rb.checked = !isYes;
    });

    var docWrap = document.getElementById('doc_extra_rows');
    if (docWrap){
      docWrap.innerHTML = '';
      var docs = Array.isArray(p.note_docs) ? p.note_docs : [];
      for (var k=0; k<docs.length; k++){
        docWrap.insertAdjacentHTML('beforeend', docExtraRowTpl(k, docs[k]));
        var field = 'doc_extra_del['+k+']';
        var last = docWrap.lastElementChild;
        var filesBox = document.createElement('div');
        filesBox.className = 'mt-1';
        filesBox.innerHTML = multiFileWidget(docs[k].files||[], field);
        last.querySelector('.row.g-2 .col-md-3')?.appendChild(filesBox);
      }
      if (docs.length === 0) {
        docWrap.insertAdjacentHTML('beforeend', docExtraRowTpl(0));
      }
    }

    var firstTab=document.querySelector('#modalEdit .nav-link[data-bs-target="#tab-anag"]');
    if(firstTab) bootstrap.Tab.getOrCreateInstance(firstTab).show();
  });

  var btnAddAbil=document.getElementById('btnAddAbil');
  if(btnAddAbil) btnAddAbil.addEventListener('click', function(){ var wrap=document.getElementById('abil_rows'); var i=wrap.querySelectorAll('.border.rounded').length; wrap.insertAdjacentHTML('beforeend', abilRowTpl(i)); });

  var btnAddDpi=document.getElementById('btnAddDpi');
  if(btnAddDpi) btnAddDpi.addEventListener('click', function(){ var wrap=document.getElementById('dpi_rows'); var i=wrap.querySelectorAll('.border.rounded').length; wrap.insertAdjacentHTML('beforeend', dpiRowTpl(i)); });

  var btnAddDocExtra=document.getElementById('btnAddDocExtra');
  if(btnAddDocExtra) btnAddDocExtra.addEventListener('click', function(){
    var wrap=document.getElementById('doc_extra_rows'); 
    var i=wrap.querySelectorAll('.border.rounded').length; 
    wrap.insertAdjacentHTML('beforeend', docExtraRowTpl(i));
  });

  window.printTrackCard = function(){
    var card = document.getElementById('trackCard');
    if (!card) return;
    var nominativo = card.getAttribute('data-nominativo') || '';
    var grado = card.getAttribute('data-grado') || '';
    var dataComp = card.getAttribute('data-data') || '';
    var summary = '';
    var items = card.querySelectorAll('ul > li');
    if (items && items.length){
      summary += '<ul>';
      items.forEach(function(li){
        var labelEl = li.querySelector('span');
        var lbl = labelEl ? labelEl.textContent.trim() : '';
        var yes = li.querySelector('input[type="radio"][value="si"]');
        var no  = li.querySelector('input[type="radio"][value="no"]');
        var stato = (yes && yes.checked) ? 'Richiesto' : 'Non richiesto';
        summary += '<li class="mb-1"><strong>'+ (lbl||'Voce') + ':</strong> ' + stato + '</li>';
      });
      summary += '</ul>';
    }
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
      + summary
      + '</body></html>';
    w.document.write(html);
    w.document.close();
    w.focus();
    w.onload = function(){ w.print(); w.close(); };
  };

  var btnTrackPrint=document.getElementById('btnTrackPrint');
  if(btnTrackPrint) btnTrackPrint.addEventListener('click', function(){ window.printTrackCard(); });

  document.addEventListener('click', function(ev){
    var btnPrev = ev.target.closest('button[data-preview]'); if(btnPrev){ openPreview(btnPrev.getAttribute('data-preview')||'', btnPrev.getAttribute('data-title')||''); return; }
    var btnDel = ev.target.closest('button[data-del-target]'); if(btnDel){
      var name = btnDel.getAttribute('data-del-target');
      if(name){
        var form = btnDel.closest('form');
        var input = form.querySelector('input[name="'+name+'"]');
        if(!input){ input = document.createElement('input'); input.type='hidden'; input.name=name; input.value='1'; form.appendChild(input); } else { input.value='1'; }
        var wrap = btnDel.closest('.file-widget'); if(wrap) wrap.classList.add('opacity-50');
      }
      return;
    }
    var btnDelArr = ev.target.closest('button[data-del-array]'); if(btnDelArr){
      var arrName = btnDelArr.getAttribute('data-del-array');
      var url = btnDelArr.getAttribute('data-del-url');
      if(arrName && url){
        var input = document.createElement('input'); input.type='hidden'; input.name=arrName; input.value=url; btnDelArr.closest('form').appendChild(input);
        var wrap = btnDelArr.closest('.file-widget'); if(wrap) wrap.classList.add('opacity-50');
      }
      return;
    }
    var btnDelRow = ev.target.closest('.btn-docextra-delrow'); if(btnDelRow){ var row = btnDelRow.closest('.border.rounded'); if(row){ row.remove(); } return; }
  });

  var form=document.querySelector('#modalEdit form');
  if(form){ form.addEventListener('submit', function(ev){ var id=(document.getElementById('f_id')||{}).value; if(!id || isNaN(Number(id)) || Number(id)<=0){ ev.preventDefault(); alert('ID vigile mancante: riapri la modale con "Modifica".'); } }); }
}
if(document.readyState==='loading'){ document.addEventListener('DOMContentLoaded', initPersonaleUI); } else { initPersonaleUI(); }
</script>
</body>
</html>
