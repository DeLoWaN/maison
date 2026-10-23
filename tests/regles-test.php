<?php
// Tests de donnees_regles_bien, donnees_communes_zone et donnees_fusionner_biens. Lancement : php tests/regles-test.php
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

// Commune.
$zone = donnees_communes_zone('maison');
$bien = function ($commune) { return array('commune' => $commune, 'prix' => 300000, 'verdict' => 'top', 'crit' => crit()); };
verifier('liste de zone lue', true, is_array($zone) && count($zone) > 100);
verifier('commune de la zone acceptée', true, donnees_regles_bien($bien('Bayeux'), 385000, $zone));
verifier('commune de la bordure acceptée', true, donnees_regles_bien($bien('Amayé-sur-Orne'), 385000, $zone));
verifier('ancienne commune fusionnée acceptée', true, donnees_regles_bien($bien('Bretteville-l\'Orgueilleuse'), 385000, $zone));
verifier('accents et majuscules ignorés', true, donnees_regles_bien($bien('EVRECY'), 385000, $zone));
verifier('accents ajoutés ignorés', true, donnees_regles_bien($bien('épinay sur odon'), 385000, $zone));
verifier('espaces et apostrophe acceptés', true, donnees_regles_bien($bien('Val d\'Arry'), 385000, $zone));
verifier('texte libre refusé', true, $refuse(donnees_regles_bien($bien('Secteur Bayeux (commune à confirmer)'), 385000, $zone)));
verifier('Castillon refusé', true, $refuse(donnees_regles_bien($bien('Castillon'), 385000, $zone)));
verifier('Surrain refusé', true, $refuse(donnees_regles_bien($bien('Surrain'), 385000, $zone)));
verifier('commune vide refusée', true, $refuse(donnees_regles_bien($bien(''), 385000, $zone)));
verifier('commune absente refusée', true, $refuse(donnees_regles_bien(array('prix' => 300000, 'verdict' => 'top', 'crit' => crit()), 385000, $zone)));
verifier('sans liste, commune libre acceptée', true, donnees_regles_bien($bien('Castillon'), 385000, null));
verifier('le refus commune dit quoi faire', 'commune hors liste de zone : mettre une commune de la liste ; le doute va dans lieu, crit.zone et verifier',
    donnees_regles_bien($bien('Surrain'), 385000, $zone));

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

echo $echecs ? "\n$echecs échec(s)\n" : "\nTous les tests passent.\n";
exit($echecs ? 1 : 0);
