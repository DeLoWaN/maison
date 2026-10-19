<?php
// GET numeros.php -> {"7": {"r": "longere", "id": "thue-185"}, ...} : pour « aller à la maison n° 7 ».
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require __DIR__ . '/numeros-lib.php';
$j = numeros_lire();
$out = array();
foreach ($j['carte'] as $cle => $n) {
    $p = explode('/', $cle, 2);
    if (count($p) === 2) $out[(string) $n] = array('r' => $p[0], 'id' => $p[1]);
}
echo json_encode((object) $out);
