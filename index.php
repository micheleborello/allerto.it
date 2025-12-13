<?php
// index.php – Inserimento ore addestramento (allineato a DATA_DIR come gli altri file)

ini_set('display_errors', 1);
error_reporting(E_ALL);
ob_start();

require __DIR__.'/auth.php';
require __DIR__.'/tenant_bootstrap.php'; // definisce DATA_DIR / tenant_slug ecc.
require __DIR__.'/utils.php';            // per minuti_da_intervallo_datetime()

// Login/tenant
if (function_exists('auth_is_logged_in') && !auth_is_logged_in()) { header('Location: login.php'); exit; }
if (function_exists('require_tenant_user')) { require_tenant_user(); }
if (function_exists('require_mfa')) { require_mfa(); }
if (function_exists('require_perm')) { require_perm('view:index'); require_perm('edit:index'); }

// ====== Utility locali (stesso schema di addestramenti.php / edit_addestramento.php)
if (!defined('DATA_DIR')) { define('DATA_DIR', __DIR__.'/data'); if (!is_dir(DATA_DIR)) @mkdir(DATA_DIR, 0775, true); }
function _path(string $file): string { $file=ltrim($file,'/'); return rtrim(DATA_DIR,'/').'/'.$file; }
function _read_json(string $abs): array { if (!is_file($abs)) return []; $s=@file_get_contents($abs); if ($s===false) return []; $j=json_decode($s,true); return is_array($j)?$j:[]; }
function _write_json(string $abs, array $data): void {
  $dir=dirname($abs); if(!is_dir($dir)) @mkdir($dir,0775,true);
  $json=json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
  if($json===false) throw new RuntimeException(json_last_error_msg());
  $tmp=$abs.'.tmp-'.bin2hex(random_bytes(6));
  if(file_put_contents($tmp,$json)===false) throw new RuntimeException('scrittura tmp fallita');
  if(!@rename($tmp,$abs)){ @unlink($tmp); throw new RuntimeException('rename fallito'); }
  @chmod($abs,0664);
}
function load_vigili_local(): array { $p = defined('VIGILI_JSON') ? VIGILI_JSON : _path('vigili.json'); return _read_json($p); }
function load_addestramenti_local(): array { $p = defined('ADDESTRAMENTI_JSON') ? ADDESTRAMENTI_JSON : _path('addestramenti.json'); return _read_json($p); }
function load_infortuni_local(): array { $p = defined('INFORTUNI_JSON') ? INFORTUNI_JSON : _path('infortuni.json'); return _read_json($p); }
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// ====== Sessione + CSRF
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['_csrf_addestramenti'])) $_SESSION['_csrf_addestramenti'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['_csrf_addestramenti'];

// ====== Menu opzionale
$menuPath = __DIR__.'/_menu.php';
$hasMenu  = is_file($menuPath);

// ====== Ruolo
$isCapo = function_exists('auth_can') ? (bool)auth_can('capo:*') : true;

// ====== Caso superadmin senza tenant attivo
$needTenant = function_exists('auth_is_superadmin') && auth_is_superadmin() && empty($_SESSION['tenant_slug']);
if ($needTenant) {
  $caserme = function_exists('load_caserme') ? load_caserme() : [];
  ?>
  <!doctype html><html lang="it"><head><meta charset="utf-8"><title>Seleziona Distaccamento</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head>
  <body class="bg-light"><div class="container py-5">
    <h1 class="mb-3">Seleziona Distaccamento</h1>
    <?php if (empty($caserme)): ?>
      <div class="alert alert-warning">Nessun Distaccamento definito. Vai su <a href="caserme.php">Impostazioni → Distaccamento</a> e creane uno.</div>
    <?php else: ?>
      <form method="post" action="caserme.php" class="row g-2">
        <input type="hidden" name="action" value="activate">
        <div class="col-auto">
          <select name="slug" class="form-select" required>
            <option value="" disabled selected>— scegli —</option>
            <?php foreach ($caserme as $r): ?>
              <option value="<?= h($r['slug']) ?>"><?= h($r['name']) ?> (<?= h($r['slug']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-auto"><button class="btn btn-primary">Attiva</button></div>
        <div class="col-12 mt-3"><a class="btn btn-link" href="caserme.php">Gestione Distaccamento</a></div>
      </form>
    <?php endif; ?>
  </div></body></html><?php
  exit;
}

// ====== Catalogo attività (supporta formato nuovo/vecchio)
if (!defined('ATTIVITA_JSON')) define('ATTIVITA_JSON', _path('attivita.json'));
if (!file_exists(ATTIVITA_JSON)) { @file_put_contents(ATTIVITA_JSON, "[]"); }
const TIPI_ATT = ['pratica','teoria','varie'];
function norm_tipo($t){ $t=strtolower(trim((string)$t)); return in_array($t, TIPI_ATT, true)?$t:'varie'; }
function load_attivita_catalogo_index(): array {
  $rows = _read_json(ATTIVITA_JSON); if (!is_array($rows)) $rows=[];
  $out=[];
  foreach ($rows as $x) {
    if (is_string($x)) {
      $lab=trim($x); if($lab!=='') $out[]=['label'=>$lab,'tipo'=>'varie'];
    } elseif (is_array($x)) {
      $lab=trim((string)($x['label']??'')); $tp=norm_tipo($x['tipo']??'varie');
      if($lab!=='') $out[]=['label'=>$lab,'tipo'=>$tp];
    }
  }
  $seen=[]; $clean=[];
  foreach($out as $r){ $k=mb_strtolower($r['label'],'UTF-8'); if(isset($seen[$k])) continue; $seen[$k]=1; $clean[]=$r; }
  usort($clean, fn($a,$b)=> strcasecmp($a['label'],$b['label']));
  return $clean;
}
$catalogoAttFull = load_attivita_catalogo_index();
$catalogoAttByTipo = ['pratica'=>[], 'teoria'=>[], 'varie'=>[]];
foreach ($catalogoAttFull as $r) { $catalogoAttByTipo[$r['tipo']][] = $r['label']; }

// ====== Infortuni JSON
if (!defined('INFORTUNI_JSON')) define('INFORTUNI_JSON', _path('infortuni.json'));
if (!file_exists(INFORTUNI_JSON)) { @file_put_contents(INFORTUNI_JSON, "[]"); }

// ====== Carico VIGILI (prima del debug!) e ordino
$vigili = sanitize_vigili_list(load_vigili_local());
if (!is_array($vigili)) $vigili = [];
usort($vigili, fn($a,$b)=>strcmp(
  trim(($a['cognome']??'').' '.($a['nome']??'')),
  trim(($b['cognome']??'').' '.($b['nome']??'')))
);

// ====== Finestra mobile (12 mesi prima del mese selezionato in toolbar)
$meseSelezionato = $_GET['mese'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $meseSelezionato)) $meseSelezionato = date('Y-m');
$rifY = (int)substr($meseSelezionato,0,4);
$rifM = (int)substr($meseSelezionato,5,2);
$startRef = DateTime::createFromFormat('Y-m-d', sprintf('%04d-%02d-01', $rifY, $rifM));
$winStart = (clone $startRef)->modify('-1 year');
$winEnd   = (clone $startRef)->modify('-1 day');
$winStartStr = $winStart->format('Y-m-d');
$winEndStr   = $winEnd->format('Y-m-d');

// ====== Banca/eccedenza sulla finestra mobile
$add = load_addestramenti_local(); if (!is_array($add)) $add=[];
$nonrec = []; $rec = [];
$minutiRecord = function(array $r): int {
  if (isset($r['minuti'])) return (int)$r['minuti'];
  $inizioDT = $r['inizio_dt'] ?? (($r['data'] ?? '').'T'.substr((string)($r['inizio'] ?? '00:00'),0,5).':00');
  $fineDT   = $r['fine_dt']   ?? (($r['data'] ?? '').'T'.substr((string)($r['fine']   ?? '00:00'),0,5).':00');
  try { return minuti_da_intervallo_datetime($inizioDT,$fineDT); } catch(Throwable $e){ return 0; }
};
foreach ($add as $r) {
  if (!is_array($r)) continue;
  $vid=(int)($r['vigile_id']??0); $d=(string)($r['data']??''); if(!$vid||!$d) continue;
  if ($d < $winStartStr || $d > $winEndStr) continue;
  $min = $minutiRecord($r);
  if ((int)($r['recupero'] ?? 0) === 1) $rec[$vid] = ($rec[$vid] ?? 0) + $min;
  else                                  $nonrec[$vid] = ($nonrec[$vid] ?? 0) + $min;
}
$ECCESSO = [];
foreach ($vigili as $v) {
  if ((int)($v['attivo'] ?? 1) !== 1) continue;
  $vid = (int)($v['id'] ?? 0); if(!$vid) continue;
  $nr=(int)($nonrec[$vid] ?? 0); $rc=(int)($rec[$vid] ?? 0);
  $bank = max(0, $nr - 60*60) - $rc; // 60 ore = 3600 min
  if ($bank < 0) $bank = 0;
  $ECCESSO[$vid] = $bank;
}

// ====== Infortuni (periodi)
$INFORTUNI = [];
$infortuniRaw = load_infortuni_local();
if (is_array($infortuniRaw)) {
  foreach ($infortuniRaw as $row) {
    $vid=(int)($row['vigile_id']??0);
    $dal=(string)($row['dal']??''); $al=(string)($row['al']??'');
    if ($vid && preg_match('/^\d{4}-\d{2}-\d{2}$/',$dal) && preg_match('/^\d{4}-\d{2}-\d{2}$/',$al)) {
      $INFORTUNI[$vid][] = [$dal,$al];
    }
  }
}

// ====== HTML/JS ======
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Ore addestramento – Inserimento</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  select.long-options { width: 100%; max-width: 100%; }
  select.long-options option{ white-space: normal; word-break: break-word; overflow-wrap: anywhere; }
  .form-check-label.text-danger { font-weight: 600; }
  .form-check-input.is-invalid { outline: 2px solid rgba(220,53,69,.6); }
  .form-check-label.text-success { font-weight: 600; }
  .form-check-input.is-valid { outline: 2px solid rgba(25,135,84,.6); }
  .form-check-input:disabled + .form-check-label { opacity: .65; }
</style>
</head>
<body class="bg-light">
<?php if ($hasMenu) include $menuPath; ?>

<div class="container py-4">

  <!-- TOOLBAR -->
  <div class="sticky-top bg-white border rounded-3 p-2 mb-3" style="top:.5rem; box-shadow:0 2px 8px rgba(0,0,0,.03);">
    <div class="d-flex flex-wrap align-items-center gap-2">
      <a class="btn btn-outline-dark" href="riepilogo.php">Riepiloghi ▶</a>
      <a class="btn btn-outline-primary" href="addestramenti.php">Dettagli e Modifiche ✎</a>

      <form action="report_operativita_tcpdf.php" method="get" target="_blank" class="ms-auto d-flex align-items-center gap-2">
        <label for="meseToolbar" class="form-label mb-0 fw-semibold">Mese</label>
        <input type="month" id="meseToolbar" name="mese" value="<?= h($meseSelezionato) ?>" class="form-control form-control-sm">
        <button type="submit" class="btn btn-sm btn-danger fw-semibold">PDF Operatività</button>
      </form>
    </div>
    <script>
      window.ECCESSO   = <?= json_encode($ECCESSO, JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
      window.INFORTUNI = <?= json_encode($INFORTUNI, JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
    </script>
  </div>

  <h1 class="mb-4">Inserimento ore addestramento</h1>

  <form id="form-inserimento" method="post" action="salva_sessione.php">
    <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
    <?php if ($isCapo): ?>
      <!-- FLAG: l’UI è da CAPO, il server può salvare i presenti -->
      <input type="hidden" name="is_capo_ui" value="1">
    <?php endif; ?>

    <div class="row g-3">
      <!-- SINISTRA -->
      <div class="col-lg-6">
        <div class="card shadow-sm">
          <div class="card-body">
            <h5 class="card-title">Nuovo addestramento</h5>
            <div class="row g-2">

              <div class="col-6">
                <label class="form-label">Inizio (data e ora)</label>
                <input id="inizio_dt" type="datetime-local" name="inizio_dt" class="form-control" required>
              </div>
              <div class="col-6">
                <label class="form-label">Fine (data e ora)</label>
                <input id="fine_dt" type="datetime-local" name="fine_dt" class="form-control" required>
              </div>

              <div class="col-12 form-check mt-1">
                <input class="form-check-input" type="checkbox" id="chkRec" name="recupero" value="1">
                <label class="form-check-label fw-semibold" for="chkRec">Addestramento di recupero</label>
                <div class="form-text">Se attivo, le ore saranno scalate dalle eccedenze (ultimi 12 mesi prima del mese selezionato).</div>
              </div>

              <!-- Attività -->
              <div class="col-12">
                <label class="form-label mb-1">Attività</label>
                <select class="form-select long-options" name="attivita_select" id="i_att_sel" required>
                  <option value="">— Seleziona dal programma —</option>
                  <?php foreach (['pratica'=>'Pratica','teoria'=>'Teoria','varie'=>'Varie'] as $k=>$titolo): ?>
                    <?php if (!empty($catalogoAttByTipo[$k])): ?>
                      <optgroup label="<?= h($titolo) ?>">
                        <?php foreach ($catalogoAttByTipo[$k] as $lbl): ?>
                          <option value="<?= h($lbl) ?>" title="<?= h($lbl) ?>"><?= h($lbl) ?></option>
                        <?php endforeach; ?>
                      </optgroup>
                    <?php endif; ?>
                  <?php endforeach; ?>
                  <option value="__ALTRO__">— Altro (scrivi sotto) —</option>
                </select>

                <input type="text" class="form-control mt-2 d-none" id="i_att_custom" name="attivita_custom" placeholder="Nuova attività…">

                <div class="row g-2 align-items-center mt-2">
                  <div class="col-auto">
                    <div class="form-check">
                      <input class="form-check-input" type="checkbox" id="i_add_prog" name="add_to_program" value="1">
                      <label class="form-check-label" for="i_add_prog">Aggiungi l’attività al programma</label>
                    </div>
                  </div>
                  <div class="col-auto d-none" id="i_tipo_altro_wrap">
                    <select class="form-select form-select-sm" name="attivita_tipo" id="i_att_tipo">
                      <option value="pratica">Pratica</option>
                      <option value="teoria">Teoria</option>
                      <option value="varie">Varie</option>
                    </select>
                  </div>
                </div>
              </div>

              <div class="col-12">
                <label class="form-label">Formatore e Note (opz.)</label>
                <textarea name="note" class="form-control" rows="2"></textarea>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- DESTRA -->
      <div class="col-lg-6">
        <div class="card shadow-sm">
          <div class="card-body">
            <?php if ($isCapo): ?>
              <h5 class="card-title">Seleziona i presenti</h5>

              <div class="d-flex gap-3 flex-wrap">
                <div class="flex-grow-1">
                  <div class="border rounded p-2" style="max-height: 280px; overflow:auto;">
                    <?php foreach ($vigili as $v):
                      if ((int)($v['attivo'] ?? 1) !== 1) continue;
                      $id    = (int)($v['id'] ?? 0); if(!$id) continue;
                      $grado = $v['grado'] ?? '';
                      $label = h(trim(($grado ? "[$grado] " : '').($v['cognome']??'').' '.($v['nome']??'')));
                    ?>
                      <div class="form-check">
                        <input class="form-check-input chk-presente" type="checkbox" value="<?= $id ?>" id="v<?= $id ?>" name="vigili_presenti[]">
                        <label class="form-check-label" for="v<?= $id ?>">
                          <?= $label ?>
                          <small class="badge rounded-pill text-bg-info-subtle d-none badge-eccesso" data-vid="<?= $id ?>"></small>
                          <small class="badge rounded-pill text-bg-danger-subtle d-none badge-infortunio" data-vid="<?= $id ?>">Infortunio</small>
                        </label>
                      </div>
                    <?php endforeach; ?>
                  </div>
                  <div class="mt-2 d-flex gap-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btnSelezionaTutti">Seleziona tutti</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btnDeselezionaTutti">Deseleziona tutti</button>
                  </div>

                  <div class="mt-2 text-muted small">
                    <span class="me-3">Legenda:</span>
                    <span class="fw-semibold text-success">Verde</span> = banca ore sufficiente •
                    <span class="fw-semibold text-danger">Rosso</span> = banca ore insufficiente •
                    <span class="fw-semibold">Badge “Infortunio”</span> = non selezionabile nella data
                  </div>
                </div>

                <div style="min-width:260px;">
                  <div class="row g-2">
                    <div class="col-12">
                      <div class="card">
                        <div class="card-header py-2"><strong>Presenti</strong></div>
                        <div class="card-body" style="max-height:120px; overflow:auto;">
                          <ul id="listaPresenti" class="mb-0 small"></ul>
                        </div>
                      </div>
                    </div>
                    <div class="col-12">
                      <div class="card">
                        <div class="card-header py-2"><strong>Assenti</strong></div>
                        <div class="card-body" style="max-height:120px; overflow:auto;">
                          <ul id="listaAssenti" class="mb-0 small"></ul>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>

              </div>

              <small class="text-muted d-block mt-2">Gli assenti sono automaticamente tutti quelli non spuntati.</small>
            <?php else: ?>
              <h5 class="card-title">Presenze tramite QR</h5>
              <div class="alert alert-info">
                Le presenze verranno registrate dai vigili tramite QR.
                Crea l’addestramento (inizio/fine/attività) e ti si aprirà il QR da far scansionare.
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- SUBMIT -->
      <div class="col-12">
        <div class="d-grid">
          <button class="btn btn-primary">Salva addestramento</button>
        </div>
      </div>
    </div>
  </form>

<script>
// Toolbar mese ↔ URL
(function(){
  const meseTb = document.getElementById('meseToolbar');
  if (!meseTb) return;
  const qs = new URLSearchParams(location.search);
  if (qs.get('mese')) meseTb.value = qs.get('mese');
  meseTb.addEventListener('change', () => {
    if (meseTb.value) location.href = 'index.php?mese=' + encodeURIComponent(meseTb.value);
  });
})();
</script>

<script>
// Toggle "Altro" + tipo
(function(){
  const sel  = document.getElementById('i_att_sel');
  const txt  = document.getElementById('i_att_custom');
  const chk  = document.getElementById('i_add_prog');
  const wrap = document.getElementById('i_tipo_altro_wrap');
  function toggleAltro(){
    if (!sel||!txt) return;
    if (sel.value==='__ALTRO__'){ txt.classList.remove('d-none'); if(wrap) wrap.classList.toggle('d-none', !(chk&&chk.checked)); }
    else { txt.classList.add('d-none'); txt.value=''; if(wrap) wrap.classList.add('d-none'); }
  }
  function toggleTipo(){ if(!wrap) return; const show=(sel&&sel.value==='__ALTRO__'&&chk&&chk.checked); wrap.classList.toggle('d-none', !show); }
  sel?.addEventListener('change', ()=>{toggleAltro();toggleTipo();});
  chk?.addEventListener('change', toggleTipo);
  toggleAltro(); toggleTipo();
})();
</script>

<?php if ($isCapo): ?>
<script>
// Presenze + colori in base a ECCESSO/INFORTUNI
(function(){
  const chk = Array.from(document.querySelectorAll('.chk-presente'));
  const lp  = document.getElementById('listaPresenti');
  const la  = document.getElementById('listaAssenti');
  const inizio = document.getElementById('inizio_dt');
  const fine   = document.getElementById('fine_dt');

  const ECCESSO   = window.ECCESSO || {};
  const INFORTUNI = window.INFORTUNI || {};

  function fmt(min){ const h=Math.floor(min/60), m=Math.max(0,min%60); return h+':'+String(m).padStart(2,'0'); }
  function parseDt(v){ if(!v) return null; const s=(v.length===16)?v+':00':v; const d=new Date(s.replace('T',' ')); return isNaN(d.getTime())?null:d; }
  function sessionMinutes(){ const t1=parseDt(inizio?.value), t2=parseDt(fine?.value); if(!t1||!t2) return null; const diff=(t2-t1)/60000; return (diff>0)?Math.round(diff):null; }
  function datePart(v){ return v ? v.split('T')[0] : null; }
  function infortunioCheck(vid, ymd){ const ranges=INFORTUNI[vid]||[]; if(!ymd) return false; for(const [dal,al] of ranges){ if(ymd>=dal && ymd<=al) return true; } return false; }

  function refreshLists(){
    if (!lp||!la) return; lp.innerHTML=''; la.innerHTML='';
    chk.forEach(c=>{ const label = c.nextElementSibling?.innerText || ('ID '+c.value); const li=document.createElement('li'); li.textContent=label; (c.checked?lp:la).appendChild(li); });
  }
  function pulisciRiga(c){
    c.classList.remove('is-invalid','is-valid');
    const lab=document.querySelector(`label[for="${c.id}"]`);
    lab?.classList.remove('text-danger','text-success');
    const b = lab? lab.querySelector('.badge-eccesso[data-vid="'+c.value+'"]') : null;
    const bi= lab? lab.querySelector('.badge-infortunio[data-vid="'+c.value+'"]') : null;
    if (b){ b.classList.add('d-none'); b.classList.remove('text-danger'); b.textContent=''; }
    if (bi){ bi.classList.add('d-none'); }
    c.disabled=false;
  }
  function refreshColori(){
    const need = sessionMinutes();
    const ymdStart = datePart(inizio?.value);
    chk.forEach(pulisciRiga);
    if (need===null || !ymdStart) return;

    chk.forEach(c=>{
      const vid=parseInt(c.value,10);
      const lab=document.querySelector(`label[for="${c.id}"]`);
      const b  = lab? lab.querySelector('.badge-eccesso[data-vid="'+vid+'"]') : null;
      const bi = lab? lab.querySelector('.badge-infortunio[data-vid="'+vid+'"]') : null;

      if (infortunioCheck(vid, ymdStart)) {
        c.checked=false; c.disabled=true; if(bi) bi.classList.remove('d-none'); return;
      }
      const bank=parseInt(ECCESSO[vid]||0,10);
      if (b){ b.classList.remove('d-none'); b.textContent=`ecc.: ${fmt(bank)} / ${fmt(need)}`; if(bank<need) b.classList.add('text-danger'); }
      if (c.checked){
        if (bank<need){ c.classList.add('is-invalid'); lab?.classList.add('text-danger'); }
        else          { c.classList.add('is-valid');   lab?.classList.add('text-success'); }
      }
    });
  }

  chk.forEach(c => c.addEventListener('change', ()=>{ refreshLists(); refreshColori(); }));
  [inizio, fine].forEach(el => el && el.addEventListener('input', refreshColori));
  document.getElementById('btnSelezionaTutti')?.addEventListener('click', ()=>{ chk.forEach(c=>{ if(!c.disabled) c.checked=true; }); refreshLists(); refreshColori(); });
  document.getElementById('btnDeselezionaTutti')?.addEventListener('click', ()=>{ chk.forEach(c=> c.checked=false); refreshLists(); refreshColori(); });

  refreshLists(); refreshColori();

  // Sync mese toolbar quando scegli l'inizio
  const meseTb = document.getElementById('meseToolbar');
  if (inizio && meseTb) {
    inizio.addEventListener('change', () => {
      if (!inizio.value) return;
      const d = inizio.value.split('T')[0];
      if (d) meseTb.value = d.slice(0,7);
    });
  }
})();
</script>
<?php endif; ?>
</div>
</body>
</html>
