<?php
// Tests de fusion-lib.php. Lancement : php tests/fusion-test.php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../maison/fusion-lib.php';

$echecs = 0;
function verifier($nom, $attendu, $obtenu) {
    global $echecs;
    if ($attendu === $obtenu) { echo "ok    $nom\n"; return; }
    $echecs++;
    echo "ÉCHEC $nom\n  attendu : " . json_encode($attendu, JSON_UNESCAPED_UNICODE) . "\n  obtenu  : " . json_encode($obtenu, JSON_UNESCAPED_UNICODE) . "\n";
}
function bien($id, $num, $urls, $extra = array()) {
    $liens = array(); foreach ($urls as $u) $liens[] = array('site' => 'X', 'url' => $u, 'ok' => true);
    return array_merge(array('id' => $id, 'num' => $num, 'etat' => 'active', 'verdict' => 'top', 'titre' => $id, 'liens' => $liens,
        'crit' => array('pierre' => array('ok', 'Pierre'), 'rdc' => array('ok', 'Une chambre au RDC')), 'prix_hist' => array(), 'repere_le' => '2026-10-19'), $extra);
}
function index_par_num($res) { $o = array(); foreach ($res['data']['biens'] as $b) $o[$b['num']] = $b; return $o; }

$longere = array('biens' => array(
    // n° 20 et n° 101 : même maison que plainpied n° 73 (chaîne de liens partagés).
    bien('sommervieu-299', 20, array('https://www.seloger.com/a/1', 'https://www.bienici.com/a/2'),
        array('adresse' => array('confiance' => 'non_trouvee', 'essais' => 1), 'prix_hist' => array(array('d' => '2026-10-18', 'p' => 299000)))),
    bien('sommervieu-290', 101, array('https://www.leboncoin.fr/vi/3291465932.htm')),
    // n° 12 : pas de chambre au RDC, marqué « pas intéressés » par la famille.
    bien('maisoncelles-230', 12, array('https://ofi.example/12'), array('crit' => array('pierre' => array('ok', 'Pierre'), 'rdc' => array('warn', 'Pas de chambre au RDC')))),
    // n° 23 : pas de chambre au RDC, mais gardé visible.
    bien('maisoncelles-329', 23, array('https://ofi.example/23'), array('crit' => array('pierre' => array('ok', 'Pierre'), 'rdc' => array('warn', 'Buanderie transformable')))),
    // n° 9 : répartition inconnue.
    bien('evrecy-385', 9, array('https://ofi.example/9'), array('crit' => array('pierre' => array('ok', 'Pierre'), 'rdc' => array('unk', 'À confirmer')))),
    // n° 26 : deux chambres au RDC annoncées.
    bien('tourville-350', 26, array('https://ofi.example/26'), array('crit' => array('pierre' => array('ok', 'Pierre'), 'rdc' => array('ok', 'Deux chambres au RDC')))),
    // n° 1 : déjà retirée : reste « retiree ».
    bien('noyers-142', 1, array('https://ofi.example/1'), array('etat' => 'retiree', 'retiree_le' => '2026-10-18'))
),
    'vus' => array(array('url' => 'https://ofi.example/vu-a', 'motif' => 'Construite en 1977', 'le' => '2026-10-20'), array('url' => 'https://www.bienici.com/a/2?x=1', 'motif' => 'erreur', 'le' => '2026-10-20')),
    'sources' => array(array('nom' => 'SeLoger', 'statut' => 'ok', 'dernier_ok' => '2026-10-21'), array('nom' => 'PAP', 'statut' => 'echec', 'dernier_ok' => null)));
$plainpied = array('biens' => array(
    bien('sommervieu-299b', 73, array('https://www.bienici.com/a/2', 'https://www.leboncoin.fr/ad/ventes_immobilieres/3291465932'),
        array('crit' => array('pierre' => array('ok', 'Maison de 1960'), 'rdc' => array('unk', 'Une chambre au RDC indiquée')),
              'adresse' => array('confiance' => 'forte', 'texte' => 'ADRESSE-TEST'), 'photos' => array('https://p/1', 'https://p/2'),
              'prix_hist' => array(array('d' => '2026-10-19', 'p' => 299000)), 'repere_le' => '2026-10-18')),
    // n° 57 : autre maison, même id que n° 20 de longere.
    bien('sommervieu-299', 57, array('https://ofi.example/57'), array('crit' => array('pierre' => array('ok', 'Pavillon'), 'rdc' => array('ok', '3 chambres de plain-pied'))))
),
    'vus' => array(array('url' => 'https://ofi.example/vu-a', 'motif' => 'doublon', 'le' => '2026-10-21'), array('url' => 'https://ofi.example/vu-b', 'motif' => 'Neuf', 'le' => '2026-10-21')),
    'sources' => array(array('nom' => 'seloger', 'statut' => 'ok', 'dernier_ok' => '2026-10-19')));

$statuts = array('longere' => array('maisoncelles-230' => array('statut' => 'pas_interesses', 'le' => '2026-10-20 10:00')),
                 'plainpied' => array('sommervieu-299b' => array('statut' => 'a_visiter', 'le' => '2026-10-21 09:00')));
$avis = array('longere' => array(), 'plainpied' => array());

$res = fusion_calculer(array('longere' => $longere, 'plainpied' => $plainpied), $statuts, $avis);
$ix = index_par_num($res);
echo json_encode($res['rapport'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";

// Regroupement et bien gardé.
verifier('9 biens donnent 7 maisons', 7, count($res['data']['biens']));
verifier('le groupe de Sommervieu garde le bien avec un statut de la famille (n° 73)', true, isset($ix[73]) && !isset($ix[20]) && !isset($ix[101]));
verifier('alias n° 20 → 73 et n° 101 → 73', array('20' => 73, '101' => 73), $res['alias']);
verifier('liens du groupe réunis sans doublon (Leboncoin /vi/ et /ad/ = même annonce)', 3, count($ix[73]['liens']));
verifier('adresse la plus sûre gardée', 'forte', $ix[73]['adresse']['confiance']);
verifier('galerie gardée', 2, count($ix[73]['photos']));
verifier('repéré au plus tôt', '2026-10-18', $ix[73]['repere_le']);
verifier('historique des prix réuni', 2, count($ix[73]['prix_hist']));
verifier('type de maison jugé par longere', array('ok', 'Pierre'), $ix[73]['crit']['pierre']);
verifier('rdc jugé par plainpied : une chambre = warn', 'warn', $ix[73]['crit']['rdc'][0]);
verifier('statut de la famille repris sous l\'id gardé', 'a_visiter', $res['statuts']['sommervieu-299b']['statut']);

// Le groupe de Sommervieu garde l'id sommervieu-299b : n° 57 garde son id.
verifier('n° 57 garde son id', 'sommervieu-299', $ix[57]['id']);
verifier('carte des numéros sous maison/', 57, $res['carte']['maison/sommervieu-299']);
verifier('plain-pied annoncé = rdc ok', 'ok', $ix[57]['crit']['rdc'][0]);

// Id en double entre deux maisons différentes (sans statut de la famille : le plus petit numéro garde l'id).
$res2 = fusion_calculer(array('longere' => array('biens' => array(bien('sommervieu-299', 20, array('https://a/1')))),
    'plainpied' => array('biens' => array(bien('sommervieu-299', 57, array('https://a/2'))))), array(), array());
$ix2 = index_par_num($res2);
verifier('id en double : n° 20 garde l\'id', 'sommervieu-299', $ix2[20]['id']);
verifier('id en double : n° 57 renommé', 'sommervieu-299-n57', $ix2[57]['id']);
verifier('id en double : photos du n° 57 copiées sous le nouvel id', array('r' => 'plainpied', 'de' => 'sommervieu-299', 'vers' => 'sommervieu-299-n57'), $res2['photos'][1]);

// Règle « au moins une chambre au RDC ».
verifier('n° 12 sans chambre au RDC : écarté', 'ecartee', $ix[12]['etat']);
verifier('n° 12 critère rdc ko', 'ko', $ix[12]['crit']['rdc'][0]);
verifier('n° 12 garde son statut « pas intéressés »', 'pas_interesses', $res['statuts']['maisoncelles-230']['statut']);
verifier('n° 23 reste visible', 'active', $ix[23]['etat']);
verifier('n° 23 critère rdc ko', 'ko', $ix[23]['crit']['rdc'][0]);
verifier('n° 9 répartition inconnue : unk, reste actif', array('unk', 'active'), array($ix[9]['crit']['rdc'][0], $ix[9]['etat']));
verifier('n° 26 deux chambres au RDC : ok', 'ok', $ix[26]['crit']['rdc'][0]);
verifier('n° 1 retirée : reste retirée', 'retiree', $ix[1]['etat']);

// Annonces écartées et sources.
$urls = array(); foreach ($res['data']['vus'] as $v) $urls[] = $v['url'];
verifier('écartées réunies sans doublon, sans les liens d\'un bien', array('https://ofi.example/vu-a', 'https://ofi.example/vu-b'), $urls);
$src = array(); foreach ($res['data']['sources'] as $s) $src[strtolower($s['nom'])] = $s['dernier_ok'];
verifier('source : lecture complète la plus ancienne', array('seloger' => '2026-10-19', 'pap' => null), $src);

// Un bien sans numéro est refusé.
try { fusion_calculer(array('longere' => array('biens' => array(array('id' => 'x', 'liens' => array()))), 'plainpied' => array()), array(), array()); verifier('bien sans numéro refusé', true, false); }
catch (InvalidArgumentException $e) { verifier('bien sans numéro refusé', true, strpos($e->getMessage(), 'sans numéro') !== false); }

echo $echecs ? "\n$echecs échec(s)\n" : "\nTous les tests passent.\n";
exit($echecs ? 1 : 0);
