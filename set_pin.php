<?php
require __DIR__.'/auth.php';
require __DIR__.'/tenant_bootstrap.php';
require_tenant_user();

$vid = isset($_REQUEST['id']) ? (int)$_REQUEST['id'] : 0;
$pin = isset($_REQUEST['pin']) ? trim((string)$_REQUEST['pin']) : '';

if ($vid <= 0) { http_response_code(400); echo "ID mancante"; exit; }
if ($pin !== '' && !preg_match('/^\d{4,6}$/', $pin)) { http_response_code(400); echo "PIN non valido"; exit; }

$raw  = @file_get_contents(VIGILI_JSON);
$arr  = json_decode($raw, true);
if (!is_array($arr)) $arr = [];
$found = false;
foreach ($arr as &$v) {
  if ((int)($v['id'] ?? 0) === $vid) { if ($pin==='') unset($v['pin']); else $v['pin']=$pin; $found=true; break; }
}
unset($v);
if (!$found) { http_response_code(404); echo "Vigile non trovato"; exit; }
@file_put_contents(VIGILI_JSON, json_encode($arr, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));
@chmod(VIGILI_JSON, 0664);
echo "OK";