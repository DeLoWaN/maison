<?php
// GET numeros.php -> {"7": {"r": "maison", "id": "thue-185"}, ...} : pour « aller à la maison n° 7 ».
// Un ancien numéro fusionné (alias) mène à la maison gardée.
require_once __DIR__ . '/auth-lib.php';
auth_exiger(); // réservé aux comptes Google de la famille
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/numeros-lib.php';
$j = numeros_lire();
$out = array();
foreach ($j['carte'] as $cle => $n) {
    $p = explode('/', $cle, 2);
    if (count($p) === 2) $out[(string) $n] = array('r' => $p[0], 'id' => $p[1]);
}
if (isset($j['alias']) && is_array($j['alias'])) foreach ($j['alias'] as $ancien => $garde) {
    if (isset($out[(string) $garde])) $out[(string) $ancien] = $out[(string) $garde];
}
echo json_encode((object) $out);
