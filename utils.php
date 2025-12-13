<?php
// utils.php

function minuti_da_orari(string $inizio, string $fine): int {
  if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $inizio) || !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $fine)) {
    throw new InvalidArgumentException('Formato orario invalido. Usa HH:MM o HH:MM:SS');
  }
  $base = '2000-01-01';
  $tz = new DateTimeZone(date_default_timezone_get());
  $t0 = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $base.' '.(strlen($inizio)==5 ? $inizio.':00' : $inizio), $tz);
  $t1 = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $base.' '.(strlen($fine)==5 ? $fine.':00' : $fine), $tz);
  if (!$t0 || !$t1) throw new InvalidArgumentException('Impossibile parse-orari');
  if ($t1 < $t0) $t1 = $t1->add(new DateInterval('P1D'));
  $diff = $t1->getTimestamp() - $t0->getTimestamp();
  $min  = intdiv($diff, 60);
  if ($min <= 0) throw new InvalidArgumentException('Durata non valida (<= 0 minuti)');
  return $min;
}

function h_ore_min(int $minuti): string {
  $h = intdiv($minuti, 60);
  $m = $minuti % 60;
  return sprintf('%d:%02d', $h, $m);
}
function ore_decimali(int $minuti, int $precisione = 2): float {
  return round($minuti / 60, $precisione);
}

function minuti_da_intervallo_datetime(string $inizioIso, string $fineIso): int {
  if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?$/', $inizioIso) ||
      !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?$/', $fineIso)) {
    throw new InvalidArgumentException('Formato datetime invalido. Usa YYYY-MM-DDTHH:MM');
  }
  $tz = new DateTimeZone(date_default_timezone_get());
  $fix = fn($s) => (strlen($s)===16 ? $s.':00' : $s);
  $d0 = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', $fix($inizioIso), $tz);
  $d1 = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', $fix($fineIso),   $tz);
  if (!$d0 || !$d1) throw new InvalidArgumentException('Impossibile parsare le date/ore');
  $diff = $d1->getTimestamp() - $d0->getTimestamp();
  if ($diff <= 0) throw new InvalidArgumentException('Intervallo non valido (fine <= inizio)');
  return intdiv($diff, 60);
}

/* ========= LIMITE ORE ========= */
if (!defined('LIMITE_ORE_ANNUALI')) {
  define('LIMITE_ORE_ANNUALI', 60.0); // ore/anno piene (senza infortuni)
}

/** Limite ore annue (float, in ore) */
function limite_ore_annue_ore(): float { return (float) LIMITE_ORE_ANNUALI; }
/** Limite in minuti (int) */
function limite_ore_annue_min(): int { return (int) round(limite_ore_annue_ore() * 60); }

/** Anno record (usa inizio_dt o data) */
function anno_record(array $it): ?int {
  if (!empty($it['inizio_dt']) && preg_match('/^\d{4}-\d{2}-\d{2}T/', $it['inizio_dt'])) {
    return (int) substr($it['inizio_dt'], 0, 4);
  }
  if (!empty($it['data']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $it['data'])) {
    return (int) substr($it['data'], 0, 4);
  }
  return null;
}

/** Minuti totali annui per vigile (escludendo sessione $ignoreSessionUid se indicata) */
function minuti_totali_annui_per_vigile(array $items, int $vigileId, int $anno, ?string $ignoreSessionUid = null): int {
  $tot = 0;
  foreach ($items as $it) {
    if ((int) ($it['vigile_id'] ?? 0) !== $vigileId) continue;
    $y = anno_record($it);
    if ($y !== $anno) continue;
    if ($ignoreSessionUid !== null && ($it['sessione_uid'] ?? '') === $ignoreSessionUid) continue;
    if (isset($it['minuti']) && is_numeric($it['minuti'])) { $tot += (int) $it['minuti']; continue; }
    if (!empty($it['inizio_dt']) && !empty($it['fine_dt'])) {
      try { $tot += minuti_da_intervallo_datetime($it['inizio_dt'], $it['fine_dt']); continue; } catch (\Throwable $e) {}
    }
    if (!empty($it['data']) && !empty($it['inizio']) && !empty($it['fine'])) {
      try { $tot += minuti_da_orari(substr($it['inizio'],0,8), substr($it['fine'],0,8)); continue; } catch (\Throwable $e) {}
    }
  }
  return $tot;
}

/* ========= INFORTUNI (utility) ========= */

/** true se Y-m-d $ymd cade dentro uno dei periodi di infortunio del vigile */
function is_infortunio_ymd(array $infortuniList, int $vigileId, string $ymd): bool {
  foreach ($infortuniList as $row) {
    if ((int)($row['vigile_id'] ?? 0) !== $vigileId) continue;
    $dal = (string)($row['dal'] ?? '');
    $al  = (string)($row['al']  ?? '');
    if ($dal && $al && $ymd >= $dal && $ymd <= $al) return true;
  }
  return false;
}

/** Mappa mesi esenti per vigile in un anno: [ 'YYYY-MM' => true, ... ] */
function mesi_esenti_per_vigile(array $infortuniList, int $vigileId, int $anno): array {
  $out = [];
  for ($m=1;$m<=12;$m++) {
    $ym = sprintf('%04d-%02d', $anno, $m);
    $start = $ym.'-01';
    $end   = (new DateTimeImmutable($start))->modify('last day of this month')->format('Y-m-d');
    foreach ($infortuniList as $row) {
      if ((int)($row['vigile_id'] ?? 0) !== $vigileId) continue;
      $dal = (string)($row['dal'] ?? '');
      $al  = (string)($row['al']  ?? '');
      if (!$dal || !$al) continue;
      // overlap mese ↔ periodo
      if (!($end < $dal || $start > $al)) { $out[$ym] = true; break; }
    }
  }
  return $out;
}

/** Soglia annua personalizzata (in minuti) = 60h * (mesi attivi / 12) */
function soglia_annua_min_personalizzata(array $infortuniList, int $vigileId, int $anno): int {
  $base = limite_ore_annue_min(); // minuti per 12 mesi
  $esenti = mesi_esenti_per_vigile($infortuniList, $vigileId, $anno);
  $attivi = 12 - count($esenti);
  if ($attivi < 0) $attivi = 0;
  // proporzione sui minuti
  return (int) round($base * ($attivi / 12));
}