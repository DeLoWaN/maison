<?php
// Statuts posés par la famille sur chaque maison, par recherche.
// Stockés hors du dossier déployé par Git (<home>/maison-data/<recherche>/statuts.json),
// pour qu'un déploiement ne les efface jamais.
// GET  api.php?r=longere                -> {"<id>": {"statut": "...", "le": "AAAA-MM-JJ HH:MM"}}
// POST api.php?r=longere {id, statut}   -> enregistre ("" efface le statut)
// Avis de Damien et Aurélia (pouce levé ou baissé), rangé à part dans avis.json :
// GET  api.php?r=longere&avis=1              -> {"<id>": {"avis": "pour"|"contre", "le": "...", "par": "..."}}
// POST api.php?r=longere&avis=1 {id, avis}   -> enregistre ("" efface) ; réservé à leurs comptes
//      avis : "pour", "neutre" (sans avis) ou "contre" ; {id, commentaire} enregistre le texte libre ("" l'efface)

require_once __DIR__ . '/auth-lib.php';
$moi = auth_exiger(); // réservé aux comptes Google de la famille
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$r = isset($_GET['r']) ? (string) $_GET['r'] : '';
if (!preg_match('/^[a-z0-9-]{1,40}$/', $r) || !is_dir(__DIR__ . '/recherches/' . $r)) {
    http_response_code(404);
    echo '{"erreur":"recherche inconnue"}';
    exit;
}

$dir = dirname(dirname(__DIR__)) . '/maison-data/' . $r;
$avis = isset($_GET['avis']);
$file = $dir . ($avis ? '/avis.json' : '/statuts.json');
$champ = $avis ? 'avis' : 'statut';
$permis = $avis ? ['pour', 'neutre', 'contre', ''] : ['a_visiter', 'visitee', 'pas_interesses', ''];
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
$statut = is_array($in) && isset($in[$champ]) ? (string) $in[$champ] : null;
// Commentaire libre de Damien et Aurélia (avis.json), envoyé seul ou avec l'avis.
$comm = ($avis && is_array($in) && array_key_exists('commentaire', $in)) ? trim((string) $in['commentaire']) : null;
if ($comm !== null && function_exists('mb_substr')) $comm = mb_substr($comm, 0, 3000, 'UTF-8');
if ($comm !== null && $statut === null) $statut = false; // commentaire seul : l'avis ne bouge pas
if ($avis && !auth_peut_aviser($moi)) {
    http_response_code(403);
    echo '{"erreur":"avis réservé à Damien et Aurélia"}';
    exit;
}
if (!preg_match('/^[a-z0-9-]{1,60}$/', $id) || ($statut !== false && !in_array($statut, $permis, true))) {
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

date_default_timezone_set('Europe/Paris');
if ($avis) {
    $e = isset($data[$id]) && is_array($data[$id]) ? $data[$id] : array();
    if ($statut === '') { unset($e['avis'], $e['le'], $e['par']); }
    elseif ($statut !== false) { $e['avis'] = $statut; $e['le'] = date('Y-m-d H:i'); $e['par'] = auth_prenom($moi); }
    if ($comm !== null) {
        if ($comm === '') { unset($e['commentaire'], $e['commentaire_le'], $e['commentaire_par']); }
        else { $e['commentaire'] = $comm; $e['commentaire_le'] = date('Y-m-d H:i'); $e['commentaire_par'] = auth_prenom($moi); }
    }
    if (count($e)) $data[$id] = $e; else unset($data[$id]);
} elseif ($statut === '') {
    unset($data[$id]);
} else {
    $data[$id] = ['statut' => $statut, 'le' => date('Y-m-d H:i')];
}

ftruncate($fp, 0);
rewind($fp);
fwrite($fp, json_encode((object) $data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
fflush($fp);
flock($fp, LOCK_UN);
fclose($fp);

echo json_encode((object) $data, JSON_UNESCAPED_UNICODE);
