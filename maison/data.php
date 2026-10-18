<?php
// Liste des maisons d'une recherche.
// GET data.php?r=longere : renvoie la dernière liste envoyée par la veille
// (<home>/maison-data/<r>/data.json), sinon la liste d'origine du dépôt.
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
readfile(is_file($vivant) ? $vivant : $origine);
