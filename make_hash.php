<?php
// make_hash.php
header('Content-Type: text/plain; charset=utf-8');

echo "PHP ".PHP_VERSION."\n";

// 1) Genera un bcrypt nuovo per '0000'
$plain = '0000';
$hash  = password_hash($plain, PASSWORD_DEFAULT);
echo "HASH (nuovo, da INCOLLARE nel JSON):\n$hash\n";

// 2) Verifica subito
echo "VERIFY: ";
var_dump(password_verify($plain, $hash));

// 3) Se vuoi testare un hash incollato da te, mettilo qui:
$test = 'PASTA-QUI-IL-TUO-HASH'; // sostituisci
if ($test !== 'PASTA-QUI-IL-TUO-HASH') {
  echo "VERIFY (hash incollato): ";
  var_dump(password_verify($plain, $test));
  echo "Lunghezza hash incollato: ".strlen($test)."\n";
}