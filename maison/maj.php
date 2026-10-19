<?php
// Réception de la liste mise à jour par la veille automatique.
// POST maj.php?r=longere, corps = data.json complet.
// La liste est rangée hors du dossier déployé (<home>/maison-data/<r>/data.json),
// avec une copie datée de chaque version (les 60 dernières sont gardées).
require_once __DIR__ . '/auth-lib.php';
auth_exiger(); // réservé aux comptes Google de la famille
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo '{"erreur":"POST attendu"}'; exit; }
$r = isset($_GET['r']) ? (string) $_GET['r'] : '';
if (!preg_match('/^[a-z0-9-]{1,40}$/', $r) || !is_dir(__DIR__ . '/recherches/' . $r)) {
    http_response_code(404); echo '{"erreur":"recherche inconnue"}'; exit;
}
$brut = file_get_contents('php://input');
if (strlen($brut) > 3000000) { http_response_code(413); echo '{"erreur":"trop gros"}'; exit; }
$d = json_decode($brut, true);
$ok = is_array($d) && isset($d['maj']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['maj'])
    && isset($d['biens']) && is_array($d['biens']) && isset($d['titre']);
if ($ok) {
    foreach ($d['biens'] as $b) {
        if (!is_array($b) || !isset($b['id']) || !preg_match('/^[a-z0-9-]{1,60}$/', $b['id']) || !isset($b['etat'])) { $ok = false; break; }
    }
}
if (!$ok) { http_response_code(400); echo '{"erreur":"format invalide"}'; exit; }

// Numéro de maison : attribué une fois pour toutes par le serveur, unique sur tout le site
// (toutes recherches confondues), jamais réutilisé. La veille n'a pas à s'en occuper.
require_once __DIR__ . '/numeros-lib.php';
$d['biens'] = numeroter($r, $d['biens']);

// Adresses retrouvées : la veille relit une liste où l'adresse est masquée (data.php).
// Si elle renvoie une adresse sans texte, on garde celle déjà enregistrée.
$fichierVivant = dirname(dirname(__DIR__)) . '/maison-data/' . $r . '/data.json';
$ancien = is_file($fichierVivant) ? json_decode((string) @file_get_contents($fichierVivant), true) : null;
$adresses = array();
$galeries = array();
$surfaces = array();
if (is_array($ancien) && isset($ancien['biens']) && is_array($ancien['biens'])) {
    foreach ($ancien['biens'] as $b) {
        if (isset($b['id'], $b['adresse']['texte'])) $adresses[$b['id']] = $b['adresse'];
        if (isset($b['id'], $b['photos']) && is_array($b['photos']) && count($b['photos'])) $galeries[$b['id']] = $b['photos'];
        if (isset($b['id'], $b['surface']) && is_numeric($b['surface']) && $b['surface'] > 0) $surfaces[$b['id']] = $b['surface'];
    }
}
// Galerie de photos : si la veille renvoie un bien sans photos, on garde celles déjà enregistrées.
foreach ($d['biens'] as $i => $b) {
    if (isset($galeries[$b['id']]) && (!isset($b['photos']) || !is_array($b['photos']) || !count($b['photos']))) {
        $d['biens'][$i]['photos'] = $galeries[$b['id']];
    }
    // Même chose pour la surface habitable.
    if (isset($surfaces[$b['id']]) && (!isset($b['surface']) || !is_numeric($b['surface']) || $b['surface'] <= 0)) {
        $d['biens'][$i]['surface'] = $surfaces[$b['id']];
    }
}
foreach ($d['biens'] as $i => $b) {
    $id = $b['id'];
    $recu = isset($b['adresse']) && is_array($b['adresse']) ? $b['adresse'] : null;
    if (isset($adresses[$id]) && ($recu === null || !isset($recu['texte']) || $recu['texte'] === '')) {
        $d['biens'][$i]['adresse'] = $adresses[$id];
    }
}

$dir = dirname(dirname(__DIR__)) . '/maison-data/' . $r;
$hist = $dir . '/historique';
if (!is_dir($hist)) @mkdir($hist, 0750, true);
$file = $dir . '/data.json';
date_default_timezone_set('Europe/Paris');
if (is_file($file)) @copy($file, $hist . '/' . date('Ymd-His') . '.json');
$copies = glob($hist . '/*.json');
if ($copies && count($copies) > 60) { sort($copies); foreach (array_slice($copies, 0, count($copies) - 60) as $f) @unlink($f); }
$tmp = $file . '.tmp';
if (file_put_contents($tmp, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) === false || !rename($tmp, $file)) {
    http_response_code(500); echo '{"erreur":"écriture impossible"}'; exit;
}
echo json_encode(array('ok' => true, 'maj' => $d['maj'], 'biens' => count($d['biens'])));
