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
$brut = file_get_contents(is_file($vivant) ? $vivant : $origine);
$d = json_decode($brut, true);
if (!is_array($d) || !isset($d['biens']) || !is_array($d['biens'])) { echo $brut; exit; }
// Adresse retrouvée : tant que le site n'est pas protégé, seuls la confiance et la date sortent
// (jamais l'adresse elle-même ni les preuves). Le serveur garde le reste (voir maj.php).
foreach ($d['biens'] as $i => $b) {
    if (isset($b['adresse']) && is_array($b['adresse'])) {
        $a = $b['adresse'];
        $d['biens'][$i]['adresse'] = array(
            'confiance' => isset($a['confiance']) ? $a['confiance'] : null,
            'le' => isset($a['le']) ? $a['le'] : null,
            'essais' => isset($a['essais']) ? $a['essais'] : null,
        );
    }
}
echo json_encode($d, JSON_UNESCAPED_UNICODE);
