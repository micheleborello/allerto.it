<?php
// pins_admin.php — gestione PIN (solo utenti loggati della caserma)
require __DIR__.'/auth.php';
require __DIR__.'/tenant_bootstrap.php';
require_tenant_user();

// Solo Capo Distaccamento
if (!function_exists('auth_is_capo') || !auth_is_capo()) {
  http_response_code(403);
  exit('Solo Capo Distaccamento.');
}
require_perm('admin:pins');

// Util: carica JSON assoc
function json_load_assoc(string $path) : array {
  $raw = @file_get_contents($path);
  $arr = json_decode($raw, true);
  return is_array($arr) ? $arr : [];
}

$vigili = json_load_assoc(VIGILI_JSON);

$info = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  $id  = (int)($_POST['id'] ?? 0);
  $pin = trim((string)($_POST['pin'] ?? ''));
  if ($id <= 0) {
    $info = 'ID non valido';
  } elseif ($pin !== '' && !preg_match('/^\d{4,6}$/', $pin)) {
    $info = 'PIN non valido (4–6 cifre)';
  } else {
    foreach ($vigili as &$v) {
      if ((int)($v['id'] ?? 0) === $id) {
        if ($pin === '') unset($v['pin']); else $v['pin'] = $pin;
        break;
      }
    }
    unset($v);
    @file_put_contents(VIGILI_JSON, json_encode($vigili, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));
    @chmod(VIGILI_JSON, 0664);
    $info = 'Aggiornato.';
  }
}
?>
<!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <title>Gestione PIN — <?php echo htmlspecialchars(tenant_active_name(), ENT_QUOTES); ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php include __DIR__.'/_menu.php'; ?>

<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="mb-0">Gestione PIN</h1>
    <span class="text-muted"><?php echo htmlspecialchars(tenant_active_name(), ENT_QUOTES); ?></span>
  </div>

  <?php if ($info): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($info, ENT_QUOTES); ?></div>
  <?php endif; ?>

  <div class="card shadow-sm">
    <div class="card-body">
      <div class="table-responsive">
        <table class="table table-sm align-middle">
          <thead class="table-light">
            <tr>
              <th style="width:90px;">ID</th>
              <th>Nominativo</th>
              <th style="width:140px;">Stato PIN</th>
              <th style="min-width:300px;">Imposta / Reset</th>
            </tr>
          </thead>
          <tbody>
          <?php
          usort($vigili, function($a,$b){
            $ac = trim(($a['cognome'] ?? '').' '.($a['nome'] ?? ''));
            $bc = trim(($b['cognome'] ?? '').' '.($b['nome'] ?? ''));
            return strcasecmp($ac, $bc);
          });
          foreach ($vigili as $v):
            $id = (int)($v['id'] ?? 0);
            if ($id <= 0) continue;
            $name = trim(($v['cognome'] ?? '').' '.($v['nome'] ?? ''));
            $has  = isset($v['pin']) && trim((string)$v['pin']) !== '';
          ?>
            <tr>
              <td><code><?php echo $id; ?></code></td>
              <td><?php echo htmlspecialchars($name, ENT_QUOTES); ?></td>
              <td>
                <?php if ($has): ?>
                  <span class="badge text-bg-success">Impostato</span>
                <?php else: ?>
                  <span class="badge text-bg-secondary">—</span>
                <?php endif; ?>
              </td>
              <td>
                <form class="row g-2 align-items-center" method="post" action="">
                  <input type="hidden" name="id" value="<?php echo $id; ?>">
                  <div class="col-auto">
                    <input class="form-control form-control-sm" type="password" name="pin"
                           placeholder="nuovo PIN (vuoto = reset)"
                           inputmode="numeric" pattern="\d{4,6}">
                  </div>
                  <div class="col-auto">
                    <button type="submit" class="btn btn-sm btn-primary">Salva</button>
                  </div>
                  <div class="col-auto text-muted small">
                    4–6 cifre. Vuoto = rimuovi PIN.
                  </div>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="text-muted small">Suggerimento: per sicurezza, comunica i PIN ai vigili attraverso canali affidabili.</div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>