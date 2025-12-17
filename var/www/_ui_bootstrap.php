<?php
// _ui_bootstrap.php — inietta CSS/JS responsive in tutte le pagine
// Attivalo con: php.ini o .htaccess  ->  auto_prepend_file = /percorso/_ui_bootstrap.php

// Evita doppie iniezioni
if (!defined('__UI_BOOTSTRAP__')) define('__UI_BOOTSTRAP__', 1);

// Avvia un buffer d'output che post-processa l'HTML
ob_start(function($html){
  // se non è HTML o abbiamo già iniettato, esci
  if (stripos($html, '<html') === false) return $html;
  if (stripos($html, 'app-mobile.css') !== false) return $html;

  // Base URL: supporta installazioni sia su dominio root (/) sia in sottocartella (es. /lavorazione)
  $scriptName = (string)($_SERVER['SCRIPT_NAME'] ?? '/');
  $baseUrl = str_replace('\\', '/', dirname($scriptName));
  if ($baseUrl === '/' || $baseUrl === '.') $baseUrl = '';

  $head = stripos($html, '</head>');
  $inject = [];
  // Meta viewport se manca
  if (stripos($html, 'name="viewport"') === false) {
    $inject[] = '<meta name="viewport" content="width=device-width, initial-scale=1">';
  }
  // CSS + JS (cambia i path se preferisci)
  $inject[] = '<link rel="stylesheet" href="'.$baseUrl.'/assets/app-mobile.css">';
  $inject[] = '<script src="'.$baseUrl.'/assets/app-mobile.js" defer></script>';

  $block = implode("\n", $inject)."\n";

  if ($head !== false) {
    // Inserisci prima di </head>
    $html = substr($html, 0, $head) . $block . substr($html, $head);
  } else {
    // Fallback: appiccica all'inizio del body
    $body = stripos($html, '<body');
    if ($body !== false) {
      $gt = stripos($html, '>', $body);
      if ($gt !== false) {
        $html = substr($html, 0, $gt+1) . "\n".$block . substr($html, $gt+1);
      }
    }
  }
  return $html;
});
