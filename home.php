<?php
// home.php — scelta rapida dopo login (Dashboard vigile / Test)
ob_start();
require __DIR__.'/auth.php';
require __DIR__.'/tenant_bootstrap.php';
require_tenant_user();

$slug = function_exists('tenant_active_slug') ? tenant_active_slug() : ($_SESSION['tenant_slug'] ?? 'default');
$slug = preg_replace('/[^a-z0-9_-]/i','', (string)$slug);

// percorso al sito "test" (cartella test nella root)
$testUrl = 'test/';
?>
<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Benvenuto</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { min-height: 100vh; background: radial-gradient(circle at 20% 20%, #e7f1ff, #f7fbff 45%, #ffffff); }
    .hero { padding: 56px 0; }
    .card-link { transition: transform .1s ease, box-shadow .1s ease; }
    .card-link:hover { transform: translateY(-2px); box-shadow: 0 10px 25px rgba(0,0,0,.08); text-decoration: none; }
  </style>
</head>
<body>

<?php if (is_file(__DIR__.'/_menu.php')) include __DIR__.'/_menu.php'; ?>

<div class="container hero">
  <div class="row justify-content-center text-center mb-5">
    <div class="col-lg-8">
      <h1 class="mb-3">Benvenuto</h1>
      <p class="text-muted mb-0">Scegli dove proseguire dopo l'accesso.</p>
    </div>
  </div>

  <div class="row g-4 justify-content-center">
    <div class="col-md-5">
      <a class="card card-link h-100" href="dashboard_vigile.php<?php echo $slug ? '?slug='.urlencode($slug) : ''; ?>">
        <div class="card-body">
          <div class="d-flex align-items-center gap-3 mb-3">
            <span class="badge text-bg-primary rounded-pill px-3 py-2">Dashboard</span>
            <small class="text-muted">Gestione vigile</small>
          </div>
          <h4 class="card-title mb-2">Dashboard Vigile</h4>
          <p class="card-text text-muted">Accedi alla gestione operativa, report e dati anagrafici.</p>
        </div>
      </a>
    </div>
    <div class="col-md-5">
      <a class="card card-link h-100" href="<?php echo htmlspecialchars($testUrl, ENT_QUOTES); ?>">
        <div class="card-body">
          <div class="d-flex align-items-center gap-3 mb-3">
            <span class="badge text-bg-success rounded-pill px-3 py-2">Test</span>
            <small class="text-muted">Sito addestramento</small>
          </div>
          <h4 class="card-title mb-2">Area Test / Addestramento</h4>
          <p class="card-text text-muted">Entra nel sito di addestramento integrato nella cartella “test”.</p>
        </div>
      </a>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
