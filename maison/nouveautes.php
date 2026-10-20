<?php
// Tri des annonces lues dans une liste de résultats : le serveur dit lesquelles sont nouvelles.
// POST nouveautes.php?r=longere  {"annonces": [{"url": "...", "prix": 285000}, ...]}
// Réponse :
//   nouvelles   : adresses jamais vues (à ouvrir si elles semblent correspondre)
//   a_rouvrir   : écartées pour leur prix, et le prix affiché est maintenant plus bas
//   suivies     : déjà suivies ; le serveur note qu'elles sont toujours en ligne (vu_en_liste_le = aujourd'hui).
//                 prix_different = true si le prix de la liste n'est pas celui enregistré : ouvrir la fiche pour confirmer.
//   ecartees    : nombre d'annonces déjà examinées et écartées (rien à faire)
//   autre_recherche : annonces suivies par l'autre recherche (information)
require_once __DIR__ . '/auth-lib.php';
auth_exiger();
require_once __DIR__ . '/donnees-lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo '{"erreur":"POST attendu"}'; exit; }
$r = isset($_GET['r']) ? (string) $_GET['r'] : '';
if (!donnees_recherche_valide($r)) { http_response_code(404); echo '{"erreur":"recherche inconnue"}'; exit; }
$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in) || !isset($in['annonces']) || !is_array($in['annonces'])) { http_response_code(400); echo '{"erreur":"format attendu : {\"annonces\": [{\"url\":..., \"prix\":...}]}"}'; exit; }

$autres = array();
foreach (glob(__DIR__ . '/recherches/*', GLOB_ONLYDIR) as $dir) {
    $x = basename($dir); if ($x === $r) continue;
    $da = donnees_lire($x);
    foreach ($da['biens'] as $b) if (isset($b['liens']) && is_array($b['liens'])) foreach ($b['liens'] as $l) if (isset($l['url'])) $autres[donnees_cle_url($l['url'])] = array('r' => $x, 'id' => $b['id'], 'num' => isset($b['num']) ? $b['num'] : null);
}

$sortie = array('nouvelles' => array(), 'a_rouvrir' => array(), 'suivies' => array(), 'ecartees' => 0, 'autre_recherche' => array());
$vuesEnListe = array();
$d = donnees_lire($r);
$ix = donnees_index($d);
$deja = array();
foreach ($in['annonces'] as $a) {
    $url = is_array($a) && isset($a['url']) ? (string) $a['url'] : (is_string($a) ? $a : '');
    if (!preg_match('#^https?://#', $url)) continue;
    $k = donnees_cle_url($url);
    if (isset($deja[$k])) continue; $deja[$k] = 1;
    $prix = is_array($a) && isset($a['prix']) ? donnees_entier($a['prix']) : null;
    if (isset($ix[$k]) && $ix[$k]['type'] === 'bien') {
        $b = $d['biens'][$ix[$k]['i']];
        $diff = $prix !== null && isset($b['prix']) && $b['prix'] !== null && (int) $b['prix'] !== $prix;
        $sortie['suivies'][] = array('url' => $url, 'id' => $b['id'], 'num' => isset($b['num']) ? $b['num'] : null, 'etat' => $b['etat'], 'prix' => isset($b['prix']) ? $b['prix'] : null, 'prix_liste' => $prix, 'prix_different' => $diff);
        $vuesEnListe[$b['id']] = 1;
    } elseif (isset($ix[$k])) {
        $v = $d['vus'][$ix[$k]['i']];
        $budget = isset($v['motif']) && preg_match('/budget|trop cher|prix/i', $v['motif']);
        if ($budget && $prix !== null && isset($v['prix']) && $v['prix'] !== null && $prix < (int) $v['prix']) {
            $sortie['a_rouvrir'][] = array('url' => $url, 'prix_note' => $v['prix'], 'prix' => $prix, 'motif' => $v['motif']);
        } else $sortie['ecartees']++;
    } else {
        $sortie['nouvelles'][] = $url;
        if (isset($autres[$k])) $sortie['autre_recherche'][] = array('url' => $url) + $autres[$k];
    }
}
// Toujours en ligne : noté sans toucher au reste (sert au choix des fiches à revérifier, voir suivi.php).
if ($vuesEnListe) {
    donnees_modifier($r, function (&$d) use ($vuesEnListe) {
        $auj = donnees_aujourdhui();
        foreach ($d['biens'] as $i => $b) if (isset($vuesEnListe[$b['id']])) $d['biens'][$i]['vu_en_liste_le'] = $auj;
        return true;
    }, false);
}
echo json_encode($sortie, JSON_UNESCAPED_UNICODE);
