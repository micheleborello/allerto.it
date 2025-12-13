<?php
// event_log.php — helper per audit log per-tenant
// Scrive/legge da DATA_DIR.'/logs_audit.json'

// Se incluso prima del bootstrap, evita fatal e usa default.
if (!defined('DATA_DIR')) {
  if (!defined('APP_ROOT')) { define('APP_ROOT', realpath(__DIR__)); }
  define('DATA_DIR', APP_ROOT . '/data/default');
}
if (!defined('LOGS_AUDIT_JSON')) {
  define('LOGS_AUDIT_JSON', DATA_DIR . '/logs_audit.json');
}

/** DateTimeZone coerente col tenant (fallback Europe/Rome) */
function _elog_tz(): DateTimeZone {
  $tzId = defined('TENANT_TZ') ? TENANT_TZ : (ini_get('date.timezone') ?: 'Europe/Rome');
  try { return new DateTimeZone($tzId); } catch(Throwable $e) { return new DateTimeZone('Europe/Rome'); }
}

/** Ora “giusta” del tenant, formattata */
function _elog_now(): array {
  $dt = new DateTime('now', _elog_tz());
  return [
    'ts'     => $dt->format('Y-m-d H:i:s'),   // locale tenant
    'ts_iso' => $dt->format(DateTime::ATOM),  // ISO-8601 con offset
  ];
}

/** Ritorna l'IP client best-effort */
function _elog_client_ip(): string {
  foreach (['HTTP_CLIENT_IP','HTTP_X_FORWARDED_FOR','REMOTE_ADDR'] as $k) {
    if (!empty($_SERVER[$k])) return explode(',', $_SERVER[$k])[0];
  }
  return '';
}

/** Carica array di log (sempre array) */
function event_log_load(): array {
  @mkdir(dirname(LOGS_AUDIT_JSON), 0775, true);
  if (!is_file(LOGS_AUDIT_JSON)) {
    @file_put_contents(LOGS_AUDIT_JSON, "[]");
    @chmod(LOGS_AUDIT_JSON, 0664);
  }
  $raw = @file_get_contents(LOGS_AUDIT_JSON);
  $arr = json_decode($raw, true);
  return is_array($arr) ? $arr : [];
}

/** Salva array di log in modo atomico */
function _elog_save(array $rows): void {
  $tmp = LOGS_AUDIT_JSON . '.tmp';
  @file_put_contents($tmp, json_encode($rows, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));
  @chmod($tmp, 0664);
  @rename($tmp, LOGS_AUDIT_JSON);
}

/**
 * Appende un evento al log.
 * @param string $type   es. 'addestramento.create', 'checkin.ok', ...
 * @param array  $data   payload specifico evento (dettagli)
 * @param int    $keep   limite max record da tenere (FIFO), 0 = illimitato
 */
function event_log_append(string $type, array $data = [], int $keep = 5000): void {
  if (session_status() === PHP_SESSION_NONE) { @session_start(); }

  // Metadati comuni
  $user = null;
  if (!empty($_SESSION['user']['username'])) {
    $user = (string)$_SESSION['user']['username'];
  } elseif (!empty($_SESSION['username'])) {
    $user = (string)$_SESSION['username'];
  }

  $now = _elog_now();

  $row = [
    'type'   => $type,
    'ts'     => $now['ts'],      // es. 2025-09-01 11:37:12 (Europe/Rome)
    'ts_iso' => $now['ts_iso'],  // es. 2025-09-01T11:37:12+02:00
    'user'   => $user,
    'ip'     => _elog_client_ip(),
    'ua'     => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
    'data'   => $data,
  ];

  $all = event_log_load();
  $all[] = $row;

  // Mantieni ultimi N
  if ($keep > 0 && count($all) > $keep) {
    $all = array_slice($all, -$keep);
  }

  _elog_save($all);
}