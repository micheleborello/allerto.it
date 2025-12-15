<?php
// home.php — landing pubblica: scegli AllertoGest o e-learning (prima del login)
ob_start();
$elearningUrl = 'https://allerto.it/test'; // cartella e-learning (ex test) nella root

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
    body {
      min-height: 100vh;
      background: radial-gradient(circle at 10% 20%, #e0f0ff 0, #f8fbff 40%, #ffffff 70%);
      font-family: "Segoe UI", system-ui, -apple-system, sans-serif;
    }
    .hero { padding: 64px 0 48px; }
    .card-link {
      transition: transform .12s ease, box-shadow .12s ease, background-color .12s ease;
      border: 0;
      background: #fff;
      border-radius: 18px;
      box-shadow: 0 10px 40px rgba(0,0,0,.06);
    }
    .card-link:hover {
      transform: translateY(-3px);
      box-shadow: 0 15px 45px rgba(0,0,0,.08);
      text-decoration: none;
      background: #f9fbff;
    }
    .badge-pill {
      border-radius: 999px;
      padding: .45rem .85rem;
      font-size: .9rem;
    }
    .icon {
      width: 44px; height: 44px;
      border-radius: 12px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 1.2rem;
    }
  </style>
</head>
<body>

<div class="container hero">
  <div class="row justify-content-center text-center mb-5">
    <div class="col-lg-8">
      <h1 class="mb-3">Benvenuto su Allerto</h1>
      <p class="text-muted mb-0">Scegli l'area prima di effettuare il login.</p>
      <?php if ($logged): ?>
        <p class="mt-2"><a class="btn btn-outline-primary btn-sm" href="dashboard_vigile.php">Sei già loggato? Vai alla Dashboard</a></p>
      <?php endif; ?>
    </div>
  </div>

  <div class="row g-4 justify-content-center">
    <div class="col-md-5">
      <a class="card card-link h-100 p-3" href="login.php">
        <div class="card-body text-start">
          <div class="d-flex align-items-center gap-3 mb-3">
            <div class="icon bg-primary text-white">🛠</div>
            <div>
              <span class="badge text-bg-primary badge-pill">AllertoGest</span><br>
              <small class="text-muted">Accesso con credenziali</small>
            </div>
          </div>
          <h4 class="card-title mb-2">Accedi ad AllertoGest</h4>
          <p class="card-text text-muted mb-0">Gestione addestramenti, dashboard vigile e dati anagrafici.</p>
        </div>
      </a>
    </div>
    <div class="col-md-5">
      <a class="card card-link h-100 p-3" href="<?php echo htmlspecialchars($elearningUrl, ENT_QUOTES); ?>">
        <div class="card-body text-start">
          <div class="d-flex align-items-center gap-3 mb-3">
            <div class="icon bg-success text-white">📚</div>
            <div>
              <span class="badge text-bg-success badge-pill">E-learning</span><br>
              <small class="text-muted">Area formativa</small>
            </div>
          </div>
          <h4 class="card-title mb-2">Vai all'area e-learning</h4>
          <p class="card-text text-muted mb-0">Entra nell'area formativa integrata (cartella “test”).</p>
        </div>
      </a>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
