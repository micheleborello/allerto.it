<?php
declare(strict_types=1);

/**
 * storage.php
 * Gestione file JSON multi-tenant con fallback automatico alla cartella globale /data.
 *
 * ✅ Legge prima in data/<slug>/<file>.json; se non esiste, legge data/<file>.json (globale).
 * ✅ Scrive SEMPRE in data/<slug>/<file>.json (crea la cartella se manca).
 * ✅ Nessun dato globale toccato. Nessuna perdita. Fine della pantomima “vedo 0 utenti”.
 *
 * Config veloce:
 * - Metti questa libreria nella root del progetto (accanto alla cartella "data").
 * - Se vuoi cambiare la cartella base, imposta STORAGE_BASE_DIR a piacere.
 */

// ========================= CONFIG =========================

// Cartella base dei dati (di default: <root>/data)
if (!defined('STORAGE_BASE_DIR')) {
    define('STORAGE_BASE_DIR', rtrim(__DIR__ . DIRECTORY_SEPARATOR . 'data', DIRECTORY_SEPARATOR));
}

// Nome/i chiave di sessione/param che possono contenere lo slug del distaccamento
if (!defined('STORAGE_TENANT_KEYS')) {
    // Ordine di priorità: usa la prima che trovi
    define('STORAGE_TENANT_KEYS', json_encode([
        'tenant', 'slug', 'distaccamento', 'customer_slug', 'customer_id'
    ], JSON_THROW_ON_ERROR));
}

// Se true, quando lo slug non è valido, usa "default"
if (!defined('STORAGE_ALLOW_DEFAULT_TENANT')) {
    define('STORAGE_ALLOW_DEFAULT_TENANT', true);
}

// Slug di fallback quando non si trova niente
if (!defined('STORAGE_DEFAULT_TENANT')) {
    define('STORAGE_DEFAULT_TENANT', 'default');
}

// Permessi di default per cartelle e file creati
if (!defined('STORAGE_DIR_MODE')) define('STORAGE_DIR_MODE', 0775);
if (!defined('STORAGE_FILE_MODE')) define('STORAGE_FILE_MODE', 0664);

// ====================== FUNZIONI CORE ======================

/**
 * Ritorna lo slug “ragionevole” del tenant/distaccamento.
 * Sonda: $_GET, $_POST, header X-Tenant, $_SESSION, e infine default (se abilitato).
 */
function storage_current_tenant(): string
{
    static $cache = null;
    if ($cache !== null) return $cache;

    // 1) Dalle query/post
    $keys = json_decode((string)STORAGE_TENANT_KEYS, true);
    foreach ($keys as $k) {
        if (isset($_GET[$k]) && is_string($_GET[$k]) && $_GET[$k] !== '') {
            $slug = storage_slugify($_GET[$k]);
            if ($slug !== '') return $cache = $slug;
        }
        if (isset($_POST[$k]) && is_string($_POST[$k]) && $_POST[$k] !== '') {
            $slug = storage_slugify($_POST[$k]);
            if ($slug !== '') return $cache = $slug;
        }
    }

    // 2) Da header (es. reverse proxy o app)
    $headers = storage_request_headers();
    foreach (['X-Tenant', 'X-Distaccamento', 'X-Customer', 'X-Customer-Id'] as $hk) {
        if (!empty($headers[$hk])) {
            $slug = storage_slugify($headers[$hk]);
            if ($slug !== '') return $cache = $slug;
        }
    }

    // 3) Da sessione
    if (session_status() === PHP_SESSION_ACTIVE) {
        foreach ($keys as $k) {
            if (isset($_SESSION[$k]) && is_string($_SESSION[$k]) && $_SESSION[$k] !== '') {
                $slug = storage_slugify($_SESSION[$k]);
                if ($slug !== '') return $cache = $slug;
            }
        }
    }

    // 4) Default (opzionale)
    if (STORAGE_ALLOW_DEFAULT_TENANT) {
        return $cache = storage_slugify((string)STORAGE_DEFAULT_TENANT) ?: 'default';
    }

    // 5) Niente: errore brutale (volendo)
    return $cache = 'default';
}

/**
 * Leggi JSON con fallback.
 * Cerca: data/<tenant>/<filename>  → se manca → data/<filename>
 * @return array<mixed>
 */
function read_json(string $filename, ?string $tenant = null): array
{
    $tenant = $tenant ? storage_slugify($tenant) : storage_current_tenant();

    $tenantPath = storage_path_tenant($tenant, $filename);
    if (is_file($tenantPath)) {
        $data = storage_read_json_file($tenantPath);
        if ($data !== null) return $data;
    }

    $globalPath = storage_path_global($filename);
    if (is_file($globalPath)) {
        $data = storage_read_json_file($globalPath);
        if ($data !== null) return $data;
    }

    // Se non esiste nulla, ritorna array vuoto (così la UI non schianta)
    return [];
}

/**
 * Scrivi JSON nel tenant.
 * Crea data/<tenant>/ se manca. Non tocca il globale.
 * @param array<mixed> $data
 */
function write_json(string $filename, array $data, ?string $tenant = null): void
{
    $tenant = $tenant ? storage_slugify($tenant) : storage_current_tenant();

    $path = storage_path_tenant($tenant, $filename);
    $dir  = dirname($path);

    if (!is_dir($dir)) {
        if (!@mkdir($dir, STORAGE_DIR_MODE, true) && !is_dir($dir)) {
            throw new RuntimeException("Impossibile creare directory: $dir");
        }
    }

    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('JSON encoding fallito: ' . json_last_error_msg());
    }

    storage_atomic_write($path, $json);
    @chmod($path, STORAGE_FILE_MODE);
}

/**
 * Restituisce il path completo data/<tenant>/<filename>
 */
function storage_path_tenant(string $tenant, string $filename): string
{
    $tenant = storage_slugify($tenant);
    $filename = ltrim($filename, DIRECTORY_SEPARATOR);
    return STORAGE_BASE_DIR . DIRECTORY_SEPARATOR . $tenant . DIRECTORY_SEPARATOR . $filename;
}

/**
 * Restituisce il path completo data/<filename> (globale)
 */
function storage_path_global(string $filename): string
{
    $filename = ltrim($filename, DIRECTORY_SEPARATOR);
    return STORAGE_BASE_DIR . DIRECTORY_SEPARATOR . $filename;
}

/**
 * Utility: lettura sicura JSON da file → array|null
 * Ritorna null se file non leggibile o malformato.
 * Non esplode: sei in produzione, non in laboratorio.
 */
function storage_read_json_file(string $path): ?array
{
    $contents = @file_get_contents($path);
    if ($contents === false) return null;

    $decoded = json_decode($contents, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        return null;
    }
    return $decoded;
}

/**
 * Scrittura atomica con lock (tmp + rename).
 */
function storage_atomic_write(string $path, string $contents): void
{
    $tmp = $path . '.tmp-' . bin2hex(random_bytes(6));
    $fp = @fopen($tmp, 'wb');
    if ($fp === false) {
        throw new RuntimeException("Impossibile aprire file temporaneo: $tmp");
    }

    try {
        if (!@flock($fp, LOCK_EX)) {
            throw new RuntimeException("Impossibile ottenere lock su: $tmp");
        }
        if (@fwrite($fp, $contents) === false) {
            throw new RuntimeException("Scrittura fallita su: $tmp");
        }
        @fflush($fp);
        @flock($fp, LOCK_UN);
    } finally {
        @fclose($fp);
    }

    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException("Rename atomico fallito verso: $path");
    }
}

/**
 * Normalizza slug (solo [a-z0-9_-])
 */
function storage_slugify(string $raw): string
{
    $raw = trim(mb_strtolower($raw, 'UTF-8'));
    // converte spazi in trattini
    $raw = preg_replace('/\s+/', '-', $raw) ?? $raw;
    // filtra caratteri indesiderati
    $raw = preg_replace('/[^a-z0-9_-]+/', '', $raw) ?? $raw;
    // squeeze trattini
    $raw = preg_replace('/-+/', '-', $raw) ?? $raw;
    return trim($raw, '-_');
}

/**
 * Recupera headers richiesta in modo cross-server.
 * @return array<string,string>
 */
function storage_request_headers(): array
{
    $headers = [];
    // Apache/nginx fastcgi populano $_SERVER con HTTP_*
    foreach ($_SERVER as $k => $v) {
        if (strpos($k, 'HTTP_') === 0) {
            $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($k, 5)))));
            $headers[$name] = is_string($v) ? $v : '';
        }
    }
    // Fallback per funzione getallheaders se disponibile
    if (function_exists('getallheaders')) {
        foreach ((array)getallheaders() as $name => $value) {
            $headers[$name] = is_string($value) ? $value : '';
        }
    }
    return $headers;
}

// =================== HELPERS “PRONTI SUBITO” ===================

/**
 * Helper per ottenere “vigili” con fallback.
 * Esempio d’uso:
 *   $vigili = get_vigili();
 */
function get_vigili(?string $tenant = null): array
{
    return read_json('vigili.json', $tenant);
}

/**
 * Helper per scrivere “vigili”.
 */
function put_vigili(array $rows, ?string $tenant = null): void
{
    write_json('vigili.json', $rows, $tenant);
}

/**
 * Stessa cosa per “addestramenti”.
 */
function get_addestramenti(?string $tenant = null): array
{
    return read_json('addestramenti.json', $tenant);
}
function put_addestramenti(array $rows, ?string $tenant = null): void
{
    write_json('addestramenti.json', $rows, $tenant);
}

/**
 * E altro a piacere: personale, ruoli, turni, ecc.
 * Aggiungi qui i tuoi alias, non toccare il cuore sopra.
 */
// function get_personale(?string $tenant = null): array { return read_json('personale.json', $tenant); }
// function put_personale(array $rows, ?string $tenant = null): void { write_json('personale.json', $rows, $tenant); }