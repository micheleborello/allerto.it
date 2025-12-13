<?php
// zz_diag.php — SOLO LETTURA, non modifica nulla
ini_set('display_errors', 1);
error_reporting(E_ALL);

require __DIR__.'/tenant_bootstrap.php';
require __DIR__.'/storage.php';

if (session_status() === PHP_SESSION_NONE) @session_start();

$slug = function_exists('tenant_active_slug') ? tenant_active_slug() : ($_SESSION['tenant_slug'] ?? '');
$slug = preg_replace('/[^a-z0-9_-]/i','', (string)$slug);
$dataDir = defined('DATA_DIR') ? DATA_DIR : (__DIR__.'/data'.($slug?"/$slug":""));

$paths = [
  'vigili'  => $dataDir.'/vigili.json',
  'users'   => $dataDir.'/users.json',
  'addestr' => $dataDir.'/addestramenti.json',
  'inf'     => $dataDir.'/infortuni.json',
];

function peek_json($p, $max=2) {
  $out = ['exists'=>is_file($p), 'size'=>0, 'count'=>null, 'sample'=>[]];
  if (!is_file($p)) return $out;
  $out['size'] = filesize($p);
  $raw = @file_get_contents($p);
  if ($raw===false) return $out;
  $arr = json_decode($raw, true);
  if (is_array($arr)) {
    $out['count'] = count($arr);
    $out['sample'] = array_slice($arr, 0, $max);
  }
  return $out;
}

$vigili  = peek_json($paths['vigili']);
$users   = peek_json($paths['users']);
$addestr = peek_json($paths['addestr']);
$inf     = peek_json($paths['inf']);

header('Content-Type: text/plain; charset=UTF-8');
echo "=== DIAGNOSI TENANT ===\n";
echo "slug attivo:      {$slug}\n";
echo "DATA_DIR:         {$dataDir}\n\n";

foreach ($paths as $k=>$p) {
  echo strtoupper($k).": {$p}\n";
  $info = $$k;
  echo "  exists: ".($info['exists']?'YES':'NO')."\n";
  echo "  size:   ".$info['size']." bytes\n";
  echo "  count:  ".var_export($info['count'], true)."\n";
  if (!empty($info['sample'])) {
    echo "  sample:\n";
    foreach ($info['sample'] as $row) {
      echo "    - ".json_encode($row, JSON_UNESCAPED_UNICODE)."\n";
    }
  }
  echo "\n";
}

echo "=== NOTE ===\n";
if (!$vigili['exists']) {
  echo "- vigili.json NON trovato nel tenant corrente. Se hai più distaccamenti, forse stai guardando lo slug sbagliato.\n";
}
if ($vigili['exists'] && $vigili['count']===0) {
  echo "- vigili.json esiste ma è VUOTO (count=0). Serve un restore da backup, oppure ricreare i nominativi.\n";
}
if ($users['exists'] && $users['count']===0) {
  echo "- users.json esiste ma è vuoto: la pagina di login non avrà utenti validi.\n";
}
if ($addestr['exists'] && $addestr['count']>0 && ($vigili['count']??0)===0) {
  echo "- addestramenti.json ha dati MA vigili.json è vuoto: la gestione personale apparirà vuota.\n";
}
echo "\nFINE.\n";
