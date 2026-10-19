<?php
// Repères privés de la famille (position de « chez Damien et Aurélia »), rangés hors du dossier publié :
// <home>/maison-data/lieux.json. Jamais dans le dépôt, qui est public.
// GET  lieux.php            -> {"chez": {"lat":..., "lon":..., "nom": "..."}} ou {}
// POST lieux.php {"chez": {...}} -> enregistre
// Compatible PHP 5.4.
require_once __DIR__ . '/auth-lib.php';
auth_exiger();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$f = dirname(dirname(__DIR__)) . '/maison-data/lieux.json';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo is_file($f) ? file_get_contents($f) : '{}';
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

$in = json_decode(file_get_contents('php://input'), true);
$c = (is_array($in) && isset($in['chez']) && is_array($in['chez'])) ? $in['chez'] : null;
$ok = $c && isset($c['lat'], $c['lon'])
    && is_numeric($c['lat']) && is_numeric($c['lon'])
    && $c['lat'] > 48 && $c['lat'] < 50 && $c['lon'] > -2 && $c['lon'] < 1;
if (!$ok) { http_response_code(400); echo '{"erreur":"position invalide"}'; exit; }

$out = array('chez' => array(
    'lat' => round((float) $c['lat'], 6),
    'lon' => round((float) $c['lon'], 6),
    'nom' => isset($c['nom']) ? mb_substr((string) $c['nom'], 0, 60, 'UTF-8') : 'Damien et Aurélia',
));
if (!is_dir(dirname($f))) @mkdir(dirname($f), 0750, true);
$tmp = $f . '.tmp';
file_put_contents($tmp, json_encode($out));
rename($tmp, $f);
echo json_encode(array('ok' => true));
