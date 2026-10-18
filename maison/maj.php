<?php
// Réception de la liste mise à jour par la veille automatique.
// POST maj.php?r=longere, corps = data.json complet.
// La liste est rangée hors du dossier déployé (<home>/maison-data/<r>/data.json),
// avec une copie datée de chaque version (les 60 dernières sont gardées).
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
