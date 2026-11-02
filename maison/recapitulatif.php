<?php
// Récapitulatif envoyé à la famille après un passage (voir recapitulatif-lib.php).
// GET  recapitulatif.php            -> {"envoyer": false, "raison": ...}
//                                      ou {"envoyer": true, "id", "objet", "html", "octets", "nb", "page"}
//      Prépare le Récapitulatif des changements jamais envoyés et le garde sous son id ; ne marque rien.
// POST recapitulatif.php {"id", "a": [adresses], "repondre_a"} -> {"ok": true, "envoye_a"}
//      Envoie le courriel de ce Récapitulatif par le serveur, puis marque ses changements : ils ne seront plus jamais renvoyés.
//      Seuls les comptes de la famille (auth_autorise) sont acceptés. Un id déjà envoyé n'est pas renvoyé : {"ok": true, "deja": true}.
//      Échec de l'envoi : 502, rien n'est marqué.
// GET  recapitulatif.php?id=<id>    -> page HTML du Récapitulatif complet, sans limite de fiches.
//      Sans connexion : renvoie vers la page de connexion du site, qui ramène ici.
require_once __DIR__ . '/auth-lib.php';
require_once __DIR__ . '/recapitulatif-lib.php';
$base = 'https://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/';
$id = isset($_GET['id']) ? (string) $_GET['id'] : '';

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $id !== '') {
    if (!recap_id_valide($id)) { http_response_code(400); exit; }
    if (!auth_https() || auth_utilisateur() === null) {
        header('Location: ' . $base . '?retour=' . rawurlencode('recapitulatif.php?id=' . $id), true, 302);
        exit;
    }
    $p = json_decode((string) @file_get_contents(recap_fichier($id)), true);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    if (!is_array($p)) { http_response_code(404); echo '<p>Ce récapitulatif n\'existe plus.</p>'; exit; }
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . recap_e($p['objet']) . '</title></head><body style="margin:0;background:#eff2ef">'
        . recap_html($p['calc'], $base, null, '') . '</body></html>';
    exit;
}

auth_exiger();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = json_decode(file_get_contents('php://input'), true);
    $id = is_array($in) && isset($in['id']) ? (string) $in['id'] : '';
    $f = recap_fichier($id);
    $p = recap_id_valide($id) ? json_decode((string) @file_get_contents($f), true) : null;
    if (!is_array($p)) { http_response_code(404); echo '{"erreur":"récapitulatif inconnu"}'; exit; }
    if (isset($p['envoye_le'])) { echo json_encode(array('ok' => true, 'deja' => true, 'envoye_le' => $p['envoye_le']), JSON_UNESCAPED_UNICODE); exit; }
    $page = $base . 'recapitulatif.php?id=' . $id;
    try {
        $m = recap_courriel($p['objet'], recap_html($p['calc'], $base, RECAP_FICHES_MAX, $page), $page,
            isset($in['a']) ? $in['a'] : null, isset($in['repondre_a']) ? $in['repondre_a'] : null);
    } catch (InvalidArgumentException $e) {
        http_response_code(400); echo json_encode(array('erreur' => $e->getMessage()), JSON_UNESCAPED_UNICODE); exit;
    }
    if (!mail($m['a'], $m['objet'], $m['corps'], $m['entetes'], '-f' . RECAP_EXPEDITEUR)) {
        http_response_code(502); echo '{"erreur":"le serveur n\'a pas accepté le courriel (mail)"}'; exit;
    }
    $etat = recap_modifier_etat(function ($etat) use ($p) { return recap_marquer($etat, $p['calc']['marques']); });
    $p['envoye_le'] = date('Y-m-d H:i'); $p['envoye_a'] = $in['a'];
    @file_put_contents($f, json_encode($p, JSON_UNESCAPED_UNICODE));
    if (isset($etat['erreur'])) { http_response_code(500); echo json_encode(array('erreur' => 'courriel envoyé, mais ' . $etat['erreur']), JSON_UNESCAPED_UNICODE); exit; }
    echo json_encode(array('ok' => true, 'envoye_a' => $in['a']), JSON_UNESCAPED_UNICODE);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); echo '{"erreur":"GET ou POST attendu"}'; exit; }

// Le premier appel pose « depuis » à aujourd'hui.
$etat = recap_modifier_etat(function ($etat) { return $etat; });
if (isset($etat['erreur'])) { http_response_code(500); echo json_encode($etat, JSON_UNESCAPED_UNICODE); exit; }
$calc = recap_calculer(recap_recherches(), $etat);
if (!$calc['envoyer']) {
    echo json_encode(array('envoyer' => false, 'raison' => 'aucune Nouvelle maison ni Baisse de prix depuis le dernier Récapitulatif', 'nb' => $calc['nb']), JSON_UNESCAPED_UNICODE);
    exit;
}
$id = date('Ymd-His');
$objet = recap_objet($calc['nb'], donnees_aujourdhui());
$page = $base . 'recapitulatif.php?id=' . $id;
if (@file_put_contents(recap_fichier($id), json_encode(array('id' => $id, 'le' => date('Y-m-d H:i'), 'objet' => $objet, 'calc' => $calc), JSON_UNESCAPED_UNICODE)) === false) {
    http_response_code(500); echo '{"erreur":"récapitulatif non enregistré : espace de l\'hébergement plein probablement (voir diag.php)"}'; exit;
}
recap_nettoyer();
$html = recap_html($calc, $base, RECAP_FICHES_MAX, $page);
echo json_encode(array('envoyer' => true, 'id' => $id, 'objet' => $objet, 'html' => $html, 'octets' => strlen($html), 'nb' => $calc['nb'], 'page' => $page), JSON_UNESCAPED_UNICODE);
