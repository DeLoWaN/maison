<?php
// Statuts posés par la famille sur chaque maison, par recherche.
// Stockés hors du dossier déployé par Git (<home>/maison-data/<recherche>/statuts.json),
// pour qu'un déploiement ne les efface jamais.
// GET  api.php?r=longere                -> {"<id>": {"statut": "...", "le": "AAAA-MM-JJ HH:MM"}}
// POST api.php?r=longere {id, statut}   -> enregistre ("" efface le statut)

require_once __DIR__ . '/auth-lib.php';
auth_exiger(); // réservé aux comptes Google de la famille
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$r = isset($_GET['r']) ? (string) $_GET['r'] : '';
if (!preg_match('/^[a-z0-9-]{1,40}$/', $r) || !is_dir(__DIR__ . '/recherches/' . $r)) {
    http_response_code(404);
    echo '{"erreur":"recherche inconnue"}';
    exit;
}

$dir = dirname(dirname(__DIR__)) . '/maison-data/' . $r;
$file = $dir . '/statuts.json';
$permis = ['a_visiter', 'visitee', 'pas_interesses', ''];
if (!is_dir($dir)) { @mkdir($dir, 0750, true); }

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $j = is_file($file) ? json_decode(@file_get_contents($file), true) : [];
    echo json_encode((object) (is_array($j) ? $j : []), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo '{"erreur":"méthode non permise"}';
    exit;
}

$in = json_decode(file_get_contents('php://input'), true);
$id = is_array($in) && isset($in['id']) ? (string) $in['id'] : '';
$statut = is_array($in) && isset($in['statut']) ? (string) $in['statut'] : null;
if (!preg_match('/^[a-z0-9-]{1,60}$/', $id) || !in_array($statut, $permis, true)) {
    http_response_code(400);
    echo '{"erreur":"requête invalide"}';
    exit;
}

$fp = fopen($file, 'c+');
if (!$fp || !flock($fp, LOCK_EX)) {
    http_response_code(500);
    echo '{"erreur":"stockage indisponible"}';
    exit;
}
$data = json_decode(stream_get_contents($fp) ?: '{}', true);
if (!is_array($data)) $data = [];

if ($statut === '') {
    unset($data[$id]);
} else {
    date_default_timezone_set('Europe/Paris');
    $data[$id] = ['statut' => $statut, 'le' => date('Y-m-d H:i')];
}

ftruncate($fp, 0);
rewind($fp);
fwrite($fp, json_encode((object) $data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
fflush($fp);
flock($fp, LOCK_UN);
fclose($fp);

echo json_encode((object) $data, JSON_UNESCAPED_UNICODE);
