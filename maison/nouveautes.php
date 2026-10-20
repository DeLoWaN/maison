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
// Ouest-France Immo, forme courte : {"of": "maison/mouen-14-14454/20863273:285000 longere-et-corps-de-ferme/ellon-14-14238/20862001:199000 ..."}
//   (chemin après /immobilier/vente/, sans .htm, puis :prix). Pour ces annonces, le serveur écarte lui-même les nouvelles
//   hors zone (commune lue dans l'adresse, liste recherches/<r>/zone.json) et au-dessus du plafond d'ouverture :
//   elles sont ajoutées aux écartées sans opération vu (compte dans ecartees_auto).
// GET nouveautes.php?r=longere&seuil=of : plus grand numéro d'annonce Ouest-France déjà connu (pour le script de relevé).
require_once __DIR__ . '/auth-lib.php';
auth_exiger();
require_once __DIR__ . '/donnees-lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$r = isset($_GET['r']) ? (string) $_GET['r'] : '';
if (!donnees_recherche_valide($r)) { http_response_code(404); echo '{"erreur":"recherche inconnue"}'; exit; }
$OF = '#^https://www\.ouestfrance-immo\.com/immobilier/vente/[^/]+/([a-z0-9-]+?)-14-\d+/(\d+)\.htm$#';
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['seuil'])) {
    $d = donnees_lire($r); $max = 0;
    foreach (array_keys(donnees_index($d)) as $k) if (preg_match('#^ouestfrance-immo\.com/immobilier/vente/[^/]+/[^/]+/(\d+)\.htm$#', $k, $m) && (int) $m[1] > $max) $max = (int) $m[1];
    echo json_encode(array('of_seuil' => $max)); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo '{"erreur":"POST attendu"}'; exit; }
$in = json_decode(file_get_contents('php://input'), true);
if (is_array($in) && isset($in['of']) && is_string($in['of'])) {
    if (!isset($in['annonces']) || !is_array($in['annonces'])) $in['annonces'] = array();
    foreach (preg_split('/\s+/', trim($in['of'])) as $x) if (preg_match('#^([a-z0-9-]+/[a-z0-9-]+-14-\d+/\d+):(\d*)$#', $x, $m))
        $in['annonces'][] = array('url' => 'https://www.ouestfrance-immo.com/immobilier/vente/' . $m[1] . '.htm', 'prix' => $m[2] === '' ? null : (int) $m[2]);
}
if (!is_array($in) || !isset($in['annonces']) || !is_array($in['annonces'])) { http_response_code(400); echo '{"erreur":"format attendu : {\"annonces\": [{\"url\":..., \"prix\":...}]} ou {\"of\": \"chemin:prix ...\"}"}'; exit; }
$zone = json_decode((string) @file_get_contents(__DIR__ . '/recherches/' . $r . '/zone.json'), true);
$communes = array(); $plafond = null;
if (is_array($zone) && isset($zone['communes'])) {
    foreach (array_merge($zone['communes'], isset($zone['bordure']) ? $zone['bordure'] : array()) as $c) $communes[$c] = 1;
    $plafond = isset($zone['plafond_ouverture']) ? (int) $zone['plafond_ouverture'] : null;
}

$autres = array();
foreach (glob(__DIR__ . '/recherches/*', GLOB_ONLYDIR) as $dir) {
    $x = basename($dir); if ($x === $r) continue;
    $da = donnees_lire($x);
    foreach ($da['biens'] as $b) if (isset($b['liens']) && is_array($b['liens'])) foreach ($b['liens'] as $l) if (isset($l['url'])) $autres[donnees_cle_url($l['url'])] = array('r' => $x, 'id' => $b['id'], 'num' => isset($b['num']) ? $b['num'] : null);
}

$sortie = array('nouvelles' => array(), 'a_rouvrir' => array(), 'suivies' => array(), 'ecartees' => 0, 'ecartees_auto' => array('hors_zone' => 0, 'budget' => 0), 'autre_recherche' => array());
$auto = array();
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
        if ($budget && $prix !== null && isset($v['prix']) && $v['prix'] !== null && $prix < (int) $v['prix'] && ($plafond === null || $prix <= $plafond)) {
            $sortie['a_rouvrir'][] = array('url' => $url, 'prix_note' => $v['prix'], 'prix' => $prix, 'motif' => $v['motif']);
        } else $sortie['ecartees']++;
    } elseif ($communes && preg_match($OF, $url, $m) && !isset($communes[$m[1]])) {
        $auto[] = array('url' => $url, 'motif' => 'Hors zone : ' . ucwords(str_replace('-', ' ', $m[1])) . ' (tri automatique)', 'prix' => $prix);
        $sortie['ecartees_auto']['hors_zone']++;
    } elseif ($plafond && $prix !== null && $prix > $plafond && preg_match($OF, $url)) {
        $auto[] = array('url' => $url, 'motif' => 'Au-dessus du budget : ' . $prix . ' € (tri automatique)', 'prix' => $prix);
        $sortie['ecartees_auto']['budget']++;
    } else {
        $sortie['nouvelles'][] = $prix !== null ? array('url' => $url, 'prix' => $prix) : array('url' => $url);
        if (isset($autres[$k])) $sortie['autre_recherche'][] = array('url' => $url) + $autres[$k];
    }
}
// Toujours en ligne : noté sans toucher au reste (sert au choix des fiches à revérifier, voir suivi.php).
if ($vuesEnListe || $auto) {
    $res = donnees_modifier($r, function (&$d) use ($vuesEnListe, $auto) {
        $auj = donnees_aujourdhui();
        foreach ($d['biens'] as $i => $b) if (isset($vuesEnListe[$b['id']])) $d['biens'][$i]['vu_en_liste_le'] = $auj;
        $ix = donnees_index($d);
        foreach ($auto as $e) { if (isset($ix[donnees_cle_url($e['url'])])) continue; $e['le'] = $auj; $d['vus'][] = $e; }
        return true;
    }, false);
    if (is_array($res) && isset($res['erreur'])) { http_response_code(500); echo json_encode(array('erreur' => $res['erreur']), JSON_UNESCAPED_UNICODE); exit; }
}
echo json_encode($sortie, JSON_UNESCAPED_UNICODE);
