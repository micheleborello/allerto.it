<?php
// tenant_bootstrap.php — bootstrap multi-caserma con DATA_DIR isolato per copia
// Inietta CSS/JS responsive una sola volta per richiesta

if (!defined('__UI_BOOTSTRAP__')) {
  define('__UI_BOOTSTRAP__', 1);

  // Radice del progetto
  $APP_ROOT = realpath(__DIR__);

  // Candidati dove potresti aver messo il file
  $candidates = [
    $APP_ROOT.'/_ui_bootstrap.php',                 // <— root del sito (consigliato)
    $APP_ROOT.'/var/www/_ui_bootstrap.php',         // se l’hai messo in una sottocartella var/www del progetto
    '/home/kt4p82hv/allerto.it/_ui_bootstrap.php',  // path assoluto root sito
    '/var/www/_ui_bootstrap.php',                   // path assoluto “classico” (ma nel tuo caso non c’è)
  ];

  foreach ($candidates as $p) {
    if (is_file($p)) { @require_once $p; break; }
  }
}

// Avvia sessione se serve
if (session_status() === PHP_SESSION_NONE) { @session_start(); }

// Fuso orario del tenant
if (!defined('TENANT_TZ')) {
  define('TENANT_TZ', 'Europe/Rome');
}
@date_default_timezone_set(TENANT_TZ);

// Evita doppie inclusioni
if (!defined('TENANT_BOOTSTRAP_LOADED')) {
  define('TENANT_BOOTSTRAP_LOADED', 1);

  // Radice fisica dell’app (isola prod/test automaticamente)
  if (!defined('APP_ROOT')) {
    define('APP_ROOT', realpath(__DIR__)); // es: /home/kt4p82hv/allerto.it
  }

  // ======= Utilities caserme =======
  if (!function_exists('tenant_load_caserme')) {
    function tenant_load_caserme(): array {
      $base = APP_ROOT . '/data';
      $cfg  = $base . '/caserme.json';
      $out  = [];

      // 1) Config esplicita
      if (is_file($cfg)) {
        $raw = @file_get_contents($cfg);
        $arr = $raw ? json_decode($raw, true) : null;
        if (is_array($arr)) {
          foreach ($arr as $r) {
            $slug = preg_replace('~[^a-z0-9_-]+~i', '', (string)($r['slug'] ?? ''));
            $name = trim((string)($r['name'] ?? ''));
            if ($slug === '') continue;
            if (is_dir($base.'/'.$slug)) {
              $out[$slug] = ['slug'=>$slug, 'name'=> ($name!=='' ? $name : $slug)];
            }
          }
        }
      }

      // 2) Fallback/merge: cartelle con users.json
      foreach (glob($base.'/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $slug = basename($dir);
        if (!preg_match('~^[a-z0-9_-]+$~i', $slug)) continue;
        if (is_file($dir.'/users.json') && !isset($out[$slug])) {
          $out[$slug] = ['slug'=>$slug, 'name'=>$slug];
        }
      }

      if (empty($out)) $out['default'] = ['slug'=>'default','name'=>'Default'];
      uasort($out, fn($a,$b)=> strcasecmp((string)$a['name'], (string)$b['name']));
      return array_values($out);
    }
  }

  if (!function_exists('tenant_save_caserme')) {
    function tenant_save_caserme(array $rows): void {
      $base = APP_ROOT . '/data';
      @mkdir($base, 0775, true);
      $p = $base . '/caserme.json';
      $out = [];
      foreach ($rows as $r) {
        $slug = preg_replace('~[^a-z0-9_-]+~i', '', (string)($r['slug'] ?? ''));
        $name = trim((string)($r['name'] ?? ''));
        if ($slug === '') continue;
        $out[] = ['slug' => $slug, 'name' => ($name !== '' ? $name : $slug)];
      }
      $json = json_encode($out, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
      @file_put_contents($p, $json);
      @chmod($p, 0664);
    }
  }

  if (!function_exists('tenant_set_active_slug')) {
    function tenant_set_active_slug(string $slug): void {
      $slug = preg_replace('~[^a-z0-9_-]+~i', '', $slug);
      $_SESSION['tenant_slug']  = $slug;
      $_SESSION['CASERMA_SLUG'] = $slug;
    }
  }

  if (!function_exists('tenant_active_slug')) {
    function tenant_active_slug(): string {
      $slug = $_SESSION['tenant_slug'] ?? $_SESSION['CASERMA_SLUG'] ?? 'default';
      $slug = preg_replace('~[^a-z0-9_-]+~i', '', (string)$slug);
      if ($slug === '') $slug = 'default';
      $slugs = array_column(tenant_load_caserme(), 'slug');
      if (!in_array($slug, $slugs, true)) {
        $slug = $slugs[0] ?? 'default';
      }
      return $slug;
    }
  }

  if (!function_exists('tenant_active_name')) {
    function tenant_active_name(): string {
      $slug = tenant_active_slug();
      foreach (tenant_load_caserme() as $c) {
        if (($c['slug'] ?? '') === $slug) return (string)($c['name'] ?? $slug);
      }
      return $slug;
    }
  }

  if (!function_exists('tenant_bootstrap_from_get')) {
    function tenant_bootstrap_from_get(): void {
      if (!empty($_SESSION['tenant_slug']) || !empty($_SESSION['CASERMA_SLUG'])) return;
      if (!isset($_GET['slug'])) return;
      $slug = preg_replace('~[^a-z0-9_-]+~i', '', (string)$_GET['slug']);
      if ($slug === '') return;
      foreach (tenant_load_caserme() as $c) {
        if (strcasecmp((string)($c['slug'] ?? ''), $slug) === 0) {
          tenant_set_active_slug($slug);
          break;
        }
      }
    }
  }

  if (!function_exists('tenant_define_datadir')) {
    function tenant_define_datadir(): void {
      if (defined('DATA_DIR')) return;

      tenant_bootstrap_from_get(); // prova da ?slug

      $slug = tenant_active_slug();
      $dir  = APP_ROOT . '/data/' . $slug;

      if (!is_dir($dir)) { @mkdir($dir, 0775, true); }

      // Crea file minimi se mancanti
      $seeds = [
        'vigili.json'         => "[]",
        'addestramenti.json'  => "[]",
        'personale.json'      => "{}", // mappa id=>dati
        'infortuni.json'      => "[]",
        'attivita.json'       => "[]",
        'users.json'          => "[]",
      ];
      foreach ($seeds as $f => $init) {
        $p = $dir . '/' . $f;
        if (!is_file($p)) {
          @file_put_contents($p, $init);
          @chmod($p, 0664);
        }
      }

      define('DATA_DIR', $dir);

      // Path noti
      if (!defined('VIGILI_JSON'))     define('VIGILI_JSON',     DATA_DIR . '/vigili.json');
      if (!defined('ADDESTR_JSON'))    define('ADDESTR_JSON',    DATA_DIR . '/addestramenti.json');
      if (!defined('PERSONALE_JSON'))  define('PERSONALE_JSON',  DATA_DIR . '/personale.json');
      if (!defined('INFORTUNI_JSON'))  define('INFORTUNI_JSON',  DATA_DIR . '/infortuni.json');
      if (!defined('ATTIVITA_JSON'))   define('ATTIVITA_JSON',   DATA_DIR . '/attivita.json');
    }
  }

  if (!function_exists('require_tenant_user')) {
    function require_tenant_user(): void {
      if (empty($_SESSION['user'])) {
        $next = $_SERVER['REQUEST_URI'] ?? 'index.php';
        header('Location: login.php?next='.urlencode($next));
        exit;
      }
    }
  }

  // Esegui subito
  tenant_define_datadir();
}