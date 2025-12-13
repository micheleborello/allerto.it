<?php
// mfa_guard.php — shim unico per richiedere l’MFA email quando serve.

if (session_status() === PHP_SESSION_NONE) session_start();

if (!function_exists('require_mfa')) {
  function require_mfa(): void {
    // Se l’MFA è globalmente disattivata, esci
    if (defined('MFA_ENABLED') && !MFA_ENABLED) return;

    // Se superadmin è esente e l’utente è superadmin, esci
    if (
      function_exists('auth_is_superadmin') &&
      auth_is_superadmin() &&
      defined('MFA_REQUIRE_SUPERADMIN') &&
      !MFA_REQUIRE_SUPERADMIN
    ) {
      return;
    }

    // Se già verificato, esci
    if (!empty($_SESSION['mfa_ok'])) return;

    // Prepara il contesto mfa_ctx per la pagina di verifica
    if (empty($_SESSION['mfa_ctx'])) {
      $ctx = [];
      if (function_exists('auth_is_superadmin') && auth_is_superadmin()) {
        $ctx['type'] = 'superadmin';
      } else {
        $ctx['type'] = 'tenant';
        $ctx['slug'] = $_SESSION['tenant_slug'] ?? ($_SESSION['CASERMA_SLUG'] ?? '');
      }
      if (function_exists('auth_current_user')) {
        $u = auth_current_user();
        $ctx['username'] = $u['username'] ?? '';
      }
      $_SESSION['mfa_ctx'] = $ctx;
    }

    $next = $_SERVER['REQUEST_URI'] ?? 'index.php';
    header('Location: totp_verify.php?next=' . urlencode($next));
    exit;
  }
}
