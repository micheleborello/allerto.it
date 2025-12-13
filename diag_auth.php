<?php
require __DIR__.'/auth.php';
require __DIR__.'/tenant_bootstrap.php';
header('Content-Type: text/plain; charset=utf-8');

echo "tenant_slug in session: ".($_SESSION['tenant_slug'] ?? '(none)')."\n";
print_r(auth_current_user());

$slug = $_SESSION['tenant_slug'] ?? 'nole';
echo "\nUtenti caricati da data/$slug/users.json:\n";
print_r(auth_load_tenant_users($slug));