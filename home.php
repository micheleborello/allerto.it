<?php
// home.php — landing pubblica: scegli AllertoGest o Allerto Test (prima del login)
ob_start();
$testUrl = 'test/'; // cartella test nella root

// Se già autenticato come tenant, mostra link rapido alla dashboard
$logged = false;
if (session_status() === PHP_SESSION_NONE) session_start();
if (!empty($_SESSION['TENANT_USER'])) $logged = true;
?>
<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Allerto | Scegli l'area</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { min-height: 100vh; background: radial-gradient(circle at 20% 20%, #e7f1ff, #f7fbff 45%, #ffffff); }
    .hero { padding: 56px 0; }
    .card-link { transition: transform .1s ease, box-shadow .1s ease; border: 0; }
    .card-link:hover { transform: translateY(-2px); box-shadow: 0 10px 25px rgba(0,0,0,.08); text-decoration: none; }
  </style>
</head>
<body>

<div class="container hero">
  <div class="row justify-content-center text-center mb-5">
    <div class="col-lg-8">
      <h1 class="mb-3">Benvenuto su Allerto</h1>
      <p class="text-muted mb-0">Scegli l'area a cui vuoi accedere prima di effettuare il login.</p>
      <?php if ($logged): ?>
        <p class="mt-2"><a class="btn btn-outline-primary btn-sm" href="dashboard_vigile.php">Sei già loggato? Vai alla Dashboard</a></p>
      <?php endif; ?>
    </div>
  </div>

  <div class="row g-4 justify-content-center">
    <div class="col-md-5">
      <a class="card card-link h-100" href="login.php">
        <div class="card-body">
          <div class="d-flex align-items-center gap-3 mb-3">
            <span class="badge text-bg-primary rounded-pill px-3 py-2">AllertoGest</span>
            <small class="text-muted">Accesso con credenziali</small>
          </div>
          <h4 class="card-title mb-2">Accedi ad AllertoGest</h4>
          <p class="card-text text-muted">Gestione addestramenti, dashboard vigile e dati anagrafici.</p>
        </div>
      </a>
    </div>
    <div class="col-md-5">
      <a class="card card-link h-100" href="<?php echo htmlspecialchars($testUrl, ENT_QUOTES); ?>">
        <div class="card-body">
          <div class="d-flex align-items-center gap-3 mb-3">
            <span class="badge text-bg-success rounded-pill px-3 py-2">Allerto Test</span>
            <small class="text-muted">Accesso area test</small>
          </div>
          <h4 class="card-title mb-2">Vai all'area Test</h4>
          <p class="card-text text-muted">Entra nel sito di addestramento integrato (cartella “test”).</p>
        </div>
      </a>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
