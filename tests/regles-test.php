<?php
// Tests de donnees_regles_bien, donnees_regles, donnees_zone, donnees_communes_zone, donnees_fusionner_biens et donnees_ecrire_json. Lancement : php tests/regles-test.php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../maison/donnees-lib.php';

$echecs = 0;
function verifier($nom, $attendu, $obtenu) {
    global $echecs;
    if ($attendu === $obtenu) { echo "ok    $nom\n"; return; }
    $echecs++;
    echo "ÉCHEC $nom\n  attendu : " . json_encode($attendu, JSON_UNESCAPED_UNICODE) . "\n  obtenu  : " . json_encode($obtenu, JSON_UNESCAPED_UNICODE) . "\n";
}
function crit($ko = array(), $cles = array('zone', 'pierre', 'rdc', 'ch', 'terrain', 'dep', 'wc', 'budget')) {
    $c = array();
    foreach ($cles as $k) $c[$k] = array(in_array($k, $ko, true) ? 'ko' : 'ok', 'texte');
    return $c;
}
$clesExtensible = array('zone', 'pierre', 'extension', 'ch', 'terrain', 'dep', 'wc', 'budget');
$refuse = function ($x) { return $x !== true; };

// Règles.
verifier('bien conforme accepté', true, donnees_regles_bien(array('prix' => 300000, 'verdict' => 'top', 'crit' => crit()), 385000));
verifier('prix au plafond accepté', true, donnees_regles_bien(array('prix' => 385000, 'verdict' => 'top', 'crit' => crit()), 385000));
verifier('prix au-dessus du plafond refusé', true, $refuse(donnees_regles_bien(array('prix' => 390000, 'verdict' => 'hors', 'crit' => crit(array('budget'))), 385000)));
verifier('sans plafond, prix élevé accepté', true, donnees_regles_bien(array('prix' => 900000, 'verdict' => 'top', 'crit' => crit()), null));
verifier('prix inconnu accepté', true, donnees_regles_bien(array('prix' => null, 'verdict' => 'top', 'crit' => crit()), 385000));
$reglesMaison = donnees_regles('maison');
verifier('aucune chambre au RDC refusé dans maison', true, $refuse(donnees_regles_bien(array('prix' => 200000, 'verdict' => 'hors', 'crit' => crit(array('rdc'))), 385000, null, $reglesMaison)));
verifier('sans règles de recherche, rdc ko n\'est pas refusé', true, donnees_regles_bien(array('prix' => 200000, 'verdict' => 'hors', 'crit' => crit(array('rdc'))), 385000));
verifier('top avec un ko refusé', true, $refuse(donnees_regles_bien(array('prix' => 200000, 'verdict' => 'top', 'crit' => crit(array('terrain'))), 385000)));
verifier('hors avec un ko accepté', true, donnees_regles_bien(array('prix' => 200000, 'verdict' => 'hors', 'crit' => crit(array('terrain'))), 385000));
verifier('presque avec un ko accepté', true, donnees_regles_bien(array('prix' => 200000, 'verdict' => 'presque', 'crit' => crit(array('wc'))), 385000));
verifier('hors sans ko refusé', true, $refuse(donnees_regles_bien(array('prix' => 200000, 'verdict' => 'hors', 'crit' => crit()), 385000)));
verifier('deux ko refusé', true, $refuse(donnees_regles_bien(array('prix' => 200000, 'verdict' => 'hors', 'crit' => crit(array('terrain', 'wc'))), 385000)));
$msg = donnees_regles_bien(array('prix' => 200000, 'verdict' => 'hors', 'crit' => crit(array('rdc'))), 385000, null, $reglesMaison);
verifier('le refus rdc dit quoi faire', true, strpos($msg, 'opération vu') !== false);
verifier('maison : une maison sans critère extension est acceptée', true, donnees_regles_bien(array('prix' => 300000, 'verdict' => 'top', 'crit' => crit()), 385000, null, $reglesMaison));

// Règles de la recherche « extensible ».
$reglesExt = donnees_regles('extensible');
$ext = function ($ko = array(), $verdict = 'top', $prix = 240000) use ($clesExtensible) { return array('prix' => $prix, 'verdict' => $verdict, 'crit' => crit($ko, $clesExtensible)); };
verifier('extensible : bien conforme accepté', true, donnees_regles_bien($ext(), 275000, null, $reglesExt));
verifier('extensible : pierre ko refusée', true, $refuse(donnees_regles_bien($ext(array('pierre'), 'hors'), 275000, null, $reglesExt)));
verifier('extensible : extension ko refusée', true, $refuse(donnees_regles_bien($ext(array('extension'), 'hors'), 275000, null, $reglesExt)));
verifier('extensible : le refus extension dit quoi faire', true, strpos((string) donnees_regles_bien($ext(array('extension'), 'hors'), 275000, null, $reglesExt), 'opération vu') !== false);
verifier('extensible : terrain ko, verdict hors accepté', true, donnees_regles_bien($ext(array('terrain'), 'hors'), 275000, null, $reglesExt));
verifier('extensible : critère rdc refusé même conforme', true, $refuse(donnees_regles_bien(array('prix' => 240000, 'verdict' => 'top', 'crit' => crit(array(), array_merge($clesExtensible, array('rdc')))), 275000, null, $reglesExt)));
verifier('extensible : le refus rdc renvoie vers maison', true, strpos((string) donnees_regles_bien(array('prix' => 240000, 'verdict' => 'top', 'crit' => crit(array(), array('rdc'))), 275000, null, $reglesExt), 'r=maison') !== false);
verifier('extensible : prix de 275000 accepté, 275001 refusé', array(true, true), array(donnees_regles_bien($ext(array(), 'top', 275000), 275000, null, $reglesExt), $refuse(donnees_regles_bien($ext(array('budget'), 'hors', 275001), 275000, null, $reglesExt))));
verifier('extensible : top avec un ko reste refusé', true, $refuse(donnees_regles_bien($ext(array('wc'), 'top'), 275000, null, $reglesExt)));
verifier('règles : recherche sans regles.json', array('ko_refuses' => array(), 'cles_interdites' => array()), donnees_regles('longere'));
verifier('règles : recherche inconnue', array('ko_refuses' => array(), 'cles_interdites' => array()), donnees_regles('inconnue'));
verifier('règles : maison refuse seulement rdc ko', array('rdc'), array_keys($reglesMaison['ko_refuses']));
verifier('règles : extensible refuse pierre et extension ko', array('pierre', 'extension'), array_keys($reglesExt['ko_refuses']));

// Zone partagée.
verifier('zone : maison fournit sa propre zone', 'maison', donnees_zone_source('maison'));
verifier('zone : extensible prend celle de maison', 'maison', donnees_zone_source('extensible'));
verifier('zone : recherche inconnue sans zone', null, donnees_zone('inconnue'));
verifier('zone : mêmes communes pour les deux recherches', donnees_zone('maison'), donnees_zone('extensible'));
verifier('zone : mêmes communes indexées', donnees_communes_zone('maison'), donnees_communes_zone('extensible'));
verifier('zone : plafond de maison', 385000, donnees_plafond('maison'));
verifier('zone : plafond propre à extensible', 275000, donnees_plafond('extensible'));
verifier('zone : recherche inconnue sans plafond', null, donnees_plafond('inconnue'));

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
foreach (array('Seulline', 'Tracy-Bocage', 'Cahagnes', 'Amayé-sur-Seulles', 'Ducy-Sainte-Marguerite') as $c)
    verifier("commune ajoutée à la zone acceptée : $c", true, donnees_regles_bien($bien($c), 385000, $zone));
verifier('Vacognes-Neuilly, à plus de 16 minutes, refusée', true, $refuse(donnees_regles_bien($bien('Vacognes-Neuilly'), 385000, $zone)));
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
    'photo_url' => 'https://p/1', 'adresse' => array('confiance' => 'forte', 'texte' => 'X'),
    'indices' => array('kwh' => '230', 'depense' => 'entre 1 960 € et 2 710 €'));
$f = donnees_fusionner_biens($g, $a);
verifier('fusion : id, numéro, verdict et titre du bien gardé', array('a', 79, 'presque', 'Gardé'), array($f['id'], $f['num'], $f['verdict'], $f['titre']));
verifier('fusion : liens réunis sans doublon d\'adresse', 2, count($f['liens']));
verifier('fusion : historique des prix réuni et trié', array('2026-10-18', '2026-10-19'), array($f['prix_hist'][0]['d'], $f['prix_hist'][1]['d']));
verifier('fusion : repéré au plus tôt, vérifié au plus tard', array('2026-10-18', '2026-10-22'), array($f['repere_le'], $f['verifie_le']));
verifier('fusion : photo du doublon reprise', 'https://p/1', $f['photo_url']);
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

// Index des adresses d'annonce après une maj de liens.
$dIx = array('biens' => array(array('id' => 'a', 'liens' => array(array('url' => 'https://www.safti.fr/1'), array('url' => 'https://www.seloger.com/2')))), 'vus' => array());
$dIx['biens'][0]['liens'] = array(array('url' => 'https://www.seloger.com/2'));
$ixApres = donnees_index($dIx);
verifier('index : un lien retiré d\'un bien n\'y est plus', array(false, true),
    array(isset($ixApres[donnees_cle_url('https://www.safti.fr/1')]), isset($ixApres[donnees_cle_url('https://www.seloger.com/2')])));

// Écriture de la liste : une seule photo par bien, le champ photos n'est pas enregistré.
$fichierEssai = tempnam(sys_get_temp_dir(), 'regles');
$ecrit = donnees_ecrire_json($fichierEssai, array('biens' => array(
    array('id' => 'a', 'photo_url' => 'https://p/1', 'photos' => array('https://p/1', 'https://p/2')),
    array('id' => 'b'))));
$relu = json_decode((string) file_get_contents($fichierEssai), true);
@unlink($fichierEssai);
verifier('écriture : réussie', true, $ecrit);
verifier('écriture : photos retiré, photo_url gardé', array(false, 'https://p/1', 'b'),
    array(isset($relu['biens'][0]['photos']), $relu['biens'][0]['photo_url'], $relu['biens'][1]['id']));

echo $echecs ?"\n$echecs échec(s)\n" : "\nTous les tests passent.\n";
exit($echecs ? 1 : 0);
