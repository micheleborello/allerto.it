<?php
// api_totali_annuali.php
require __DIR__.'/storage.php';

header('Content-Type: application/json; charset=utf-8');
// Se devi chiamarla da un’app web/Flutter su altro dominio, sblocca CORS:
// header('Access-Control-Allow-Origin: *');

try {
  $anno = isset($_GET['anno']) ? (int)$_GET['anno'] : (int)date('Y');
  if ($anno < 2000 || $anno > 2100) {
    throw new InvalidArgumentException('Anno non valido');
  }

  $vigili = array_values(array_filter(load_json(VIGILI_JSON), fn($v)=> (int)($v['attivo'] ?? 1) === 1));
  $add    = load_json(ADDESTR_JSON);

  $minutiPerVigile    = [];
  $sessioniPerVigile  = [];

  foreach ($add as $a) {
    $y = (int)substr((string)($a['data'] ?? ''), 0, 4);
    if ($y !== $anno) continue;
    $vid = (int)($a['vigile_id'] ?? 0);
    $minutiPerVigile[$vid]   = ($minutiPerVigile[$vid] ?? 0) + (int)($a['minuti'] ?? 0);
    $sessioniPerVigile[$vid] = ($sessioniPerVigile[$vid] ?? 0) + 1;
  }

  usort($vigili, fn($a,$b)=>strcmp($a['cognome'].' '.$a['nome'], $b['cognome'].' '.$b['nome']));

  $out = [];
  foreach ($vigili as $v) {
    $vid = (int)$v['id'];
    $min = (int)($minutiPerVigile[$vid] ?? 0);
    $out[] = [
      'id'             => $vid,
      'cognome'        => (string)$v['cognome'],
      'nome'           => (string)$v['nome'],
      'minuti_annuali' => $min,
      'n_addestramenti'     => (int)($sessioniPerVigile[$vid] ?? 0),
      // bonus utili lato client:
      'ore_hhmm'       => sprintf('%d:%02d', intdiv($min,60), $min%60),
      'ok_60h'         => $min >= 60*60,
    ];
  }

  echo json_encode([
    'anno'          => $anno,
    'soglia_minuti' => 60*60,
    'data'          => $out,
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode([
    'error'   => true,
    'message' => $e->getMessage(),
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}