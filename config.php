<?php
// config.php — bootstrap multi-caserma

// 1) Cartella base che conterrà una sottocartella per ogni caserma
define('DATA_CASERME_BASE', __DIR__ . '/dati_caserme');

// 2) Assicura esistenza base
if (!is_dir(DATA_CASERME_BASE)) {
    @mkdir(DATA_CASERME_BASE, 0777, true);
}

// 3) Sorgente della caserma corrente (GET ha priorità, poi COOKIE, poi default)
function _caserma_slug(): string {
    $slug = $_GET['caserma'] ?? ($_COOKIE['caserma'] ?? 'default');
    // sanitizza: lettere, numeri, underscore e trattino
    $slug = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$slug);
    if ($slug === '') $slug = 'default';
    return strtolower($slug);
}

$CASERMA = _caserma_slug();

// 4) Percorso dati della caserma corrente
$CASERMA_DIR = DATA_CASERME_BASE . '/' . $CASERMA;

// 5) Inizializza struttura se non esiste
if (!is_dir($CASERMA_DIR)) {
    @mkdir($CASERMA_DIR, 0777, true);
}
$defaults = [
    'vigili.json'         => "[]",
    'addestramenti.json'  => "[]",
    'personale.json'      => "{}",
    'infortuni.json'      => "[]",
    'attivita.json'       => "[]",
];
foreach ($defaults as $file => $seed) {
    $p = $CASERMA_DIR . '/' . $file;
    if (!file_exists($p)) {
        @file_put_contents($p, $seed);
        @chmod($p, 0666);
    }
}

// 6) Metti un cookie (1 anno) per ricordare la caserma scelta
if (!isset($_COOKIE['caserma']) || $_COOKIE['caserma'] !== $CASERMA) {
    @setcookie('caserma', $CASERMA, [
        'expires'  => time() + 31536000,
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']),
        'httponly' => false,
        'samesite' => 'Lax',
    ]);
}

// 7) Definisci DATA_DIR (verrà usato da storage.php)
//    IMPORTANTE: deve essere definito PRIMA di includere storage.php
if (!defined('DATA_DIR')) {
    define('DATA_DIR', $CASERMA_DIR);
}