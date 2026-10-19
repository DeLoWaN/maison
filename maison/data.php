<?php
// Liste des maisons d'une recherche.
// GET data.php?r=longere : renvoie la dernière liste envoyée par la veille
// (<home>/maison-data/<r>/data.json), sinon la liste d'origine du dépôt.
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
$vivant = dirname(dirname(__DIR__)) . '/maison-data/' . $r . '/data.json';
$origine = __DIR__ . '/recherches/' . $r . '/data.json';
$brut = file_get_contents(is_file($vivant) ? $vivant : $origine);
$d = json_decode($brut, true);
if (!is_array($d) || !isset($d['biens']) || !is_array($d['biens'])) { echo $brut; exit; }
echo json_encode($d, JSON_UNESCAPED_UNICODE);
