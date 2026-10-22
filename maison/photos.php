<?php
// Enregistre la galerie de photos d'un bien, sans toucher au reste de la liste.
// POST photos.php {"r": "longere", "id": "<id>", "photos": ["https://...", ...]}
// Verrou sur le fichier pour que plusieurs envois simultanés ne s'écrasent pas.
// Compatible PHP 5.4.
require_once __DIR__ . '/auth-lib.php';
auth_exiger();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo '{"erreur":"POST attendu"}'; exit; }

$in = json_decode(file_get_contents('php://input'), true);
$r = is_array($in) && isset($in['r']) ? (string) $in['r'] : '';
$id = is_array($in) && isset($in['id']) ? (string) $in['id'] : '';
$ph = is_array($in) && isset($in['photos']) && is_array($in['photos']) ? $in['photos'] : null;
if (!preg_match('/^[a-z0-9-]{1,40}$/', $r) || !is_dir(__DIR__ . '/recherches/' . $r) || !preg_match('/^[a-z0-9-]{1,60}$/', $id) || $ph === null) {
    http_response_code(400); echo '{"erreur":"format invalide"}'; exit;
}
require_once __DIR__ . '/donnees-lib.php';
donnees_refuser_si_archivee($r);
$photos = array();
foreach ($ph as $u) {
    if (is_string($u) && strlen($u) <= 800 && preg_match('#^https://[^\s"<>]+$#', $u) && !in_array($u, $photos, true)) $photos[] = $u;
    if (count($photos) >= 40) break;
}

$file = dirname(dirname(__DIR__)) . '/maison-data/' . $r . '/data.json';
if (!is_file($file)) { http_response_code(404); echo '{"erreur":"liste absente"}'; exit; }
$verrou = fopen($file . '.lock', 'c');
flock($verrou, LOCK_EX);
$d = json_decode((string) file_get_contents($file), true);
$trouve = false;
if (is_array($d) && isset($d['biens']) && is_array($d['biens'])) {
    foreach ($d['biens'] as $i => $b) {
        if (isset($b['id']) && $b['id'] === $id) { $d['biens'][$i]['photos'] = $photos; $trouve = true; break; }
    }
}
if (!$trouve) { flock($verrou, LOCK_UN); http_response_code(404); echo '{"erreur":"bien inconnu"}'; exit; }
require_once __DIR__ . '/donnees-lib.php';
$ok = donnees_ecrire_json($file, $d);
flock($verrou, LOCK_UN);
if ($ok !== true) { http_response_code(500); echo json_encode(array('erreur' => $ok), JSON_UNESCAPED_UNICODE); exit; }
echo json_encode(array('ok' => true, 'r' => $r, 'id' => $id, 'photos' => count($photos)));
