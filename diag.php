<?php
// diag.php — NO cache, mostra errori, controlli veloci
header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "PHP OK\n";
echo "Versione: ".PHP_VERSION."\n";

require __DIR__.'/auth.php';
require __DIR__.'/tenant_bootstrap.php'; // definisce DATA_DIR per-tenant
require __DIR__.'/storage.php';

echo "DATA_DIR: ".(defined('DATA_DIR')?DATA_DIR:'(non definita)')."\n";
echo "VIGILI_JSON: ".VIGILI_JSON."\n";
echo "ADDESTR_JSON: ".ADDESTR_JSON."\n";
echo "INFORTUNI_JSON: ".INFORTUNI_JSON."\n";
echo "PERSONALE_JSON: ".PERSONALE_JSON."\n";
echo "ATTIVITA_JSON: ".ATTIVITA_JSON."\n\n";

// Verifica validità JSON (non lancia fatali)
$chk = function($label, $path){
  $ok = function_exists('json_is_valid_file') ? json_is_valid_file($path) : true;
  echo sprintf("%-18s : %s (%s)\n", $label, $ok ? 'OK' : 'BROKEN', $path);
};

$chk('vigili', VIGILI_JSON);
$chk('addestramenti', ADDESTR_JSON);
$chk('infortuni', INFORTUNI_JSON);
$chk('personale', PERSONALE_JSON);
$chk('attivita', ATTIVITA_JSON);

echo "\nProvo load_json(addestramenti)...\n";
$a = load_addestramenti();
echo "Records addestramenti: ".(is_array($a)?count($a):-1)."\n";

echo "\nFINE\n";