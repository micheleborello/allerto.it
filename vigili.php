<?php
// vigili.php – Gestione anagrafica vigili (compatibile PHP 7.1+)

ob_start();
ini_set('display_errors','1');
error_reporting(E_ALL);

/* =========================
   SAFE MODE (debug)
   - ?safe=1 bypassa require_tenant_user / require_mfa / require_perm
   ========================= */
$SAFE_MODE = isset($_GET['safe']) && $_GET['safe'] === '1';
if ($SAFE_MODE) {
  header('X-Debug-Info: vigili SAFE_MODE=1');
}

require __DIR__.'/auth.php';
require __DIR__.'/tenant_bootstrap.php';
if (!$SAFE_MODE && function_exists('require_tenant_user')) { require_tenant_user(); }
if (!$SAFE_MODE && function_exists('require_mfa')) { require_mfa(); }

require __DIR__.'/storage.php';
require __DIR__.'/utils.php';

/* ======= Permessi (soft) ======= */
$can_view = true;
$can_edit = true;
if (!$SAFE_MODE && function_exists('check_perm')) {
  $can_view = (bool)check_perm('view:vigili');
  $can_edit = (bool)check_perm('edit:vigili');
}

/* ============================================================
   PERCORSI COERENTI CON caserme.php
   -> /data/<slug>/vigili.json  e  /data/<slug>/addestramenti.json
   ============================================================ */
if (!function_exists('tenant_active_slug')) {
  function tenant_active_slug(): string {
    return (string)($_SESSION['tenant_slug'] ?? $_SESSION['CASERMA_SLUG'] ?? 'default');
  }
}
if (!defined('DATA_CASERME_BASE')) {
  // stessa base che usa caserme.php (cartella /data del progetto)
  define('DATA_CASERME_BASE', __DIR__ . '/data');
}
$__slug = preg_replace('/[^a-z0-9_-]/i', '', tenant_active_slug());
$__dir  = rtrim(DATA_CASERME_BASE, '/').'/'.$__slug;

// opzionale: tieni anche DATA_DIR puntato alla cartella del tenant
if (!defined('DATA_DIR')) define('DATA_DIR', $__dir);

if (!defined('VIGILI_JSON'))   define('VIGILI_JSON',  $__dir.'/vigili.json');
if (!defined('ADDESTR_JSON'))  define('ADDESTR_JSON', $__dir.'/addestramenti.json');

/* ======= IO JSON TOLLERANTI ======= */
if (!function_exists('load_json')) {
  function load_json($path) {
    if (!is_file($path)) return array();
    $s = @file_get_contents($path);
    if ($s === false) return array();
    // rimuovi BOM e virgole finali tollerate
    $s = preg_replace('/^\xEF\xBB\xBF/', '', $s);
    $s = preg_replace('/,\s*([\}\]])/m', '$1', $s);
    $j = json_decode($s, true);
    return is_array($j) ? $j : array();
  }
}
if (!function_exists('save_json')) {
  function save_json($path, array $data) {
    $dir = dirname($path);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $json = json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
    if ($json === false) throw new RuntimeException('JSON encoding fallita: '.json_last_error_msg());
    $tmp = $path.'.tmp-'.bin2hex(random_bytes(6));
    if (file_put_contents($tmp, $json) === false) throw new RuntimeException('Scrittura temporanea fallita');
    if (!@rename($tmp, $path)) { @unlink($tmp); throw new RuntimeException('Rename atomico fallito'); }
    @chmod($path, 0664);
  }
}
if (!function_exists('next_id')) {
  function next_id(array $rows) {
    $max = 0; foreach ($rows as $r) { $id = isset($r['id']) ? (int)$r['id'] : 0; if ($id > $max) $max = $id; }
    return $max + 1;
  }
}

/* ============================================================
   Backend COERENTE con riepilogo.php
   - Usiamo i JSON tenant-aware come SORGENTE DI VERITÀ
   ============================================================ */

function _backend_label() { return 'json'; }

if (!function_exists('read_vigili')) {
  function read_vigili() {
    // usa sempre i JSON locali (tenant-aware)
    $out = load_json(VIGILI_JSON);
    // normalizza gradi e attivo
    foreach ($out as &$v) {
      if (isset($v['grado']) && $v['grado'] !== null && $v['grado'] !== '') {
        $g = strtoupper(trim((string)$v['grado']));
        $g = rtrim($g, '.');
        if ($g === 'VV') $g = 'VIG';
        $v['grado'] = $g;
      }
      if (!isset($v['attivo'])) $v['attivo'] = 1;
    }
    unset($v);
    return $out;
  }
}

if (!function_exists('write_vigili')) {
  function write_vigili(array $rows) {
    // salva sempre nello stesso JSON usato da riepilogo.php
    return save_json(VIGILI_JSON, $rows);
  }
}

// header di debug per vedere subito il backend e file
header('X-Vigili-Backend: '._backend_label());
header('X-Vigili-Json: '.VIGILI_JSON);

/* ======= DIAGNOSTICA RAPIDA ======= */
if (isset($_GET['diag'])) {
  header('Content-Type: text/plain; charset=utf-8');
  $vigiliPath   = VIGILI_JSON;
  $addestrPath  = ADDESTR_JSON;

  echo "=== DIAGNOSTICA VIGILI ===\n\n";
  echo "[Backend]\n";
  echo " - Backend: "._backend_label()."\n";
  echo "\n[Percorsi]\n";
  echo " - DATA_DIR: ".DATA_DIR."\n";
  echo " - VIGILI_JSON: $vigiliPath ".(file_exists($vigiliPath)?"(OK)":"(NON esiste)")."\n";
  echo " - ADDESTR_JSON: $addestrPath ".(file_exists($addestrPath)?"(OK)":"(NON esiste)")."\n";

  $j = read_vigili();
  echo "\n[Test lettura vigili]\n";
  echo " - OK (".count($j)." record)\n";

  $id = (int)($_GET['id'] ?? 0);
  if ($id>0) {
    foreach ($j as $r) if ((int)($r['id']??0)===$id) { echo "\n[Record $id]\n"; print_r($r); break; }
  }
  exit;
}

/* ======= UI const ======= */
$GRADI_AMMESSI = array('FTAV','CRV','CSV','VIG'); // senza punto
$err = isset($_GET['err']) ? (string)$_GET['err'] : '';
$msg = isset($_GET['msg']) ? (string)$_GET['msg'] : '';

/* ============================================================
   AZIONI: TOGGLE via GET (redirect immediato), resto via POST
   ============================================================ */

/* --- TOGGLE (GET) --- */
if (isset($_GET['action']) && $_GET['action'] === 'toggle') {
  if (!$can_edit && !$SAFE_MODE) { header('Location: vigili.php?err=forbidden'); exit; }
  $id   = (int)($_GET['id'] ?? 0);
  $back = (string)($_GET['back'] ?? '');

  $vigili = read_vigili();
  foreach ($vigili as &$v) {
    if ((int)($v['id'] ?? 0) === $id) {
      $v['attivo'] = (int)($v['attivo'] ?? 1) ? 0 : 1;
      break;
    }
  }
  unset($v);
  write_vigili($vigili);

  $dest = $back !== '' ? $back : 'vigili.php?msg=stato_ok';
  header('Location: '.$dest); exit;
}

/* --- POST (add/edit/delete) --- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!$can_edit && !$SAFE_MODE) { header('Location: vigili.php?err=forbidden'); exit; }

  $vigili = read_vigili();
  $action = isset($_POST['action']) ? (string)$_POST['action'] : '';

  if ($action === 'add') {
    $cognome = trim(preg_replace('/\s+/', ' ', isset($_POST['cognome'])?$_POST['cognome']:''));
    $nome    = trim(preg_replace('/\s+/', ' ', isset($_POST['nome'])?$_POST['nome']:''));
    $grado   = strtoupper(trim((string)($_POST['grado'] ?? ''))); $grado = rtrim($grado, '.'); if ($grado==='VV') $grado='VIG';
    $is_capo = isset($_POST['is_capo']) ? 1 : 0;

    if ($cognome === '' || $nome === '') { header('Location: vigili.php?err=campi'); exit; }
    if ($grado !== '' && !in_array($grado, $GRADI_AMMESSI, true)) { header('Location: vigili.php?err=grado'); exit; }

    foreach ($vigili as $v) {
      $k1 = mb_strtolower(trim((isset($v['cognome'])?$v['cognome']:'').' '.(isset($v['nome'])?$v['nome']:'')));
      $k2 = mb_strtolower(trim($cognome.' '.$nome));
      if ($k1 === $k2) { header('Location: vigili.php?err=dup_nome'); exit; }
    }

    $id = next_id($vigili);
    $vigili[] = array(
      'id'         => $id,
      'cognome'    => $cognome,
      'nome'       => $nome,
      'grado'      => ($grado !== '' ? $grado : null),
      'attivo'     => 1,
      'is_capo'    => $is_capo,
      'created_at' => date('Y-m-d H:i:s'),
    );
    write_vigili($vigili);
    header('Location: vigili.php?msg=aggiunto'); exit;
  }

  if ($action === 'edit') {
    $id      = (int)($_POST['id'] ?? 0);
    $cognome = trim(preg_replace('/\s+/', ' ', $_POST['cognome'] ?? ''));
    $nome    = trim(preg_replace('/\s+/', ' ', $_POST['nome'] ?? ''));
    $grado   = strtoupper(trim((string)($_POST['grado'] ?? ''))); $grado = rtrim($grado, '.'); if ($grado==='VV') $grado='VIG';
    $is_capo = isset($_POST['is_capo']) ? 1 : 0;

    if ($id <= 0 || $cognome === '' || $nome === '') { header('Location: vigili.php?err=campi'); exit; }
    if ($grado !== '' && !in_array($grado, $GRADI_AMMESSI, true)) { header('Location: vigili.php?err=grado'); exit; }

    foreach ($vigili as $v) {
      if ((int)($v['id'] ?? 0) === $id) continue;
      $k1 = mb_strtolower(trim((isset($v['cognome'])?$v['cognome']:'').' '.(isset($v['nome'])?$v['nome']:'')));
      $k2 = mb_strtolower(trim($cognome.' '.$nome));
      if ($k1 === $k2) { header('Location: vigili.php?err=dup_nome'); exit; }
    }

    $found = false;
    foreach ($vigili as &$v) {
      if ((int)($v['id'] ?? 0) === $id) {
        $v['cognome'] = $cognome;
        $v['nome']    = $nome;
        $v['grado']   = ($grado !== '' ? $grado : null);
        $v['is_capo'] = $is_capo;
        $found = true; break;
      }
    } unset($v);
    if (!$found) { header('Location: vigili.php?err=notfound'); exit; }

    write_vigili($vigili);
    header('Location: vigili.php?msg=mod_ok'); exit;
  }

  if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    $add = load_json(ADDESTR_JSON);
    foreach ($add as $a) { if ((int)($a['vigile_id'] ?? 0) === $id) { header('Location: vigili.php?err=ha_addestramenti'); exit; } }
    $new = array(); foreach ($vigili as $v) if ((int)($v['id'] ?? 0) !== $id) $new[] = $v;
    write_vigili($new);
    header('Location: vigili.php?msg=eliminato'); exit;
  }

  header('Location: vigili.php?err=bad_action'); exit;
}

/* ======= GET ======= */
$vigili = read_vigili();
usort($vigili, function($a,$b){
  $A = trim((isset($a['cognome'])?$a['cognome']:'').' '.(isset($a['nome'])?$a['nome']:'')); 
  $B = trim((isset($b['cognome'])?$b['cognome']:'').' '.(isset($b['nome'])?$b['nome']:'')); 
  return strcasecmp($A,$B);
});
$add = load_json(ADDESTR_JSON);
$cnt = array();
foreach ($add as $a) { $vid = (int)($a['vigile_id'] ?? 0); $cnt[$vid] = isset($cnt[$vid]) ? $cnt[$vid]+1 : 1; }

// Menu se presente
if (is_file(__DIR__.'/_menu.php')) { include __DIR__.'/_menu.php'; }

// Messaggi testo
$ERR_TXT = array(
  'campi' => 'Compila cognome e nome.',
  'grado' => 'Grado non valido. Valori ammessi: FTAV, CRV, CSV, VIG (senza punto).',
  'dup_nome' => 'Esiste già un vigile con lo stesso nome e cognome.',
  'ha_addestramenti' => 'Impossibile eliminare: il vigile ha addestramenti registrati. Disattivalo se non deve essere visualizzato.',
  'notfound' => 'Vigile non trovato.',
  'bad_action' => 'Azione non riconosciuta.',
  'forbidden' => 'Permessi insufficienti per modificare i dati.',
);
$MSG_TXT = array(
  'aggiunto'  => 'Vigile aggiunto.',
  'stato_ok'  => 'Stato aggiornato.',
  'eliminato' => 'Vigile eliminato.',
  'mod_ok'    => 'Vigile modificato.',
);

$currentUrl = $_SERVER['REQUEST_URI'] ?? 'vigili.php';
$backParam  = htmlspecialchars($currentUrl, ENT_QUOTES, 'UTF-8');

?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Gestione Vigili</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4">
  <div class="d-flex align-items-center justify-content-between">
    <h1 class="mb-0">Gestione Vigili</h1>
    <div>
      <a class="btn btn-outline-secondary" href="personale.php">← Torna</a>
    </div>
  </div>

  <?php if ($SAFE_MODE): ?>
    <div class="alert alert-warning mt-3">
      <strong>SAFE MODE attivo:</strong> permessi rigidi bypassati per debug. Rimuovi <code>?safe=1</code> quando hai finito.
    </div>
  <?php endif; ?>

  <?php if (!$can_view && !$SAFE_MODE): ?>
    <div class="alert alert-danger mt-3">
      Non hai il permesso <code>view:vigili</code>. Chiedi l'abilitazione o usa temporaneamente <code>?safe=1</code> per testare l’interfaccia.
    </div>
  <?php endif; ?>

  <?php if (!empty($err)): ?>
    <div class="alert alert-danger mt-3">
      <?php echo htmlspecialchars(isset($ERR_TXT[$err]) ? $ERR_TXT[$err] : 'Errore.'); ?>
    </div>
  <?php endif; ?>

  <?php if (!empty($msg)): ?>
    <div class="alert alert-success mt-3">
      <?php echo htmlspecialchars(isset($MSG_TXT[$msg]) ? $MSG_TXT[$msg] : 'OK.'); ?>
    </div>
  <?php endif; ?>

  <div class="row g-3 mt-3">
    <!-- Aggiungi -->
    <div class="col-lg-5">
      <div class="card shadow-sm">
        <div class="card-body">
          <h5 class="card-title">Aggiungi vigile</h5>
          <?php if ($can_edit || $SAFE_MODE): ?>
          <form method="post" class="row g-2">
            <input type="hidden" name="action" value="add">
            <div class="col-6">
              <label class="form-label">Cognome</label>
              <input type="text" name="cognome" class="form-control" required>
            </div>
            <div class="col-6">
              <label class="form-label">Nome</label>
              <input type="text" name="nome" class="form-control" required>
            </div>
            <div class="col-6">
              <label class="form-label">Grado (opz.)</label>
              <select name="grado" class="form-select">
                <option value="">— Seleziona —</option>
                <option value="FTAV">FTAV</option>
                <option value="CRV">CRV</option>
                <option value="CSV">CSV</option>
                <option value="VIG">VIG</option>
              </select>
            </div>
            <div class="col-12">
              <div class="form-check mt-2">
                <input class="form-check-input" type="checkbox" id="isCapoAdd" name="is_capo">
                <label class="form-check-label" for="isCapoAdd">Capo distaccamento</label>
              </div>
            </div>
            <div class="col-12 d-grid mt-2">
              <button class="btn btn-primary">Aggiungi</button>
            </div>
            <div class="col-12 d-grid">
              <a class="btn btn-outline-primary" href="personale.php">Modifica dati del personale già inserito 📇</a>
            </div>
          </form>
          <?php else: ?>
            <div class="alert alert-secondary">Non hai i permessi per aggiungere. (edit:vigili)</div>
          <?php endif; ?>
          <p class="text-muted small mt-2">I gradi accettati sono senza punto (es. <b>VIG</b>, non <b>VIG.</b>). Se importi dati con il punto verranno normalizzati.</p>
        </div>
      </div>
    </div>

    <!-- Elenco -->
    <div class="col-lg-7">
      <div class="card shadow-sm">
        <div class="card-body">
          <h5 class="card-title">Elenco vigili</h5>
          <table class="table table-sm align-middle">
            <thead>
              <tr>
                <th>#</th>
                <th>Vigile</th>
                <th>Grado</th>
                <th>Addestr.</th>
                <th>Stato</th>
                <th class="text-end">Azioni</th>
              </tr>
            </thead>
            <tbody>
            <?php
            $currentUrl = $_SERVER['REQUEST_URI'] ?? 'vigili.php';
            foreach ($vigili as $i=>$v):
              $id = (int)($v['id'] ?? 0);
              $nAdd   = (int)($cnt[$id] ?? 0);
              $attivo = (int)($v['attivo'] ?? 1) === 1;
              $isCapo = !empty($v['is_capo']);
              $grado  = isset($v['grado']) && $v['grado'] !== '' ? (string)$v['grado'] : '—';
              $toggleUrl = 'vigili.php?action=toggle&id='.$id.'&back='.urlencode($currentUrl.'#row-'.$id);
            ?>
              <tr id="row-<?php echo $id; ?>" class="<?php echo $attivo ? '' : 'table-secondary'; ?>">
                <td><?php echo (int)$i+1; ?></td>
                <td>
                  <?php
                    $nomeCompl = trim((isset($v['cognome'])?$v['cognome']:'').' '.(isset($v['nome'])?$v['nome']:'')); 
                    echo htmlspecialchars($nomeCompl, ENT_QUOTES, 'UTF-8');
                    if ($isCapo) echo ' <span class="badge text-bg-warning">Capo</span>';
                  ?>
                </td>
                <td><?php echo htmlspecialchars($grado, ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo $nAdd; ?></td>
                <td>
                  <?php if ($attivo): ?>
                    <span class="badge text-bg-success">Visualizzato</span>
                  <?php else: ?>
                    <span class="badge text-bg-secondary">Non Visualizzato</span>
                  <?php endif; ?>
                </td>
                <td class="text-end">
                  <?php if ($can_edit || $SAFE_MODE): ?>
                    <!-- Switch: onchange => redirect GET -->
                    <div class="form-check form-switch d-inline-block align-middle me-2">
                      <input
                        class="form-check-input"
                        type="checkbox"
                        id="sw_<?php echo $id; ?>"
                        <?php echo $attivo ? 'checked' : ''; ?>
                        onchange="this.disabled=true; window.location.href='<?php echo htmlspecialchars($toggleUrl, ENT_QUOTES, 'UTF-8'); ?>';"
                        >
                      <label class="form-check-label small" for="sw_<?php echo $id; ?>">
                        <?php echo $attivo ? 'Disattiva' : 'Attiva'; ?>
                      </label>
                    </div>

                    <!-- Fallback NO-JS -->
                    <noscript>
                      <a class="btn btn-sm btn-outline-warning" href="<?php echo htmlspecialchars($toggleUrl, ENT_QUOTES, 'UTF-8'); ?>">
                        <?php echo $attivo ? 'Disattiva' : 'Attiva'; ?>
                      </a>
                    </noscript>

                    <!-- Modifica -->
                    <button type="button"
                            class="btn btn-sm btn-outline-primary btn-edit"
                            data-id="<?php echo $id; ?>"
                            data-cognome="<?php echo htmlspecialchars($v['cognome'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                            data-nome="<?php echo htmlspecialchars($v['nome'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                            data-grado="<?php echo htmlspecialchars($v['grado'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                            data-iscapo="<?php echo $isCapo ? '1' : '0'; ?>"
                            data-bs-toggle="modal"
                            data-bs-target="#modalEdit">
                      Modifica
                    </button>

                    <!-- Elimina -->
                    <form method="post" class="d-inline" onsubmit="return confirm('Eliminare definitivamente? Azione irreversibile.');">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?php echo $id; ?>">
                      <button class="btn btn-sm btn-outline-danger" <?php echo $nAdd>0 ? 'disabled title="Ha addestramenti registrati"' : ''; ?>>Elimina</button>
                    </form>
                  <?php else: ?>
                    <span class="text-muted small">Sola lettura</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (empty($vigili)): ?>
              <tr><td colspan="6" class="text-muted">Nessun vigile inserito.</td></tr>
            <?php endif; ?>
            </tbody>
          </table>
          <p class="text-muted small">Suggerito: <strong>Disattiva</strong> per non far visualizzare il vigile negli elenchi/rapporti.</p>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Modal Modifica -->
<div class="modal fade" id="modalEdit" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="id" id="editId">
      <div class="modal-header">
        <h5 class="modal-title">Modifica vigile</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
      </div>
      <div class="modal-body">
        <div class="row g-2">
          <div class="col-6">
            <label class="form-label">Cognome</label>
            <input type="text" class="form-control" name="cognome" id="editCognome" required>
          </div>
          <div class="col-6">
            <label class="form-label">Nome</label>
            <input type="text" class="form-control" name="nome" id="editNome" required>
          </div>
          <div class="col-6">
            <label class="form-label">Grado (opz.)</label>
            <select name="grado" id="editGrado" class="form-select">
              <option value="">— Seleziona —</option>
              <option value="FTAV">FTAV</option>
              <option value="CRV">CRV</option>
              <option value="CSV">CSV</option>
              <option value="VIG">VIG</option>
            </select>
          </div>
          <div class="col-12">
            <div class="form-check mt-2">
              <input class="form-check-input" type="checkbox" id="editIsCapo" name="is_capo">
              <label class="form-check-label" for="editIsCapo">Capo distaccamento</label>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annulla</button>
        <button class="btn btn-primary"<?php echo ($can_edit||$SAFE_MODE)?'':' disabled'; ?>>Salva</button>
        <a class="btn btn-outline-primary" href="personale.php">Dati personale 📇</a>
      </div>
    </form>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Popola la modal (compatibile vecchi browser)
var btns = document.querySelectorAll('.btn-edit');
for (var i=0; i<btns.length; i++) {
  btns[i].addEventListener('click', function(){
    document.getElementById('editId').value = this.dataset.id || '';
    document.getElementById('editCognome').value = this.dataset.cognome || '';
    document.getElementById('editNome').value = this.dataset.nome || '';
    var grado = this.dataset.grado || '';
    if (grado && grado.substr(-1) === '.') grado = grado.substr(0, grado.length-1);
    if (grado === 'VV') grado = 'VIG';
    var sel = document.getElementById('editGrado');
    for (var j=0; j<sel.options.length; j++) sel.options[j].selected = (sel.options[j].value === grado);
    document.getElementById('editIsCapo').checked = (this.dataset.iscapo === '1');
  });
}
</script>
</body>
</html>
