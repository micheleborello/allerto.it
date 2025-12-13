<?php
// accounts_lib.php — gestione account “vigile” per-tenant (email+password)

if (session_status() === PHP_SESSION_NONE) { session_start(); }

/* Util: sanifica slug */
if (!function_exists('acct_slug_safe')) {
  function acct_slug_safe(string $slug): string {
    return preg_replace('/[^a-z0-9_-]/i','', $slug);
  }
}

/* Path helper (non dipende da DATA_DIR) */
if (!function_exists('acct_path')) {
  function acct_path(string $slug): string {
    $slug = acct_slug_safe($slug);
    return __DIR__ . "/data/{$slug}/accounts.json";
  }
}

/* Loader JSON rilassato locale (nome diverso per evitare conflitti) */
if (!function_exists('acct_json_load_relaxed')) {
  function acct_json_load_relaxed(string $path, $fallback = []) {
    if (!is_file($path)) return $fallback;
    $s = @file_get_contents($path);
    if ($s === false) return $fallback;
    $s = preg_replace('/^\xEF\xBB\xBF/', '', $s);
    $s = preg_replace('#/\*.*?\*/#s', '', $s);
    $s = preg_replace('#//.*$#m',    '', $s);
    $s = preg_replace('/,\s*([\}\]])/', '$1', $s);
    $j = json_decode($s, true);
    return is_array($j) ? $j : $fallback;
  }
}

/* Load/Save accounts per slug */
if (!function_exists('acct_load')) {
  function acct_load(string $slug): array {
    $p = acct_path($slug);
    if (!is_file($p)) { @file_put_contents($p, "[]"); @chmod($p,0664); }
    $arr = acct_json_load_relaxed($p, []);
    return is_array($arr) ? $arr : [];
  }
}
if (!function_exists('acct_save')) {
  function acct_save(string $slug, array $rows): void {
    $p = acct_path($slug);
    $dir = dirname($p);
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    $json = json_encode(array_values($rows), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $tmp  = $p.'.tmp_'.uniqid('',true);
    @file_put_contents($tmp, $json, LOCK_EX);
    @chmod($tmp, 0664);
    @rename($tmp, $p);
  }
}

/* Trova account per email (case-insensitive) */
if (!function_exists('acct_find_by_email')) {
  function acct_find_by_email(string $slug, string $email): ?array {
    $email = trim(mb_strtolower($email,'UTF-8'));
    foreach (acct_load($slug) as $r) {
      if (mb_strtolower((string)($r['email'] ?? ''),'UTF-8') === $email) return $r;
    }
    return null;
  }
}

/* Prossimo id progressivo */
if (!function_exists('acct_next_id')) {
  function acct_next_id(array $rows): int {
    $max = 0;
    foreach ($rows as $r) { $id = (int)($r['id'] ?? 0); if ($id > $max) $max = $id; }
    return $max + 1;
  }
}

/* Verifica ID vigile + PIN nel tenant */
if (!function_exists('acct_verify_vigile_pin')) {
  function acct_verify_vigile_pin(string $slug, int $vigileId, string $pin): array {
    $slug = acct_slug_safe($slug);
    $p = __DIR__ . "/data/{$slug}/vigili.json";
    $list = acct_json_load_relaxed($p, []);
    foreach ($list as $v) {
      if ((int)($v['id'] ?? 0) === $vigileId) {
        $havePin = isset($v['pin']) && trim((string)$v['pin']) !== '';
        if (!$havePin) {
          return ['ok'=>false,'err'=>'Per questo vigile non è impostato alcun PIN. Chiedi al Capo di impostarlo da “Gestione PIN”.'];
        }
        if (hash_equals((string)$v['pin'], (string)$pin)) {
          return ['ok'=>true,'vigile'=>$v];
        }
        return ['ok'=>false,'err'=>'PIN non valido per l’ID indicato.'];
      }
    }
    return ['ok'=>false,'err'=>'ID vigile inesistente nel distaccamento selezionato.'];
  }
}

/* Crea account: scrive accounts.json e (se manca) aggiunge email al record del vigile */
if (!function_exists('acct_create')) {
  function acct_create(string $slug, int $vigileId, string $email, string $password): array {
    $slug = acct_slug_safe($slug);
    $email = trim($email);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      return ['ok'=>false,'err'=>'Email non valida.'];
    }
    if (strlen($password) < 6) {
      return ['ok'=>false,'err'=>'Password troppo corta (minimo 6 caratteri).'];
    }

    // no duplicati
    if (acct_find_by_email($slug, $email)) {
      return ['ok'=>false,'err'=>'Questa email è già registrata.'];
    }

    // esistenza vigile
    $vp = __DIR__ . "/data/{$slug}/vigili.json";
    $vig = acct_json_load_relaxed($vp, []);
    $found = false;
    foreach ($vig as &$r) {
      if ((int)($r['id'] ?? 0) === $vigileId) {
        $found = true;
        if (empty($r['email'])) {
          $r['email'] = $email;
          // salva vigili.json aggiornando l'email
          $json = json_encode($vig, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
          @file_put_contents($vp, $json);
          @chmod($vp, 0664);
        }
        break;
      }
    }
    unset($r);
    if (!$found) return ['ok'=>false,'err'=>'ID vigile inesistente.'];

    // salva account
    $rows = acct_load($slug);
    $rows[] = [
      'id'           => acct_next_id($rows),
      'vigile_id'    => $vigileId,
      'email'        => $email,
      'password_hash'=> password_hash($password, PASSWORD_DEFAULT),
      'role'         => 'vigile',
      'created_at'   => date('Y-m-d H:i:s'),
      'last_login_at'=> null,
      'last_ip'      => null,
    ];
    acct_save($slug, $rows);
    return ['ok'=>true];
  }
}

/* Login vigile con email+password (salva sessione compatibile con il resto) */
if (!function_exists('auth_login_vigile_email')) {
  function auth_login_vigile_email(string $slug, string $email, string $password): bool {
    $slug = acct_slug_safe($slug);
    $acc  = acct_find_by_email($slug, $email);
    if (!$acc) return false;
    if (!password_verify($password, (string)($acc['password_hash'] ?? ''))) return false;

    // aggiorna last login
    $rows = acct_load($slug);
    foreach ($rows as &$r) {
      if ((int)($r['id'] ?? 0) === (int)$acc['id']) {
        $r['last_login_at'] = date('Y-m-d H:i:s');
        $r['last_ip']       = $_SERVER['REMOTE_ADDR'] ?? null;
        break;
      }
    }
    unset($r);
    acct_save($slug, $rows);

    // sessione compatibile
    $_SESSION['auth_user'] = [
      'role'        => 'vigile',
      'username'    => $email,          // mostrato nell’UI
      'tenant_slug' => $slug,
      'vigile_id'   => (int)$acc['vigile_id'],
      'perms'       => ['view:index','view:riepilogo','view:personale'], // permessi minimi di sola lettura
    ];
    // per compat
    $_SESSION['tenant_slug']  = $slug;
    $_SESSION['CASERMA_SLUG'] = $slug;

    return true;
  }
}