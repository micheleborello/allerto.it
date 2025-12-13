<?php
// tools_fix_usernames.php — Rinomina utenti cognome.nome -> nome.cognome se possibile, per TUTTI i distaccamenti
ob_start(); ini_set('display_errors',1); error_reporting(E_ALL);
require_once __DIR__.'/auth.php';
if (function_exists('require_superadmin')) require_superadmin();

$DATA_BASE = __DIR__.'/data';

function load_json_relaxed($p,$def){ if(!is_file($p))return $def; $s=@file_get_contents($p); $a=json_decode($s,true); return is_array($a)?$a:$def; }
function save_json_pretty($p,$arr){ @mkdir(dirname($p),0775,true); @file_put_contents($p,json_encode($arr,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)); @chmod($p,0664); }
function uname_slug($s){ $s=strtolower($s); if(function_exists('iconv')) $s=iconv('UTF-8','ASCII//TRANSLIT',$s); return preg_replace('/[^a-z0-9\.]+/','',$s); }
function candidates($nome,$cognome){
  $n=preg_replace('/[^a-z0-9]+/','',uname_slug($nome));
  $c=preg_replace('/[^a-z0-9]+/','',uname_slug($cognome));
  return [$n.'.'.$c, $c.'.'.$n];
}

$dirs = array_filter(glob($DATA_BASE.'/*'), 'is_dir');
$totalRenamed = 0;
foreach ($dirs as $dir) {
  $slug = basename($dir);
  $vigili = load_json_relaxed("$dir/vigili.json",[]);
  $users  = load_json_relaxed("$dir/users.json",[]);
  if (!$users || !$vigili) continue;

  // index utenti
  $byName = []; foreach ($users as $i=>$u){ $un=strtolower(trim($u['username']??'')); if($un!=='') $byName[$un]=$i; }

  $renamedThis = 0;
  foreach ($vigili as $v) {
    $nome=(string)($v['nome']??''); $cogn=(string)($v['cognome']??''); if($nome===''&&$cogn==='') continue;
    [$nc,$cn] = candidates($nome,$cogn);

    // se esiste cn (cognome.nome) e NON esiste nc (nome.cognome), rinomina cn -> nc
    if (isset($byName[$cn]) && !isset($byName[$nc])) {
      $idx = $byName[$cn];
      $users[$idx]['username'] = $nc;
      unset($byName[$cn]); $byName[$nc]=$idx;
      $renamedThis++; $totalRenamed++;
    }
  }

  if ($renamedThis>0) {
    // ordina e salva
    usort($users, fn($a,$b)=>strcasecmp((string)($a['username']??''),(string)($b['username']??'')));
    save_json_pretty("$dir/users.json",$users);
  }
  echo "[$slug] rinominati: $renamedThis\n";
}

echo "Totale rinominati: $totalRenamed\n";