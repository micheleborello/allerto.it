<?php
// switch_tenant.php — cambia il distaccamento attivo in sessione
// Nessun output HTML; risposte testuali brevi solo in caso di errore.

require_once __DIR__.'/auth.php';
require_once __DIR__.'/tenant_bootstrap.php';
require_once __DIR__.'/storage.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  exit('Metodo non consentito');
}

// richiesta AJAX/same-origin: non è un CSRF “forte”, ma aiuta
if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
  // Non blocco, ma potresti farlo: qui lasciamo passare.
}

// autenticazione minima: serve utente loggato
if (!auth_is_logged_in()) {
  http_response_code(401);
  exit('Non autenticato');
}

$slug = trim((string)($_POST['slug'] ?? ''));
$slug = preg_replace('/[^a-z0-9_-]/i', '', $slug);
if ($slug === '') {
  http_response_code(400);
  exit('Slug mancante o invalido');
}

// carica l’elenco caserme per validare lo slug
if (!function_exists('load_caserme')) {
  function load_caserme(): array { return [['slug'=>'default','name'=>'Default']]; }
}
$caserme = load_caserme();
$known   = false;
foreach ($caserme as $c) {
  if (($c['slug'] ?? '') === $slug) { $known = true; break; }
}
if (!$known) {
  http_response_code(404);
  exit('Distaccamento inesistente');
}

// regole:
// - SUPERADMIN: può cambiare liberamente
// - UTENTE TENANT: può solo impostare il proprio slug (in pratica non è un “cambio”,
//   ma riallinea la sessione se serve)
$user = auth_current_user();
if (!auth_is_superadmin()) {
  $own = (string)($user['tenant_slug'] ?? '');
  if ($own === '' || strcasecmp($own, $slug) !== 0) {
    http_response_code(403);
    exit('Non autorizzato a cambiare distaccamento');
  }
}

// imposta in sessione (manteniamo entrambe per compatibilità col bootstrap esistente)
$_SESSION['tenant_slug']  = $slug;
$_SESSION['CASERMA_SLUG'] = $slug;

// ok
http_response_code(200);
echo 'OK';