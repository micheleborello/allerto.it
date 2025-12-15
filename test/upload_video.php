<?php
// upload_video.php - upload file video in test/assets/videos e restituisce URL
// Protezione base con token (lo stesso di save_data.php)

$TOKEN = 'ALLERTO';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// Token
$token = $_POST['token'] ?? '';
if ($TOKEN && $token !== $TOKEN) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'Token non valido']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok'=>false,'error'=>'Metodo non supportato']);
    exit;
}

if (!isset($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'Nessun file caricato']);
    exit;
}

$tmp  = $_FILES['file']['tmp_name'];
$name = basename($_FILES['file']['name']);
$ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));

// consenti solo alcuni formati base
$allowed = ['mp4','mov','webm','mkv'];
if (!in_array($ext, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'Formato non supportato (solo mp4/mov/webm/mkv)']);
    exit;
}

$safeBase = preg_replace('/[^a-zA-Z0-9._-]/','_', pathinfo($name, PATHINFO_FILENAME));
if ($safeBase === '') $safeBase = 'video';
$destDir = __DIR__.'/assets/videos';
if (!is_dir($destDir)) @mkdir($destDir, 0755, true);
$destName = $safeBase.'_'.date('Ymd_His').'.'.$ext;
$destPath = $destDir.'/'.$destName;

if (!move_uploaded_file($tmp, $destPath)) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'Errore nel salvataggio del file']);
    exit;
}
@chmod($destPath, 0644);

$url = 'assets/videos/'.$destName;
echo json_encode(['ok'=>true, 'url'=>$url, 'file'=>$destName]);
