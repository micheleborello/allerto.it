<?php
// vol_logout.php — logout volontario
require __DIR__.'/tenant_bootstrap.php';
if (session_status() === PHP_SESSION_NONE) @session_start();
unset($_SESSION['vol_user']);

$slug = isset($_GET['slug']) ? preg_replace('/[^a-z0-9_-]/i','', (string)$_GET['slug']) : '';
$dest = 'vol_login.php';
if ($slug !== '') $dest .= '?slug='.urlencode($slug);
header('Location: '.$dest, true, 303);
exit;
