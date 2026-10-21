<?php
// Ce que la veille doit faire sur les biens déjà suivis, calculé par le serveur.
// GET suivi.php?r=longere[&jours=3]
//   a_verifier : biens actifs à rouvrir. Un bien vu dans une liste de résultats aujourd'hui ou récemment
//                (nouveautes.php) ou vérifié il y a moins de `jours` jours n'a pas besoin d'être rouvert.
//   galerie    : biens actifs top ou presque sans galerie de photos (moins de 2 photos)
//   adresse    : biens actifs top ou presque à traiter à l'étape 6 bis, dans l'ordre de la consigne (au plus 8).
//                Les biens « hors » ne sont pas proposés : chercher leur adresse ou leur galerie ne sert pas aux parents.
//   sans_photo : biens actifs sans photo_url
require_once __DIR__ . '/auth-lib.php';
auth_exiger();
require_once __DIR__ . '/donnees-lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$r = isset($_GET['r']) ? (string) $_GET['r'] : '';
if (!donnees_recherche_valide($r)) { http_response_code(404); echo '{"erreur":"recherche inconnue"}'; exit; }
$jours = isset($_GET['jours']) ? max(0, min(14, (int) $_GET['jours'])) : 3;
$d = donnees_lire($r);
$auj = donnees_aujourdhui();
$limite = date('Y-m-d', strtotime('-' . $jours . ' days'));
$court = function ($b) {
    $liens = array(); if (isset($b['liens']) && is_array($b['liens'])) foreach ($b['liens'] as $l) if (isset($l['url'])) $liens[] = array('site' => isset($l['site']) ? $l['site'] : '', 'url' => $l['url']);
    return array('id' => $b['id'], 'num' => isset($b['num']) ? $b['num'] : null, 'commune' => isset($b['commune']) ? $b['commune'] : '', 'prix' => isset($b['prix']) ? $b['prix'] : null, 'liens' => $liens);
};
$out = array('a_verifier' => array(), 'galerie' => array(), 'adresse' => array(), 'sans_photo' => array(), 'jours' => $jours);
$adr = array(array(), array(), array(), array());
$rang = array('top' => 0, 'presque' => 1, 'hors' => 2);
foreach ($d['biens'] as $b) {
    if (!isset($b['etat']) || $b['etat'] !== 'active') continue;
    $v = isset($b['verifie_le']) ? (string) $b['verifie_le'] : '';
    $l = isset($b['vu_en_liste_le']) ? (string) $b['vu_en_liste_le'] : '';
    $dernier = $v > $l ? $v : $l;
    if ($dernier === '' || $dernier < $limite) { $x = $court($b); $x['dernier'] = $dernier ?: null; $out['a_verifier'][] = $x; }
    if (empty($b['photo_url'])) $out['sans_photo'][] = $court($b);
    if (isset($b['verdict']) && $b['verdict'] === 'hors') continue;
    if (!isset($b['photos']) || !is_array($b['photos']) || count($b['photos']) < 2) $out['galerie'][] = $court($b);
    $a = isset($b['adresse']) && is_array($b['adresse']) ? $b['adresse'] : null;
    $agence = false; if (isset($b['liens']) && is_array($b['liens'])) foreach ($b['liens'] as $li) if (isset($li['site']) && preg_match('/\(agence\)\s*$/', $li['site'])) $agence = true;
    if (isset($b['repere_le']) && $b['repere_le'] === $auj && !$a) { $adr[0][] = $court($b); }
    elseif (!$a) { $x = $court($b); $x['verdict'] = isset($b['verdict']) ? $b['verdict'] : ''; $adr[1][] = $x; }
    elseif ($a['confiance'] === 'non_trouvee' && (isset($a['essais']) ? (int) $a['essais'] : 1) < 3) {
        $x = $court($b); $x['essais'] = isset($a['essais']) ? $a['essais'] : 1; $x['preuves'] = isset($a['preuves']) ? $a['preuves'] : '';
        $x['agence_cherchee'] = $agence || (isset($a['preuves']) && stripos($a['preuves'], "annonce d'origine introuvable") !== false);
        $x['verdict'] = isset($b['verdict']) ? $b['verdict'] : '';
        // À faire en premier pour ce bien : l'annonce d'origine (methode-adresse.md, étape 1 bis), avant toute autre recherche.
        if (!$x['agence_cherchee']) $x['a_faire'] = "annonce d'origine sur le site de l'agence ou du notaire (methode-adresse.md, étape 1 bis), puis étape 2";
        $adr[$x['agence_cherchee'] ? 3 : 2][] = $x;
    }
}
usort($adr[1], function ($p, $q) use ($rang) { $a = isset($rang[$p['verdict']]) ? $rang[$p['verdict']] : 3; $b = isset($rang[$q['verdict']]) ? $rang[$q['verdict']] : 3; return $a - $b; });
// $adr[3] (annonce d'origine déjà cherchée) : seulement si une information nouvelle est apparue, ce que la veille seule peut juger.
// Jusqu'à 4 places sont gardées pour les biens « non trouvée » dont l'annonce d'origine n'a pas encore été cherchée :
// sans cette réserve, les biens nouveaux prenaient toutes les places et le rattrapage ne se faisait jamais.
usort($adr[2], function ($p, $q) use ($rang) { $a = isset($rang[$p['verdict']]) ? $rang[$p['verdict']] : 3; $b = isset($rang[$q['verdict']]) ? $rang[$q['verdict']] : 3; return $a - $b; });
$rattrapage = array_slice($adr[2], 0, 4);
$out['adresse'] = array_merge(array_slice(array_merge($adr[0], $adr[1]), 0, 8 - count($rattrapage)), $rattrapage);
// Les plus anciennement vérifiés d'abord.
usort($out['a_verifier'], function ($p, $q) { return strcmp((string) $p['dernier'], (string) $q['dernier']); });
echo json_encode($out, JSON_UNESCAPED_UNICODE);
