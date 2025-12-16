<?php
// upload_video.php - upload file video in test/assets/videos e restituisce URL
// Protezione base con token (lo stesso di save_data.php)

$TOKEN = 'ALLERTO';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// estrai token anche da query string o header per evitare problemi con form-data troncati
$token = $_POST['token'] ?? $_GET['token'] ?? '';
if (!$token && isset($_SERVER['HTTP_AUTHORIZATION']) && preg_match('/Bearer\\s+(.+)/i', $_SERVER['HTTP_AUTHORIZATION'], $m)) {
    $token = $m[1];
}
$token = is_string($token) ? trim($token) : '';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok'=>false,'error'=>'Metodo non supportato']);
    exit;
}

$token = (string)$token;
if ($TOKEN && $token !== $TOKEN) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'Token non valido']);
    exit;
}

$uploadErr = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
if (!isset($_FILES['file']) || $uploadErr !== UPLOAD_ERR_OK) {
    $msg = 'Nessun file caricato';
    if ($uploadErr === UPLOAD_ERR_INI_SIZE || $uploadErr === UPLOAD_ERR_FORM_SIZE) {
        $msg = 'File troppo grande (supera upload_max_filesize/post_max_size)';
    }
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>$msg]);
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
