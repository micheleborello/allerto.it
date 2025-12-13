<?php
// set_password.php
ob_start();
require __DIR__.'/auth.php';
require __DIR__.'/tenant_bootstrap.php';
require_tenant_user();
require_perm('edit:personale'); // o auth_can('admin:perms')

require_mfa();
require __DIR__.'/storage.php';

include __DIR__.'/_menu.php';

$vigili = load_json(VIGILI_JSON);
$err = ''; $msg = '';
if ($_SERVER['REQUEST_METHOD']==='POST'){
  $id = (int)($_POST['vigile_id'] ?? 0);
  $pw = (string)($_POST['password'] ?? '');
  if ($id<=0 || strlen($pw)<12) {
    $err = 'Password troppo corta (min 12).';
  } else {
    if (auth_set_password($id, $pw)) {
      $msg = 'Password aggiornata.';
    } else {
      $err = 'Errore salvataggio.';
    }
  }
}
?>
<!doctype html>
<html lang="it"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Imposta password</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head>
<body class="bg-light">
<div class="container py-4">
  <h1 class="h4">Imposta / reset password utente</h1>
  <?php if ($err): ?><div class="alert alert-danger"><?= htmlspecialchars($err) ?></div><?php endif; ?>
  <?php if ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
  <form method="post" class="card p-3 shadow-sm">
    <div class="row g-2 align-items-end">
      <div class="col-md-6">
        <label class="form-label">Vigile</label>
        <select class="form-select" name="vigile_id" required>
          <option value="">—</option>
          <?php foreach ($vigili as $v): ?>
            <option value="<?= (int)$v['id'] ?>"><?= htmlspecialchars(($v['cognome']??'').' '.($v['nome']??'').' — '.($v['email']??'')) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-6">
        <label class="form-label">Nuova password (min 12)</label>
        <input type="password" class="form-control" name="password" minlength="12" required>
      </div>
    </div>
    <button class="btn btn-primary mt-3">Salva</button>
  </form>
</div>
</body></html>
