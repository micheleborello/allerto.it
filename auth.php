<?php
// auth.php — nessun output, nessun BOM

// Avvio sessione sicura (SameSite=Lax e HttpOnly).
if (session_status() === PHP_SESSION_NONE) {
    $params = session_get_cookie_params();
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => $params['path']   ?? '/',
        'domain'   => $params['domain'] ?? '',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/* =========================
 *   UTILS JSON rilassato
 * ========================= */
function json_load_relaxed(string $path): array {
    if (!is_file($path)) return [];
    $s = @file_get_contents($path);
    if ($s === false) return [];
    $s = preg_replace('/^\xEF\xBB\xBF/', '', $s);           // BOM
    $s = preg_replace('/,\s*([\}\]])/', '$1', $s);          // virgole finali
    $data = json_decode($s, true);
    return is_array($data) ? $data : [];
}

/* =========================
 *   BACK-COMPAT SESSION
 * ========================= */
function auth_sync_legacy_session(): void {
    if (empty($_SESSION['auth_user']) && !empty($_SESSION['user']) && is_array($_SESSION['user'])) {
        $_SESSION['auth_user'] = $_SESSION['user'];
    }
    if (!empty($_SESSION['auth_user']) && empty($_SESSION['user'])) {
        $_SESSION['user'] = $_SESSION['auth_user'];
    }
    $u = $_SESSION['auth_user'] ?? $_SESSION['user'] ?? null;
    if (is_array($u) && ($u['role'] ?? '') === 'tenant') {
        $slug = $u['tenant_slug'] ?? ($_SESSION['tenant_slug'] ?? $_SESSION['CASERMA_SLUG'] ?? '');
        if ($slug) {
            $_SESSION['tenant_slug']  = $slug;
            $_SESSION['CASERMA_SLUG'] = $slug;
        }
    }
}
auth_sync_legacy_session();

/* =========================
 *   STATO / RUOLI
 * ========================= */
function auth_current_user(): ?array {
    return $_SESSION['auth_user'] ?? $_SESSION['user'] ?? null;
}
function auth_username(): string {
    $u = auth_current_user();
    if (is_array($u) && isset($u['username'])) return (string)$u['username'];
    if (is_string($u)) return $u;
    return (string)($_SESSION['username'] ?? '');
}
function auth_is_superadmin(): bool {
    $u = auth_current_user();
    return $u && ($u['role'] ?? '') === 'superadmin';
}
function auth_tenant(): ?string {
    return $_SESSION['tenant_slug'] ?? $_SESSION['CASERMA_SLUG'] ?? null;
}
function auth_is_tenant_user(?string $slug=null): bool {
    $u = auth_current_user();
    if (!$u) return false;
    if (($u['role'] ?? '') === 'superadmin') return true;
    $own = $u['tenant_slug'] ?? null;
    $t   = $slug ?? auth_tenant();
    return $own && $t && strcasecmp($own, $t) === 0;
}

/** Utente “vigile”: utente tenant NON capo (uso comune per dashboard personale) */
function auth_is_vigile(): bool {
    $u = auth_current_user();
    if (!$u) return false;
    if (($u['role'] ?? '') !== 'tenant') return false;
    return empty($u['is_capo']); // non capo → vigile
}

function require_superadmin(): void {
    if (!auth_is_superadmin()) {
        http_response_code(403);
        exit('Accesso negato: serve Super Admin.');
    }
}

// *** FIX ANTI-DUPLICAZIONE ***
if (!function_exists('require_tenant_user')) {
    function require_tenant_user(?string $slug=null): void {
        if (!auth_is_tenant_user($slug)) {
            $dest = 'login.php?next='.rawurlencode($_SERVER['REQUEST_URI'] ?? 'index.php');
            header('Location: '.$dest); exit;
        }
    }
}

function auth_require_login_or_redirect(): void {
    if (!auth_current_user()) {
        $dest = 'login.php?next='.rawurlencode($_SERVER['REQUEST_URI'] ?? 'index.php');
        header('Location: '.$dest); exit;
    }
}

/* ====================== SUPERADMIN ====================== */
function auth_load_superadmins(): array {
    $p = __DIR__ . '/data/superadmin_users.json';
    $arr = json_load_relaxed($p);
    $out = [];
    foreach ($arr as $u) {
        $un = trim((string)($u['username'] ?? ''));
        $ph = (string)($u['password_hash'] ?? '');
        if ($un !== '' && $ph !== '') {
            $out[] = ['username'=>$un, 'password_hash'=>$ph];
        }
    }
    return $out;
}

/* ============ UTENTI PER DISTACCAMENTO (capo + perms) ============ */
function auth_load_tenant_users(string $slug): array {
    $slug = preg_replace('/[^a-z0-9_-]/i','',$slug);
    $p = __DIR__ . "/data/{$slug}/users.json";
    $arr = json_load_relaxed($p);
    $out = [];
    foreach ($arr as $u) {
        $un = trim((string)($u['username'] ?? ''));
        $ph = (string)($u['password_hash'] ?? '');
        if ($un === '' || $ph === '') continue;
        $out[] = [
            'username'      => strtolower($un),
            'password_hash' => $ph,
            'is_capo'       => (bool)($u['is_capo'] ?? false),
            'perms'         => array_values(array_filter((array)($u['perms'] ?? []), 'is_string')),
        ];
    }
    return $out;
}

/* =================== LOGIN / LOGOUT =================== */
function auth_login_superadmin(string $username, string $password): bool {
    foreach (auth_load_superadmins() as $u) {
        if (strcasecmp($u['username'] ?? '', $username) === 0) {
            if (!empty($u['password_hash']) && password_verify($password, $u['password_hash'])) {
                $profile = [
                    'role'     => 'superadmin',
                    'username' => $username,
                ];
                $_SESSION['auth_user'] = $profile;
                $_SESSION['user']      = $profile; // back-compat
                return true;
            }
        }
    }
    return false;
}
function auth_login_tenant(string $slug, string $username, string $password): bool {
    $slug = preg_replace('/[^a-z0-9_-]/i','',$slug);
    foreach (auth_load_tenant_users($slug) as $u) {
        if (strcasecmp($u['username'] ?? '', $username) === 0) {
            if (!empty($u['password_hash']) && password_verify($password, $u['password_hash'])) {
                $profile = [
                    'role'        => 'tenant',
                    'username'    => $username,
                    'tenant_slug' => $slug,
                    'is_capo'     => (bool)($u['is_capo'] ?? false),
                    'perms'       => array_values(array_filter((array)($u['perms'] ?? []), 'is_string')),
                ];
                $_SESSION['auth_user']    = $profile;
                $_SESSION['user']         = $profile; // back-compat
                $_SESSION['tenant_slug']  = $slug;
                $_SESSION['CASERMA_SLUG'] = $slug;
                return true;
            }
        }
    }
    return false;
}
function auth_is_logged_in(): bool {
    return (bool) (auth_current_user());
}
function auth_logout(): void {
    unset($_SESSION['auth_user'], $_SESSION['user']);
}

/* ====================== CAPO & PERMESSI ====================== */
function auth_is_capo(): bool {
    $u = auth_current_user();
    return (bool)($u['is_capo'] ?? false);
}
function auth_can(string $perm): bool {
    $u = auth_current_user();
    if (!$u) return false;
    if (auth_is_superadmin()) return true;

    $perms = is_array($u['perms'] ?? null) ? $u['perms'] : [];

    if (!empty($u['is_capo']) && in_array('capo:*', $perms, true)) return true;
    if (in_array($perm, $perms, true)) return true;

    $parts = explode(':', $perm, 2);
    if (count($parts) === 2) {
        $prefixStar = $parts[0] . ':*';
        if (in_array($prefixStar, $perms, true)) return true;
    }
    if (in_array('*', $perms, true)) return true;

    return false;
}
function auth_has_perm(string $perm): bool { return auth_can($perm); }
function require_perm(string $perm): void {
    if (!auth_can($perm)) {
        http_response_code(403);
        exit('Accesso negato: permesso mancante ('.$perm.').');
    }
}

/* ===== MFA Back-Compat gate ===== */
if (!function_exists('require_mfa')) {
    function require_mfa(): void {
        if (session_status() === PHP_SESSION_NONE) session_start();
        auth_sync_legacy_session();

        $u = $_SESSION['auth_user'] ?? $_SESSION['user'] ?? null;
        if (empty($u)) return;

        $mfaEnabled = defined('MFA_ENABLED') ? MFA_ENABLED : true;
        $skipSuper  = defined('MFA_REQUIRE_SUPERADMIN') ? !MFA_REQUIRE_SUPERADMIN : true;

        if (!$mfaEnabled) return;
        if ($skipSuper && (($u['role'] ?? '') === 'superadmin')) return;

        if (empty($_SESSION['mfa_ok'])) {
            $next = $_SERVER['REQUEST_URI'] ?? 'index.php';
            header('Location: totp_verify.php?next=' . urlencode($next));
            exit;
        }
    }
}
if (!function_exists('mfa_verified')) {
    function mfa_verified(): bool {
        if (session_status() === PHP_SESSION_NONE) session_start();
        return !empty($_SESSION['mfa_ok']);
    }
}