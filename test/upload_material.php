<?php
// upload_material.php - upload di materiale didattico (pdf, doc, immagini, ecc.) in test/assets/materials
// Usa lo stesso token di save_data.php / upload_video.php

$TOKEN = 'ALLERTO';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// token da form-data, query o Authorization: Bearer
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

if ($TOKEN && $token !== $TOKEN) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'Token non valido']);
    exit;
}

$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
$postMaxBytes = convertToBytes(ini_get('post_max_size'));
if ($contentLength > 0 && $postMaxBytes > 0 && $contentLength > $postMaxBytes) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'Dimensione oltre post_max_size ('.ini_get('post_max_size').').']);
    exit;
}

$uploadErr = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
if (!isset($_FILES['file']) || $uploadErr !== UPLOAD_ERR_OK) {
    $msg = 'Nessun file caricato';
    if ($uploadErr === UPLOAD_ERR_INI_SIZE || $uploadErr === UPLOAD_ERR_FORM_SIZE) $msg = 'File troppo grande (upload_max_filesize/post_max_size)';
    elseif ($uploadErr === UPLOAD_ERR_PARTIAL) $msg = 'Upload interrotto (parziale)';
    elseif ($uploadErr === UPLOAD_ERR_NO_TMP_DIR) $msg = 'Cartella temporanea mancante';
    elseif ($uploadErr === UPLOAD_ERR_CANT_WRITE) $msg = 'Scrittura su disco fallita';
    elseif ($uploadErr === UPLOAD_ERR_EXTENSION) $msg = 'Upload bloccato da estensione PHP';
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>$msg]);
    exit;
}

$tmp  = $_FILES['file']['tmp_name'];
$name = basename($_FILES['file']['name']);
$ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));

$allowed = ['pdf','doc','docx','xls','xlsx','ppt','pptx','odt','odp','png','jpg','jpeg','webp','txt','rtf','csv'];
if (!in_array($ext, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'Formato non supportato']);
    exit;
}

$safeBase = preg_replace('/[^a-zA-Z0-9._-]/','_', pathinfo($name, PATHINFO_FILENAME));
if ($safeBase === '') $safeBase = 'materiale';
$destDir = __DIR__.'/assets/materials';
if (!is_dir($destDir)) @mkdir($destDir, 0755, true);
$destName = $safeBase.'_'.date('Ymd_His').'.'.$ext;
$destPath = $destDir.'/'.$destName;

if (!move_uploaded_file($tmp, $destPath)) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'Errore nel salvataggio del file']);
    exit;
}
@chmod($destPath, 0644);

$url = 'assets/materials/'.$destName;
echo json_encode(['ok'=>true, 'url'=>$url, 'file'=>$destName]);

function convertToBytes($val){
    $val = trim((string)$val);
    if ($val === '') return 0;
    $last = strtolower($val[strlen($val)-1]);
    $num = (float)$val;
    switch($last){
        case 'g': return $num * 1024 * 1024 * 1024;
        case 'm': return $num * 1024 * 1024;
        case 'k': return $num * 1024;
        default: return (int)$num;
    }
}
