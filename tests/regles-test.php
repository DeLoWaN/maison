<?php
// Tests de donnees_regles_bien et donnees_fusionner_biens. Lancement : php tests/regles-test.php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../maison/donnees-lib.php';

$echecs = 0;
function verifier($nom, $attendu, $obtenu) {
    global $echecs;
    if ($attendu === $obtenu) { echo "ok    $nom\n"; return; }
    $echecs++;
    echo "ÉCHEC $nom\n  attendu : " . json_encode($attendu, JSON_UNESCAPED_UNICODE) . "\n  obtenu  : " . json_encode($obtenu, JSON_UNESCAPED_UNICODE) . "\n";
}
function crit($ko = array()) {
    $c = array();
    foreach (array('zone', 'pierre', 'rdc', 'ch', 'terrain', 'dep', 'wc', 'budget') as $k) $c[$k] = array(in_array($k, $ko, true) ? 'ko' : 'ok', 'texte');
    return $c;
}
$refuse = function ($x) { return $x !== true; };

// Règles.
verifier('bien conforme accepté', true, donnees_regles_bien(array('prix' => 300000, 'verdict' => 'top', 'crit' => crit()), 385000));
verifier('prix au plafond accepté', true, donnees_regles_bien(array('prix' => 385000, 'verdict' => 'top', 'crit' => crit()), 385000));
verifier('prix au-dessus du plafond refusé', true, $refuse(donnees_regles_bien(array('prix' => 390000, 'verdict' => 'hors', 'crit' => crit(array('budget'))), 385000)));
verifier('sans plafond, prix élevé accepté', true, donnees_regles_bien(array('prix' => 900000, 'verdict' => 'top', 'crit' => crit()), null));
verifier('prix inconnu accepté', true, donnees_regles_bien(array('prix' => null, 'verdict' => 'top', 'crit' => crit()), 385000));
verifier('aucune chambre au RDC refusé', true, $refuse(donnees_regles_bien(array('prix' => 200000, 'verdict' => 'hors', 'crit' => crit(array('rdc'))), 385000)));
verifier('top avec un ko refusé', true, $refuse(donnees_regles_bien(array('prix' => 200000, 'verdict' => 'top', 'crit' => crit(array('terrain'))), 385000)));
verifier('hors avec un ko accepté', true, donnees_regles_bien(array('prix' => 200000, 'verdict' => 'hors', 'crit' => crit(array('terrain'))), 385000));
verifier('presque avec un ko accepté', true, donnees_regles_bien(array('prix' => 200000, 'verdict' => 'presque', 'crit' => crit(array('wc'))), 385000));
verifier('hors sans ko refusé', true, $refuse(donnees_regles_bien(array('prix' => 200000, 'verdict' => 'hors', 'crit' => crit()), 385000)));
verifier('deux ko refusé', true, $refuse(donnees_regles_bien(array('prix' => 200000, 'verdict' => 'hors', 'crit' => crit(array('terrain', 'wc'))), 385000)));
$msg = donnees_regles_bien(array('prix' => 200000, 'verdict' => 'hors', 'crit' => crit(array('rdc'))), 385000);
verifier('le refus rdc dit quoi faire', true, strpos($msg, 'opération vu') !== false);

// Fusion.
$g = array('id' => 'a', 'num' => 79, 'verdict' => 'presque', 'titre' => 'Gardé', 'repere_le' => '2026-10-19', 'verifie_le' => '2026-10-20',
    'liens' => array(array('site' => 'OF', 'url' => 'https://www.ouestfrance-immo.com/x/1.htm', 'ok' => true)),
    'prix_hist' => array(array('d' => '2026-10-19', 'p' => 339000)), 'adresse' => array('confiance' => 'non_trouvee', 'essais' => 1),
    'indices' => array('kwh' => '229', 'terrain' => '1 000'));
$a = array('id' => 'b', 'num' => 114, 'verdict' => 'top', 'titre' => 'Doublon', 'repere_le' => '2026-10-18', 'verifie_le' => '2026-10-22', 'surface' => 133,
    'liens' => array(array('site' => 'SeLoger', 'url' => 'https://www.seloger.com/y', 'ok' => true), array('site' => 'OF bis', 'url' => 'https://ouestfrance-immo.com/x/1.htm?ref=2', 'ok' => true)),
    'prix_hist' => array(array('d' => '2026-10-18', 'p' => 345000), array('d' => '2026-10-19', 'p' => 339000)),
    'photos' => array('https://p/1', 'https://p/2'), 'photo_url' => 'https://p/1', 'adresse' => array('confiance' => 'forte', 'texte' => 'X'),
    'indices' => array('kwh' => '230', 'depense' => 'entre 1 960 € et 2 710 €'));
$f = donnees_fusionner_biens($g, $a);
verifier('fusion : id, numéro, verdict et titre du bien gardé', array('a', 79, 'presque', 'Gardé'), array($f['id'], $f['num'], $f['verdict'], $f['titre']));
verifier('fusion : liens réunis sans doublon d\'adresse', 2, count($f['liens']));
verifier('fusion : historique des prix réuni et trié', array('2026-10-18', '2026-10-19'), array($f['prix_hist'][0]['d'], $f['prix_hist'][1]['d']));
verifier('fusion : repéré au plus tôt, vérifié au plus tard', array('2026-10-18', '2026-10-22'), array($f['repere_le'], $f['verifie_le']));
verifier('fusion : galerie et photo du doublon reprises', array(2, 'https://p/1'), array(count($f['photos']), $f['photo_url']));
verifier('fusion : adresse la plus sûre', 'forte', $f['adresse']['confiance']);
verifier('fusion : surface reprise si absente', 133, $f['surface']);
verifier('fusion : indices du bien gardé complétés par ceux du doublon', array('229', '1 000', 'entre 1 960 € et 2 710 €'), array($f['indices']['kwh'], $f['indices']['terrain'], $f['indices']['depense']));

// Clé d'adresse d'annonce.
verifier('clé : deux annonces ADA restent distinctes', true,
    donnees_cle_url('https://www.ada-immo.com/fr/detail.htm?cle=140061747') !== donnees_cle_url('https://www.ada-immo.com/fr/detail.htm?cle=140061749'));
verifier('clé : ADA garde cle et ignore les autres paramètres', donnees_cle_url('https://www.ada-immo.com/fr/detail.htm?cle=140061749'),
    donnees_cle_url('http://ada-immo.com/fr/detail.htm?monnaie=2&cle=140061749#photos'));
verifier('clé : paramètres de suivi bienici ignorés', 'bienici.com/annonce/vente/x/maison/5pieces/abc',
    donnees_cle_url('https://www.bienici.com/annonce/vente/x/maison/5pieces/abc?q=%2Frecherche&utm_source=mail'));
verifier('clé : paramètres de suivi seloger ignorés', 'seloger.com/annonces/achat/maison/x/123.htm',
    donnees_cle_url('https://www.seloger.com/annonces/achat/maison/x/123.htm?projects=2&tracking=abc'));
verifier('clé : leboncoin par numéro d\'annonce', donnees_cle_url('https://www.leboncoin.fr/vi/2876543210.htm'),
    donnees_cle_url('https://www.leboncoin.fr/ad/ventes_immobilieres/2876543210?utm=x'));

echo $echecs ?"\n$echecs échec(s)\n" : "\nTous les tests passent.\n";
exit($echecs ? 1 : 0);
