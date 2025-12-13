<?php
$SEDE_MAP = [
 
 '3002' => 'alpignano',
    '3003' => 'avigliana',
    '3004' => 'bardonecchia',
    '3005' => 'borgone di Susa',
    '3006' => 'bosconero',
    '3007' => 'bussoleno',
    '3008' => 'sestriere',
    '3009' => 'carignano',
    '3010' => 'carmagnola',
    '3011' => 'caselle',
    '3012' => 'castellamonte',
    '3013' => 'chiomonte',
    '3014' => 'chivasso',
    '3015' => 'condove',
    '3016' => 'cuorgne',
    '3017' => 'fenestrelle',
    '3020' => 'giaveno',
    '3021' => 'grugliasco',
    '3022' => 'santena',
    '3023' => 'lanzo',
    '3024' => 'luserna san giovanni',
    '3025' => 'mathi',
    '3026' => 'montanaro',
    '3027' => 'nole',
    '3031' => 'rivalta',
    '3032' => 'riva presso phieri',
    '3033' => 'rivarolo',
    '3034' => 'rivoli',
    '3036' => 'salbertrand',
    '3037' => 's.antonino di susa',
    '3038' => 's.maurizio canavese',
    '3039' => 'sauze d oulx',
    '3040' => 'susa',
    '3041' => 'torre pellice',
    '3042' => 'oulx',
    '3043' => 'venaria',
    '3045' => 'vinovo',
    '3046' => 'volpiano',
    '5040' => 'viu',
     
  // aggiungi le altre...
];
$base = __DIR__.'/data';
@mkdir($base, 0775, true);
foreach ($SEDE_MAP as $cod=>$slug) {
  $dir = $base.'/'.$slug;
  if (!is_dir($dir)) { @mkdir($dir, 0775, true); echo "Creato $dir\n"; }
}