<?php
// riepilogo_mensile_pdf_builder.php — genera i bytes PDF del riepilogo mensile

require_once __DIR__.'/tenant_bootstrap.php';
require_once __DIR__.'/storage.php';
require_once __DIR__.'/utils.php';
require_once __DIR__.'/lib/tcpdf/tcpdf.php';

if (!function_exists('build_riepilogo_mensile_pdf')) {
function build_riepilogo_mensile_pdf(string $meseStr): array {
  if (!preg_match('/^\d{4}-\d{2}$/', $meseStr)) {
    throw new InvalidArgumentException('Parametro mese non valido (YYYY-MM).');
  }

  // Dati tenant (nome caserma)
  $slug = tenant_active_slug();
  $casermaName = 'Default';
  foreach (load_caserme() as $c) {
    if (($c['slug'] ?? '') === $slug) { $casermaName = $c['name'] ?? $slug; break; }
  }

  $vigili = array_values(array_filter(load_json(VIGILI_JSON), fn($v)=> (int)($v['attivo'] ?? 1)===1));
  usort($vigili, fn($a,$b)=>strcasecmp(($a['cognome']??'').' '.($a['nome']??''), ($b['cognome']??'').' '.($b['nome']??'')));
  $add = load_json(ADDESTR_JSON);

  // Infortuni
  $INFORTUNI = [];
  $infRaw = load_json(INFORTUNI_JSON);
  if (is_array($infRaw)) {
    foreach ($infRaw as $row) {
      $vid = (int)($row['vigile_id'] ?? 0);
      $dal = (string)($row['dal'] ?? '');
      $al  = (string)($row['al'] ?? '');
      if ($vid && preg_match('/^\d{4}-\d{2}-\d{2}$/',$dal) && preg_match('/^\d{4}-\d{2}-\d{2}$/',$al) && $al >= $dal) {
        $INFORTUNI[$vid][] = [$dal,$al];
      }
    }
  }

  // Periodo mese
  $rifY = (int)substr($meseStr,0,4);
  $rifM = (int)substr($meseStr,5,2);
  $dt0  = DateTime::createFromFormat('Y-m-d', sprintf('%04d-%02d-01', $rifY, $rifM));
  $dt1  = (clone $dt0)->modify('last day of this month');
  $periodStart = $dt0->format('Y-m-d');
  $periodEnd   = $dt1->format('Y-m-d');

  // Minuti robusti
  $minutiRecord = function(array $r): int {
    if (isset($r['minuti']) && $r['minuti']!=='') return (int)$r['minuti'];
    $data = (string)($r['data'] ?? '');
    $inizioDT = $r['inizio_dt'] ?? ($data ? ($data.'T'.substr((string)($r['inizio'] ?? '00:00'),0,5).':00') : null);
    $fineDT   = $r['fine_dt']   ?? ($data ? ($data.'T'.substr((string)($r['fine']   ?? '00:00'),0,5).':00') : null);
    if (!$inizioDT || !$fineDT) return 0;
    try { return minuti_da_intervallo_datetime($inizioDT, $fineDT); } catch (\Throwable $e) { return 0; }
  };

  $estraiData = function(array $r): string {
    if (!empty($r['data'])) return (string)$r['data'];
    if (!empty($r['inizio_dt']) && preg_match('/^\d{4}-\d{2}-\d{2}T/',$r['inizio_dt'])) {
      return substr($r['inizio_dt'], 0, 10);
    }
    return '';
  };

  // Prorata infortuni (per mese)
  $daysBetween = function(string $A, string $B): int {
    $dA = DateTime::createFromFormat('Y-m-d', $A);
    $dB = DateTime::createFromFormat('Y-m-d', $B);
    if (!$dA || !$dB) return 0;
    if ($dB < $dA) return 0;
    return (int)$dA->diff($dB)->days + 1;
  };
  $overlapDays = function(string $aStart, string $aEnd, string $bStart, string $bEnd) use ($daysBetween): int {
    $start = max($aStart, $bStart);
    $end   = min($aEnd,   $bEnd);
    if ($end < $start) return 0;
    return $daysBetween($start, $end);
  };
  $QUOTA_MENSILE_MIN = 5 * 60;

  $soglia_prorata_min = function(
    int $baseMin, array $rangesInfortunio, string $pStart, string $pEnd
  ) use ($overlapDays, $daysBetween): int {
    $totDays = $daysBetween($pStart, $pEnd);
    if ($totDays <= 0) return 0;
    $infDays = 0;
    foreach ($rangesInfortunio as $r) {
      $dal = $r[0] ?? null; $al = $r[1] ?? null;
      if (!$dal || !$al) continue;
      $infDays += $overlapDays($pStart, $pEnd, $dal, $al);
    }
    if ($infDays > $totDays) $infDays = $totDays;
    $attivi = $totDays - $infDays;
    $ratio  = ($attivi <= 0) ? 0.0 : ($attivi / $totDays);
    $effMin = (int) round($baseMin * $ratio);
    return max(0, min($effMin, $baseMin));
  };

  // Accumuli del mese
  $minutiPerVigile = [];
  $sessioniPerVigile = [];
  foreach ($add as $a) {
    $d = $estraiData($a);
    if ($d === '' || $d < $periodStart || $d > $periodEnd) continue;
    $vid = (int)($a['vigile_id'] ?? 0);
    if ($vid <= 0) continue;
    $m = $minutiRecord($a);
    $minutiPerVigile[$vid]   = ($minutiPerVigile[$vid]   ?? 0) + $m;
    $sessioniPerVigile[$vid] = ($sessioniPerVigile[$vid] ?? 0) + 1;
  }

  $h_ore_min = fn(int $m) => sprintf('%d:%02d', intdiv($m,60), $m%60);

  // ===== TCPDF =====
  $pdf = new \TCPDF('P','mm','A4', true, 'UTF-8', false);
  $pdf->SetCreator('Sistema Ore Addestramento');
  $pdf->SetAuthor('Sistema');
  $pdf->SetTitle('Riepilogo mensile '.$meseStr.' — '.$casermaName);
  $pdf->SetMargins(15, 20, 15);
  $pdf->SetAutoPageBreak(true, 20);
  $pdf->setPrintHeader(false);
  $pdf->setPrintFooter(false);
  $pdf->SetFont('helvetica','',10);
  $pdf->AddPage();

  $pdf->SetFont('helvetica','B',12);
  $pdf->Cell(0, 0, 'Riepilogo mensile addestramenti', 0, 1, 'C');
  $pdf->Ln(2);
  $pdf->SetFont('helvetica','',10);
  $pdf->Cell(0, 0, 'Distaccamento di '.$casermaName, 0, 1, 'C');
  $pdf->Ln(2);
  $pdf->SetFont('helvetica','B',11);
  $pdf->Cell(0, 0, 'Mese: '.$meseStr, 0, 1, 'C');
  $pdf->Ln(6);

  $nota = 'Le soglie sono ridotte proporzionalmente escludendo i giorni coperti da infortunio nel mese (base: 5h).';
  $pdf->SetFont('helvetica','',9);
  $pdf->MultiCell(0, 0, $nota, 0, 'C', false, 1);
  $pdf->Ln(3);

  $rows = '';
  foreach ($vigili as $i => $v) {
    $vid = (int)($v['id'] ?? 0);
    $min = (int)($minutiPerVigile[$vid] ?? 0);
    $n   = (int)($sessioniPerVigile[$vid] ?? 0);
    $ranges = $INFORTUNI[$vid] ?? [];
    $sogliaEffMin = $soglia_prorata_min($QUOTA_MENSILE_MIN, $ranges, $periodStart, $periodEnd);
    $ok = ($min >= $sogliaEffMin);

    $nome = htmlspecialchars(trim(($v['cognome']??'').' '.($v['nome']??'')), ENT_QUOTES, 'UTF-8');
    $rows .= '<tr>
      <td width="6%"  align="right">'.($i+1).'</td>
      <td width="34%" align="left">'.$nome.'</td>
      <td width="12%" align="right">'.$n.'</td>
      <td width="18%" align="right">'.$h_ore_min($min).'</td>
      <td width="18%" align="right"><span style="color:#555;">'.$h_ore_min($sogliaEffMin).'</span></td>
      <td width="12%" align="center"><b>'.($ok ? 'OK' : 'SOTTO').'</b></td>
    </tr>';
  }
  if ($rows === '') {
    $rows = '<tr><td colspan="6" align="center">Nessun vigile attivo.</td></tr>';
  }

  $html = '
  <table cellpadding="5" cellspacing="0" border="1" width="100%">
    <thead>
      <tr style="background:#f2f2f2; font-weight:bold;">
        <th width="6%"  align="right">#</th>
        <th width="34%" align="left">Vigile</th>
        <th width="12%" align="right">Addestr.</th>
        <th width="18%" align="right">Totale (h:mm)</th>
        <th width="18%" align="right">Soglia eff.</th>
        <th width="12%" align="center">Stato</th>
      </tr>
    </thead>
    <tbody>'.$rows.'</tbody>
  </table>';

  $pdf->writeHTML($html, true, false, true, false, '');
  $bytes = $pdf->Output('', 'S');
  $fname = 'riepilogo_mensile_'.$meseStr.'.pdf';
  return [$fname, $bytes];
}}