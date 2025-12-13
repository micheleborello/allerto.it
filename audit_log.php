<?php
// audit_log.php — logging append-only per tenant (JSON Lines)
// Dipendenze: tenant_bootstrap.php (per DATA_DIR), auth.php (per utente)

require_once __DIR__.'/tenant_bootstrap.php';
if (!defined('AUDIT_LOG')) define('AUDIT_LOG', DATA_DIR.'/audit.log.jsonl');

/** Scrive un evento di audit (append-only) */
function audit_log_event(string $action, array $extra = []): void {
  try {
    // utente corrente (best-effort)
    $user = null;
    if (function_exists('auth_user')) {
      $u = auth_user();
      if (is_array($u)) $user = $u;
    }
    if (!$user && isset($_SESSION['user']) && is_array($_SESSION['user'])) $user = $_SESSION['user'];

    $username = (string)($user['username'] ?? '');
    if ($username === '') $username = 'anon';
    $isCapo = false;
    $raw = $user['is_capo'] ?? ($_SESSION['is_capo'] ?? ($_SESSION['user']['is_capo'] ?? null));
    if (is_bool($raw))        $isCapo = $raw;
    elseif (is_int($raw))     $isCapo = ($raw === 1);
    elseif (is_string($raw))  $isCapo = in_array(strtolower($raw), ['1','true','yes','on'], true);
    if (!$isCapo && function_exists('auth_can') && auth_can('admin:perms')) $isCapo = true;

    // contesto
    $slug = function_exists('tenant_active_slug') ? (tenant_active_slug() ?: ($_SESSION['tenant_slug'] ?? '')) : ($_SESSION['tenant_slug'] ?? '');
    $rec = [
      'ts'        => date('c'),
      'ts_unix'   => time(),
      'action'    => $action,
      'user'      => $username,
      'is_capo'   => $isCapo ? 1 : 0,
      'slug'      => $slug,
      'ip'        => $_SERVER['REMOTE_ADDR'] ?? null,
      'ua'        => $_SERVER['HTTP_USER_AGENT'] ?? null,
      'extra'     => $extra,
    ];
    $line = json_encode($rec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($line !== false) {
      $isNew = !file_exists(AUDIT_LOG);
      @file_put_contents(AUDIT_LOG, $line."\n", FILE_APPEND | LOCK_EX);
      if ($isNew) @chmod(AUDIT_LOG, 0664);
    }
  } catch (Throwable $e) {
    // non bloccare mai il flusso applicativo
  }
}

/** Legge tutti gli eventi (array). Se il file è grosso, valuta una lettura a streaming. */
function audit_log_read_all(): array {
  if (!file_exists(AUDIT_LOG)) return [];
  $out = [];
  $fh = @fopen(AUDIT_LOG, 'r');
  if (!$fh) return [];
  while (($line = fgets($fh)) !== false) {
    $line = trim($line);
    if ($line === '') continue;
    $row = json_decode($line, true);
    if (is_array($row)) $out[] = $row;
  }
  fclose($fh);
  return $out;
}