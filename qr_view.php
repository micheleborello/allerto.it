<?php
// qr_view.php — mostra il QR della sessione di addestramento
// Requisiti: tenant_bootstrap.php, presenza di una sessione con ?uid=... ; opzionale ?slug=...
require_once __DIR__ . '/tenant_bootstrap.php';

// --- input ---
$uid  = isset($_GET['uid'])  ? trim((string)$_GET['uid'])  : '';
$slug = isset($_GET['slug']) ? trim((string)$_GET['slug']) : tenant_active_slug();

if ($uid === '') {
  http_response_code(400);
  echo "UID di sessione mancante.";
  exit;
}

// Base URL assoluto (tenta autorilevazione); se hai un BASE_URL costante, usa quella.
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$base   = rtrim($scheme . '://' . $host . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\'), '/');

// Link di checkin con slug (fondamentale per funzionare senza login)
$checkinUrl = $base . '/checkin.php?uid=' . rawurlencode($uid) . '&slug=' . rawurlencode($slug);

// Prova a leggere qualche info della sessione (facoltativo, solo display)
$sessionTitle = 'Sessione addestramento';
try {
  $arr = json_decode(@file_get_contents(ADDESTR_JSON), true);
  if (is_array($arr)) {
    foreach ($arr as $r) {
      if (($r['type'] ?? '') === 'sessione' && ($r['sessione_uid'] ?? '') === $uid) {
        $sessionTitle = trim((string)($r['titolo'] ?? 'Sessione addestramento'));
        break;
      }
    }
  }
} catch (Throwable $e) {
  /* display soft */
}

?><!doctype html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <title>QR Presenza — <?php echo htmlspecialchars($sessionTitle, ENT_QUOTES); ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    :root { color-scheme: light dark; }
    body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin: 24px; }
    h1 { margin: 0 0 12px 0; font-size: 22px; }
    .sub { opacity: .7; margin-bottom: 20px; }
    .qr-wrap { display: grid; place-items: center; margin: 20px 0; }
    #qrcode { width: 320px; height: 320px; }
    .link { word-break: break-all; font-size: 14px; opacity: .85; }
    .actions { margin-top: 24px; display: flex; gap: 12px; flex-wrap: wrap; }
    button, a.btn {
      padding: 10px 14px; border-radius: 10px; border: 0; cursor: pointer;
      background: #0b5ed7; color: #fff; text-decoration: none; display: inline-block;
    }
    .note { margin-top: 12px; font-size: 14px; opacity: .8; }
  </style>
</head>
<body>
  <h1><?php echo htmlspecialchars(tenant_active_name(), ENT_QUOTES); ?></h1>
  <div class="sub">
    <strong><?php echo htmlspecialchars($sessionTitle, ENT_QUOTES); ?></strong><br>
    UID: <code><?php echo htmlspecialchars($uid, ENT_QUOTES); ?></code>
  </div>

  <div class="qr-wrap">
    <div id="qrcode" aria-label="QR code per check-in"></div>
  </div>

  <div class="link">
    Link check-in: <a href="<?php echo htmlspecialchars($checkinUrl, ENT_QUOTES); ?>"><?php echo htmlspecialchars($checkinUrl, ENT_QUOTES); ?></a>
  </div>

  <div class="actions">
    <a class="btn" href="addestramenti.php">Torna alla lista addestramenti</a>
    <a class="btn" href="<?php echo htmlspecialchars($checkinUrl, ENT_QUOTES); ?>">Apri check-in (debug)</a>
  </div>

  <p class="note">
    I vigili inquadrano questo QR con il proprio telefono. Si apre il link di check-in, scelgono il loro nome e inseriscono il PIN personale.
  </p>

  <!-- QRCode.js (senza build tools) -->
  <script>
    // caricamento rapido di qrcode.js da CDN jsDelivr
    (function loadQR(cb){
      var s = document.createElement('script');
      s.src = 'https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js';
      s.onload = cb; document.head.appendChild(s);
    })(function(){
      new QRCode(document.getElementById("qrcode"), {
        text: <?php echo json_encode($checkinUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>,
        width: 320, height: 320, correctLevel: QRCode.CorrectLevel.H
      });
    });
  </script>
</body>
</html>