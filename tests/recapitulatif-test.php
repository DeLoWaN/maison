<?php
// Tests de recap_calculer, recap_marquer, recap_html et recap_objet. Lancement : php tests/recapitulatif-test.php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../maison/recapitulatif-lib.php';

$echecs = 0;
function verifier($nom, $attendu, $obtenu) {
    global $echecs;
    if ($attendu === $obtenu) { echo "ok    $nom\n"; return; }
    $echecs++;
    echo "ÉCHEC $nom\n  attendu : " . json_encode($attendu, JSON_UNESCAPED_UNICODE) . "\n  obtenu  : " . json_encode($obtenu, JSON_UNESCAPED_UNICODE) . "\n";
}
function bien($id, $champs = array()) {
    return array_merge(array(
        'id' => $id, 'num' => 7, 'etat' => 'active', 'verdict' => 'top', 'titre' => 'Longère', 'commune' => 'Verson',
        'prix' => 300000, 'prix_hist' => array(array('d' => '2026-10-20', 'p' => 300000)), 'repere_le' => '2026-10-20',
        'crit' => array('zone' => array('ok', 'Verson'), 'rdc' => array('warn', 'une chambre')),
        'liens' => array(array('site' => 'Bien\'ici', 'url' => 'https://www.bienici.com/annonce/1', 'ok' => true)),
        'photo_url' => 'https://exemple.fr/1.jpg', 'surface' => 120
    ), $champs);
}
function recherche($biens, $statuts = array(), $id = 'maison') {
    return array('id' => $id, 'nom' => 'Maisons', 'd' => array('biens' => $biens, 'cles_crit' => array('zone', 'rdc')), 'statuts' => $statuts);
}
function ids($liste) { return array_map(function ($x) { return $x['id']; }, $liste); }
$etat = array('depuis' => '2026-11-02', 'biens' => array());
$base = 'https://damien-gustave.fr/maison/';

// Nouvelles maisons.
$c = recap_calculer(array(recherche(array(bien('avant'), bien('neuve', array('repere_le' => '2026-11-02'))))), $etat);
verifier('maison repérée le jour de « depuis » : nouvelle', array('neuve'), ids($c['sections'][0]['nouvelles']));
verifier('maison repérée avant « depuis » : déjà envoyée', 1, $c['nb']['nouvelles']);
verifier('une Nouvelle maison déclenche l\'envoi', true, $c['envoyer']);
verifier('marque d\'une Nouvelle maison : nouveau et prix', array('nouveau' => true, 'prix' => 300000), $c['marques']['maison/neuve']);
$apres = recap_marquer($etat, $c['marques']);
$c2 = recap_calculer(array(recherche(array(bien('avant'), bien('neuve', array('repere_le' => '2026-11-02'))))), $apres);
verifier('Nouvelle maison envoyée : plus jamais renvoyée', false, $c2['envoyer']);
verifier('marquer deux fois ne change rien', $apres, recap_marquer($apres, $c['marques']));
$c = recap_calculer(array(recherche(array(bien('neuve', array('repere_le' => '2026-11-03'))), array('neuve' => array('statut' => 'pas_interesses')))), $etat);
verifier('maison « pas intéressés » : absente', 0, $c['nb']['nouvelles']);
$c = recap_calculer(array(recherche(array(bien('neuve', array('repere_le' => '2026-11-03', 'etat' => 'ecartee'))))), $etat);
verifier('maison écartée : pas nouvelle', 0, $c['nb']['nouvelles']);

// Baisses de prix.
$baissee = bien('b', array('prix' => 280000, 'prix_hist' => array(array('d' => '2026-10-20', 'p' => 300000), array('d' => '2026-11-03', 'p' => 280000))));
$c = recap_calculer(array(recherche(array($baissee))), $etat);
verifier('prix plus bas qu\'avant « depuis » : Baisse de prix', array('b'), ids($c['sections'][0]['baisses']));
verifier('ancien prix : le prix d\'avant « depuis »', 300000, $c['sections'][0]['baisses'][0]['ancien_prix']);
verifier('une Baisse de prix déclenche l\'envoi', true, $c['envoyer']);
$etatPrix = array('depuis' => '2026-11-02', 'biens' => array('maison/b' => array('prix' => 290000)));
$c = recap_calculer(array(recherche(array($baissee))), $etatPrix);
verifier('ancien prix : le dernier prix envoyé', 290000, $c['sections'][0]['baisses'][0]['ancien_prix']);
$c = recap_calculer(array(recherche(array($baissee))), array('depuis' => '2026-11-02', 'biens' => array('maison/b' => array('prix' => 280000))));
verifier('prix égal au dernier envoyé : rien', false, $c['envoyer']);
$c = recap_calculer(array(recherche(array(bien('h', array('prix' => 320000, 'prix_hist' => array(array('d' => '2026-10-20', 'p' => 300000), array('d' => '2026-11-03', 'p' => 320000))))))), $etat);
verifier('hausse de prix : rien', false, $c['envoyer']);
// Cas réel (n° 33) : deux lignes le même jour, la dernière n'est pas le prix actuel, aucun changement depuis.
$desordre = bien('e', array('prix' => 175000, 'prix_hist' => array(array('d' => '2026-10-19', 'p' => 175000), array('d' => '2026-10-19', 'p' => 196100))));
$c = recap_calculer(array(recherche(array($desordre))), $etat);
verifier('aucun prix daté depuis « depuis » : pas de Baisse de prix', false, $c['envoyer']);
$c = recap_calculer(array(recherche(array($baissee), array('b' => array('statut' => 'pas_interesses')))), $etat);
verifier('Baisse de prix d\'une maison « pas intéressés » : absente', false, $c['envoyer']);
$c = recap_calculer(array(recherche(array(bien('n', array('repere_le' => '2026-11-03', 'prix' => 250000, 'prix_hist' => array(array('d' => '2026-11-03', 'p' => 270000), array('d' => '2026-11-04', 'p' => 250000))))))), $etat);
verifier('maison nouvelle et déjà baissée : seulement nouvelle', array(1, 0), array($c['nb']['nouvelles'], $c['nb']['baisses']));

// Maisons vendues ou retirées.
$vendue = bien('v', array('etat' => 'retiree', 'vendue' => true, 'retiree_le' => '2026-11-03'));
$c = recap_calculer(array(recherche(array($vendue))), $etat);
verifier('maison vendue après « depuis » : listée', array('v'), ids($c['sections'][0]['fins']));
verifier('maisons vendues seules : pas d\'envoi', false, $c['envoyer']);
$c = recap_calculer(array(recherche(array($vendue))), recap_marquer($etat, $c['marques']));
verifier('vente déjà envoyée : plus listée', 0, $c['nb']['fins']);
$c = recap_calculer(array(recherche(array(bien('v', array('etat' => 'retiree', 'retiree_le' => '2026-10-30'))))), $etat);
verifier('maison retirée avant « depuis » : déjà envoyée', 0, $c['nb']['fins']);
$c = recap_calculer(array(recherche(array(bien('v', array('etat' => 'retiree', 'retiree_le' => '2026-11-04', 'repere_le' => '2026-11-03'))))), $etat);
verifier('maison jamais montrée puis retirée : pas annoncée', 0, $c['nb']['fins']);

// HTML.
$neuves = array();
for ($i = 1; $i <= 12; $i++) $neuves[] = bien('n' . $i, array('repere_le' => '2026-11-03', 'prix' => 200000 + $i * 1000, 'verdict' => $i === 12 ? 'top' : 'presque'));
$neuves[] = bien('hors1', array('repere_le' => '2026-11-03', 'verdict' => 'hors', 'crit' => array('zone' => array('ko', 'trop loin'), 'rdc' => array('ok', 'oui'))));
$c = recap_calculer(array(recherche($neuves), recherche(array($baissee, $vendue), array(), 'extensible')), $etat);
$html = recap_html($c, $base, RECAP_FICHES_MAX, $base . 'recapitulatif.php?id=20261103-093000');
verifier('10 fiches complètes au plus', 10, substr_count($html, 'alt="Photo de la maison"'));
verifier('la fiche top passe avant les presque, même plus chère', true, strpos($html, 'photo.php?r=maison&amp;id=n12') < strpos($html, 'photo.php?r=maison&amp;id=n1"'));
verifier('la presque la plus chère est omise', false, strpos($html, 'id=n11"') !== false);
verifier('lien vers les 2 autres maisons', true, strpos($html, 'Voir les 2 autres maisons') !== false && strpos($html, 'recapitulatif.php?id=20261103-093000') !== false);
verifier('maison hors : une ligne avec son critère ko', true, strpos($html, '✗ Lieu : trop loin') !== false);
verifier('tous les critères dans la fiche', true, strpos($html, '<b>Chambre en bas</b> : une chambre') !== false);
verifier('Baisse de prix : ancien et nouveau prix', true, strpos($html, "280\xC2\xA0000\xC2\xA0€</b> <span style=\"color:#55626b;text-decoration:line-through\">300\xC2\xA0000\xC2\xA0€") !== false);
verifier('maison vendue : une ligne', true, strpos($html, 'Vendue le 03/11') !== false);
verifier('lien vers la fiche du site', true, strpos($html, $base . '?r=extensible#b') !== false);
verifier('le courriel reste loin de la limite de Gmail (102 Ko)', true, strlen($html) < 60000);
verifier('aucun raccourci background, retiré par certaines messageries', false, strpos($html, 'background:') !== false);
$page = recap_html($c, $base, null, '');
verifier('page complète : toutes les fiches', 12, substr_count($page, 'alt="Photo de la maison"'));
verifier('texte échappé', true, strpos(recap_html(recap_calculer(array(recherche(array(bien('x', array('repere_le' => '2026-11-03', 'titre' => '<b>pierre</b>'))))), $etat), $base, 10, ''), '&lt;b&gt;pierre') !== false);
echo 'Taille du courriel de test : ' . strlen($html) . " octets\n";

// Objet.
verifier('objet au pluriel', 'Maisons : 3 nouvelles, 2 baisses de prix — 02/11', recap_objet(array('nouvelles' => 3, 'baisses' => 2, 'fins' => 0), '2026-11-02'));
verifier('objet au singulier', 'Maisons : 1 baisse de prix, 1 vendue ou retirée — 02/11', recap_objet(array('nouvelles' => 0, 'baisses' => 1, 'fins' => 1), '2026-11-02'));

// Courriel envoyé par le serveur.
$famille = function ($x) { return in_array($x, array('a@exemple.fr', 'b@exemple.fr'), true); };
$m = recap_courriel('Maisons : 1 nouvelle — 02/11', $html, $base . 'recapitulatif.php?id=20261103-093000', array('a@exemple.fr', 'b@exemple.fr'), 'a@exemple.fr', $famille);
list($entetes, $corps) = explode("\r\n\r\n", $m['message'], 2);
verifier('destinataires de l\'enveloppe SMTP', array('a@exemple.fr', 'b@exemple.fr'), $m['destinataires']);
verifier('tous les destinataires dans « À »', 1, preg_match('/^To: a@exemple\.fr, b@exemple\.fr\r\n/', $entetes));
verifier('objet encodé en UTF-8', true, (bool) preg_match('/\r\nSubject: (\S+)\r\n/', $entetes, $o) && mb_decode_mimeheader($o[1]) === 'Maisons : 1 nouvelle — 02/11');
verifier('en-têtes Date et Message-ID', 1, preg_match('/\r\nDate: .+\r\nMessage-ID: <\d{14}\.[0-9a-f]{16}@damien-gustave\.fr>\r\n/', $entetes));
verifier('expéditeur et adresse de réponse', true, strpos($entetes, '<maison@damien-gustave.fr>') !== false && strpos($entetes, "Reply-To: a@exemple.fr") !== false);
verifier('lignes du message en CRLF', 0, preg_match('/(?<!\r)\n/', $m['message']));
preg_match_all('#Content-Type: (text/\w+); charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n([A-Za-z0-9+/=\r\n]+)#', $corps, $parties);
verifier('deux parties : texte brut puis HTML', array('text/plain', 'text/html'), $parties[1]);
$texte = base64_decode($parties[2][0]); $htmlEnvoye = base64_decode($parties[2][1]);
verifier('le HTML envoyé contient le Récapitulatif entier', true, strpos($htmlEnvoye, $html) !== false);
verifier('les photos sont des liens directs vers photo.php', true, strpos($htmlEnvoye, 'src="' . $base . 'photo.php?r=maison&amp;id=n12"') !== false);
verifier('texte brut : sans balise, avec le lien de la page complète', true, strpos($texte, '<') === false && strpos($texte, 'recapitulatif.php?id=20261103-093000') !== false && strpos($texte, '280') !== false);
verifier('la frontière ferme le message', true, substr($m['message'], -4) === "--\r\n");
foreach (array('inconnu hors de la famille' => array(array('a@exemple.fr', 'x@exemple.fr'), 'a@exemple.fr'),
               'adresse de réponse hors de la famille' => array(array('a@exemple.fr'), 'x@exemple.fr'),
               'aucun destinataire' => array(array(), 'a@exemple.fr'),
               'destinataire qui n\'est pas une adresse' => array(array('a@exemple.fr\r\nBcc: x@exemple.fr'), 'a@exemple.fr')) as $nom => $cas) {
    try { recap_courriel('o', $html, '', $cas[0], $cas[1], $famille); $leve = false; } catch (InvalidArgumentException $e) { $leve = true; }
    verifier('refusé : ' . $nom, true, $leve);
}

echo $echecs ? "\n$echecs test(s) en échec.\n" : "\nTous les tests passent.\n";
exit($echecs ? 1 : 0);
