<?php
// Récapitulatif : changements jamais envoyés à la famille (Nouvelles maisons, Baisses de prix,
// maisons vendues ou retirées), pour toutes les recherches actives, et son HTML. Compatible PHP 5.4.
// État partagé : <home>/maison-data/recapitulatif/etat.json
//   {"depuis": "AAAA-MM-JJ", "biens": {"<r>/<id>": {"nouveau": true, "prix": 289000, "fin": "AAAA-MM-JJ"}}}
//   depuis : jour du premier Récapitulatif préparé ; ce qui précède compte comme déjà envoyé.
//   prix   : dernier prix montré à la famille ; une Baisse de prix se mesure à partir de lui.
// Chaque Récapitulatif préparé est gardé dans <home>/maison-data/recapitulatif/<id>.json.
require_once __DIR__ . '/donnees-lib.php';
require_once __DIR__ . '/auth-lib.php';

// Libellés par défaut des critères, les mêmes que ceux de la page ; data.json « libelles » les remplace.
function recap_libelles_defaut() {
    return array('zone' => 'Lieu', 'pierre' => 'Caractère', 'rdc' => 'Chambre en bas', 'extension' => 'Extension en bas',
        'ch' => 'Chambres', 'terrain' => 'Terrain', 'dep' => 'Atelier, garage', 'wc' => 'WC séparés', 'budget' => 'Budget');
}
define('RECAP_FICHES_MAX', 10);

function recap_dossier() { return dirname(dirname(__DIR__)) . '/maison-data/recapitulatif'; }

// Prix d'avant $depuis dans l'historique (le dernier daté avant ce jour), ou null si aucun prix n'est daté de $depuis ou après.
// Sans cette condition, un historique dont la dernière ligne n'est pas le prix actuel donne une fausse Baisse de prix.
function recap_prix_avant($hist, $depuis) {
    $p = null; $change = false;
    if (is_array($hist)) foreach ($hist as $h) {
        if (!isset($h['d'], $h['p'])) continue;
        if ($h['d'] < $depuis) $p = (int) $h['p']; else $change = true;
    }
    return $change ? $p : null;
}

// Annonce à montrer : celle de l'agence vérifiée, sinon une vérifiée, sinon la première.
function recap_lien_annonce($b) {
    $liens = array();
    if (isset($b['liens']) && is_array($b['liens'])) foreach ($b['liens'] as $l) if (isset($l['url']) && preg_match('#^https?://#', $l['url'])) $liens[] = $l;
    foreach ($liens as $l) if (!empty($l['ok']) && isset($l['site']) && preg_match('/\(agence\)\s*$/', $l['site'])) return $l;
    foreach ($liens as $l) if (!empty($l['ok'])) return $l;
    return $liens ? $liens[0] : null;
}

// Copie d'un bien réduite à ce que montre le Récapitulatif ; crit suit l'ordre de la page.
function recap_resume_bien($b, $d) {
    $libelles = array_merge(recap_libelles_defaut(), isset($d['libelles']) && is_array($d['libelles']) ? $d['libelles'] : array());
    $cles = isset($d['cles_crit']) && is_array($d['cles_crit']) ? $d['cles_crit'] : array_values(array_diff(array_keys(recap_libelles_defaut()), array('extension')));
    $crit = array();
    foreach ($cles as $k) {
        $c = isset($b['crit'][$k]) && is_array($b['crit'][$k]) ? $b['crit'][$k] : array('unk', 'Non indiqué');
        $crit[] = array('libelle' => isset($libelles[$k]) ? $libelles[$k] : $k, 'etat' => $c[0], 'texte' => isset($c[1]) ? (string) $c[1] : '');
    }
    $hist = isset($b['prix_hist']) && is_array($b['prix_hist']) ? $b['prix_hist'] : array();
    return array(
        'id' => $b['id'], 'num' => isset($b['num']) ? $b['num'] : null,
        'titre' => isset($b['titre']) ? (string) $b['titre'] : '', 'commune' => isset($b['commune']) ? (string) $b['commune'] : '',
        'prix' => isset($b['prix']) ? $b['prix'] : null, 'premier_prix' => $hist && isset($hist[0]['p']) ? (int) $hist[0]['p'] : null,
        'surface' => isset($b['surface']) ? $b['surface'] : null, 'verdict' => isset($b['verdict']) ? $b['verdict'] : '',
        'photo' => !empty($b['photo']) || !empty($b['photo_url']), 'crit' => $crit, 'annonce' => recap_lien_annonce($b),
        'vendue' => !empty($b['vendue']), 'retiree_le' => isset($b['retiree_le']) ? $b['retiree_le'] : null
    );
}

/**
 * Changements jamais envoyés, sans rien écrire.
 * $recherches : liste de {id, nom, d (contenu de data.json), statuts (contenu de statuts.json)}.
 * $etat : contenu de etat.json, avec « depuis » rempli.
 * Renvoie {sections: [{r, nom, nouvelles, baisses, fins}], marques: {"<r>/<id>": champs d'état à poser une fois envoyé},
 *          nb: {nouvelles, baisses, fins}, envoyer}.
 * Une maison marquée « pas intéressés » n'apparaît jamais. envoyer est vrai s'il y a une Nouvelle maison ou une Baisse de prix.
 */
function recap_calculer($recherches, $etat) {
    $depuis = $etat['depuis'];
    $deja = isset($etat['biens']) && is_array($etat['biens']) ? $etat['biens'] : array();
    $sections = array(); $marques = array(); $nb = array('nouvelles' => 0, 'baisses' => 0, 'fins' => 0);
    foreach ($recherches as $rech) {
        $s = array('r' => $rech['id'], 'nom' => $rech['nom'], 'nouvelles' => array(), 'baisses' => array(), 'fins' => array());
        foreach ($rech['d']['biens'] as $b) {
            if (!isset($b['id'])) continue;
            if (isset($rech['statuts'][$b['id']]['statut']) && $rech['statuts'][$b['id']]['statut'] === 'pas_interesses') continue;
            $cle = $rech['id'] . '/' . $b['id'];
            $e = isset($deja[$cle]) ? $deja[$cle] : array();
            $etatBien = isset($b['etat']) ? $b['etat'] : 'active';
            $prix = isset($b['prix']) && $b['prix'] !== null ? (int) $b['prix'] : null;
            $repere = isset($b['repere_le']) ? (string) $b['repere_le'] : '';
            if ($etatBien === 'active' && empty($e['nouveau']) && $repere >= $depuis) {
                $s['nouvelles'][] = recap_resume_bien($b, $rech['d']);
                $marques[$cle] = array('nouveau' => true, 'prix' => $prix);
            } elseif ($etatBien === 'active' && $prix !== null) {
                $ref = isset($e['prix']) ? (int) $e['prix'] : recap_prix_avant(isset($b['prix_hist']) ? $b['prix_hist'] : null, $depuis);
                if ($ref !== null && $prix < $ref) {
                    $x = recap_resume_bien($b, $rech['d']);
                    $x['ancien_prix'] = $ref;
                    $s['baisses'][] = $x;
                    $marques[$cle] = array('prix' => $prix);
                }
            } elseif ($etatBien === 'retiree' && !empty($b['retiree_le']) && $b['retiree_le'] >= $depuis
                && (!isset($e['fin']) || $e['fin'] !== $b['retiree_le'])
                && ($repere < $depuis || !empty($e['nouveau']))) {
                // Une maison jamais montrée à la famille ne lui est pas annoncée vendue.
                $s['fins'][] = recap_resume_bien($b, $rech['d']);
                $marques[$cle] = array('fin' => $b['retiree_le']);
            }
        }
        $nb['nouvelles'] += count($s['nouvelles']); $nb['baisses'] += count($s['baisses']); $nb['fins'] += count($s['fins']);
        if ($s['nouvelles'] || $s['baisses'] || $s['fins']) $sections[] = $s;
    }
    return array('sections' => $sections, 'marques' => $marques, 'nb' => $nb, 'envoyer' => $nb['nouvelles'] + $nb['baisses'] > 0);
}

// Pose les marques d'un Récapitulatif envoyé dans l'état ; appliquer deux fois les mêmes marques ne change rien.
function recap_marquer($etat, $marques) {
    if (!isset($etat['biens']) || !is_array($etat['biens'])) $etat['biens'] = array();
    foreach ($marques as $cle => $champs) $etat['biens'][$cle] = array_merge(isset($etat['biens'][$cle]) ? $etat['biens'][$cle] : array(), $champs);
    return $etat;
}

// Ordre des fiches complètes : top avant presque, puis prix croissant (prix inconnu en dernier).
function recap_ordre_fiches($a, $b) {
    $va = $a['verdict'] === 'top' ? 0 : 1; $vb = $b['verdict'] === 'top' ? 0 : 1;
    if ($va !== $vb) return $va - $vb;
    $pa = $a['prix'] === null ? PHP_INT_MAX : (int) $a['prix']; $pb = $b['prix'] === null ? PHP_INT_MAX : (int) $b['prix'];
    return $pa === $pb ? 0 : ($pa < $pb ? -1 : 1);
}

function recap_e($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function recap_euros($p) { return $p === null ? 'prix non indiqué' : number_format((int) $p, 0, ',', "\xC2\xA0") . "\xC2\xA0€"; }
function recap_jour($d) { return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string) $d, $m) ? $m[3] . '/' . $m[2] : ''; }
function recap_url_fiche($base, $r, $id) { return $base . '?r=' . rawurlencode($r) . '#' . rawurlencode($id); }

// Couleurs et libellés de la page du site (index.html), répétés en styles en ligne : Gmail ignore les feuilles de style.
function recap_libelle_verdict($v) {
    $l = array('top' => array('À appeler en priorité', '#2e6b3f', '#e3efe5'), 'presque' => array('À étudier de plus près', '#855a0b', '#f5ead1'));
    return isset($l[$v]) ? $l[$v] : null;
}
function recap_plaque($num, $taille) {
    return $num ? '<span style="display:inline-block;background-color:#26323c;color:#f3f6f4;font-weight:bold;font-size:' . $taille . 'px;line-height:1.3;padding:1px 9px;border-radius:6px;margin-right:8px">' . recap_e($num) . '</span>' : '';
}
function recap_bouton($url, $texte, $plein) {
    return '<a href="' . recap_e($url) . '" style="display:inline-block;margin:4px 8px 0 0;padding:9px 16px;border-radius:8px;font-weight:bold;font-size:15px;text-decoration:none;'
        . ($plein ? 'background-color:#1d4f9c;color:#ffffff;border:2px solid #1d4f9c' : 'background-color:#ffffff;color:#1d4f9c;border:2px solid #1d4f9c') . '">' . $texte . '</a>';
}
function recap_titre_bloc($texte) {
    return '<tr><td style="padding:22px 0 8px;font-size:14px;font-weight:bold;letter-spacing:1px;text-transform:uppercase;color:#55626b">' . $texte . '</td></tr>';
}

function recap_html_fiche($x, $r, $base) {
    $sym = array('ok' => '✓', 'warn' => '!', 'ko' => '✗', 'unk' => '?');
    $photo = $x['photo']
        ? '<img src="' . recap_e($base . 'photo.php?r=' . rawurlencode($r) . '&id=' . rawurlencode($x['id'])) . '" width="600" alt="Photo de la maison" style="display:block;width:100%;max-width:600px;height:auto;border:0;border-radius:10px 10px 0 0">'
        : '<div style="padding:28px;text-align:center;color:#55626b;background-color:#e8ecea;border-radius:10px 10px 0 0">Pas de photo</div>';
    $v = recap_libelle_verdict($x['verdict']);
    $puce = $v ? '<span style="display:inline-block;margin-top:6px;padding:2px 10px;border-radius:12px;font-size:14px;font-weight:bold;color:' . $v[1] . ';background-color:' . $v[2] . '">' . $v[0] . '</span>' : '';
    $details = array();
    if ($x['surface']) $details[] = (int) $x['surface'] . "\xC2\xA0m²";
    if ($x['surface'] && $x['prix']) $details[] = recap_euros(round($x['prix'] / $x['surface'])) . ' le m²';
    $crit = '';
    foreach ($x['crit'] as $c) $crit .= '<tr><td width="24" valign="top" style="padding:6px 0;border-top:1px solid #eff2ef;font-weight:bold;color:#55626b">' . (isset($sym[$c['etat']]) ? $sym[$c['etat']] : '?') . '</td>'
        . '<td style="padding:6px 0;border-top:1px solid #eff2ef;font-size:15px"><b>' . recap_e($c['libelle']) . '</b> : ' . recap_e($c['texte']) . '</td></tr>';
    $boutons = ($x['annonce'] ? recap_bouton($x['annonce']['url'], 'Voir l\'annonce', true) : '') . recap_bouton(recap_url_fiche($base, $r, $x['id']), 'Voir la fiche sur le site', false);
    return '<tr><td style="padding:0 0 18px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#ffffff;border:1px solid #cfd6d2;border-radius:10px">'
        . '<tr><td>' . $photo . '</td></tr><tr><td style="padding:14px 16px 16px">'
        . '<div style="font-size:21px;font-weight:bold">' . recap_plaque($x['num'], 18) . recap_e($x['commune'] !== '' ? $x['commune'] : $x['titre']) . '</div>' . $puce
        . '<div style="margin:6px 0 2px;color:#55626b">' . recap_e($x['titre']) . '</div>'
        . '<div style="font-size:24px;font-weight:bold">' . recap_euros($x['prix']) . '</div>'
        . ($details ? '<div style="color:#55626b;font-size:15px">' . implode(' · ', $details) . '</div>' : '')
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:10px 0 8px">' . $crit . '</table>'
        . $boutons . '</td></tr></table></td></tr>';
}

// Une ligne d'une liste courte : numéro, commune, $texte, puis $dessous en petit, et le lien vers la fiche.
function recap_html_ligne($x, $r, $base, $texte, $dessous) {
    return '<tr><td style="padding:12px 16px;border-top:1px solid #eff2ef">'
        . '<div>' . recap_plaque($x['num'], 15) . '<b>' . recap_e($x['commune'] !== '' ? $x['commune'] : $x['titre']) . '</b> · ' . $texte . '</div>'
        . ($dessous !== '' ? '<div style="margin-top:2px;color:#55626b;font-size:14px">' . $dessous . '</div>' : '')
        . '<div style="margin-top:2px;font-size:14px"><a href="' . recap_e(recap_url_fiche($base, $r, $x['id'])) . '" style="color:#1d4f9c">Voir la fiche sur le site</a></div></td></tr>';
}
function recap_html_liste($lignes) {
    return '<tr><td><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#ffffff;border:1px solid #cfd6d2;border-radius:10px">' . $lignes . '</table></td></tr>';
}

/**
 * HTML du Récapitulatif. $base : adresse du site terminée par « / ».
 * $fichesMax : nombre de fiches complètes au plus, toutes recherches confondues ; null pour la page complète.
 * $urlPage : lien vers la page complète, montré quand des fiches complètes sont omises.
 */
function recap_html($calc, $base, $fichesMax, $urlPage) {
    // Les fiches complètes gardées sont les meilleures de toutes les recherches.
    $toutes = array();
    foreach ($calc['sections'] as $s) foreach ($s['nouvelles'] as $x) if ($x['verdict'] !== 'hors') $toutes[] = $x + array('_r' => $s['r']);
    usort($toutes, 'recap_ordre_fiches');
    $gardees = array();
    foreach ($toutes as $i => $x) if ($fichesMax === null || $i < $fichesMax) $gardees[$x['_r'] . '/' . $x['id']] = 1;
    $h = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" bgcolor="#eff2ef" style="background-color:#eff2ef"><tr><td align="center" style="padding:16px 8px">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;font-family:Verdana,Arial,Helvetica,sans-serif;font-size:16px;line-height:1.5;color:#1f2a32;text-align:left">'
        . '<tr><td bgcolor="#26323c" style="background-color:#26323c;color:#f3f6f4;padding:18px 20px;border-radius:10px">'
        . '<div style="font-size:14px;letter-spacing:1px;text-transform:uppercase;color:#b9c6cf">Recherche de maison</div>'
        . '<div style="font-size:22px;font-weight:bold">' . ucfirst(recap_resume_nb($calc['nb'])) . '</div></td></tr>';
    foreach ($calc['sections'] as $s) {
        $r = $s['r'];
        $h .= '<tr><td style="padding:26px 0 0;font-size:24px;font-weight:bold;border-bottom:3px solid #26323c">' . recap_e($s['nom']) . '</td></tr>';
        $fiches = array(); $hors = array(); $omises = 0;
        foreach ($s['nouvelles'] as $x) {
            if ($x['verdict'] === 'hors') $hors[] = $x;
            elseif (isset($gardees[$r . '/' . $x['id']])) $fiches[] = $x;
            else $omises++;
        }
        usort($fiches, 'recap_ordre_fiches');
        if ($fiches || $omises) {
            $h .= recap_titre_bloc('Nouvelles maisons (' . (count($fiches) + $omises) . ')');
            foreach ($fiches as $x) $h .= recap_html_fiche($x, $r, $base);
            if ($omises) $h .= '<tr><td style="padding:0 0 18px;text-align:center">' . recap_bouton($urlPage, 'Voir les ' . $omises . ' autres maisons', true) . '</td></tr>';
        }
        if ($hors) {
            $h .= recap_titre_bloc('Nouvelles, mais un critère ne va pas (' . count($hors) . ')');
            $l = '';
            foreach ($hors as $x) {
                $ko = array(); foreach ($x['crit'] as $c) if ($c['etat'] === 'ko') $ko[] = '✗ ' . recap_e($c['libelle'] . ' : ' . $c['texte']);
                $l .= recap_html_ligne($x, $r, $base, recap_euros($x['prix']), implode('<br>', $ko));
            }
            $h .= recap_html_liste($l);
        }
        if ($s['baisses']) {
            $h .= recap_titre_bloc('Baisses de prix (' . count($s['baisses']) . ')');
            $l = '';
            foreach ($s['baisses'] as $x) {
                $t = '<b>' . recap_euros($x['prix']) . '</b> <span style="color:#55626b;text-decoration:line-through">' . recap_euros($x['ancien_prix']) . '</span>'
                    . ' <span style="display:inline-block;padding:0 8px;border-radius:10px;font-size:14px;font-weight:bold;color:#2e6b3f;background-color:#e3efe5">−' . recap_euros($x['ancien_prix'] - $x['prix']) . '</span>';
                $dessous = $x['premier_prix'] !== null && $x['premier_prix'] > $x['ancien_prix'] ? 'Soit ' . recap_euros($x['premier_prix'] - $x['prix']) . ' de moins que le premier prix (' . recap_euros($x['premier_prix']) . ')' : '';
                $l .= recap_html_ligne($x, $r, $base, $t, $dessous);
            }
            $h .= recap_html_liste($l);
        }
        if ($s['fins']) {
            $h .= recap_titre_bloc('Vendues ou retirées (' . count($s['fins']) . ')');
            $l = '';
            foreach ($s['fins'] as $x) $l .= recap_html_ligne($x, $r, $base, recap_euros($x['prix']), ($x['vendue'] ? 'Vendue' : 'Annonce retirée') . ' le ' . recap_jour($x['retiree_le']));
            $h .= recap_html_liste($l);
        }
    }
    return $h . '<tr><td style="padding:24px 4px 0;color:#55626b;font-size:14px">Toutes les maisons, avec vos choix et vos avis : <a href="' . recap_e($base) . '" style="color:#1d4f9c">' . recap_e($base) . '</a></td></tr>'
        . '</table></td></tr></table>';
}

// Résumé des comptes, par exemple « 3 nouvelles, 2 baisses de prix ».
function recap_resume_nb($nb) {
    $p = array();
    if ($nb['nouvelles']) $p[] = $nb['nouvelles'] . ($nb['nouvelles'] > 1 ? ' nouvelles' : ' nouvelle');
    if ($nb['baisses']) $p[] = $nb['baisses'] . ($nb['baisses'] > 1 ? ' baisses de prix' : ' baisse de prix');
    if ($nb['fins']) $p[] = $nb['fins'] . ($nb['fins'] > 1 ? ' vendues ou retirées' : ' vendue ou retirée');
    return implode(', ', $p);
}

// Objet du courriel, par exemple « Maisons : 3 nouvelles, 2 baisses de prix — 02/11 ».
function recap_objet($nb, $jour) {
    return 'Maisons : ' . recap_resume_nb($nb) . ' — ' . recap_jour($jour);
}

// Boîte MX Plan d'OVH qui envoie le Récapitulatif ; OVH signe ses courriels avec DKIM. Personne ne lit cette boîte, d'où Reply-To.
define('RECAP_EXPEDITEUR', 'maison@damien-gustave.fr');
define('RECAP_SMTP', 'ssl://smtp.mail.ovh.net:465');

/** Identifiants SMTP de RECAP_EXPEDITEUR, posés par Damien dans <home>/maison-data/recapitulatif/smtp.json : {utilisateur, mot_de_passe}. Lève RuntimeException si le fichier manque ou est incomplet. */
function recap_smtp_identifiants() {
    $x = json_decode((string) @file_get_contents(recap_dossier() . '/smtp.json'), true);
    if (!is_array($x) || empty($x['utilisateur']) || empty($x['mot_de_passe'])) throw new RuntimeException('identifiants SMTP absents ou incomplets (maison-data/recapitulatif/smtp.json)');
    return $x;
}

function recap_entete_utf8($s) { return '=?UTF-8?B?' . base64_encode($s) . '?='; }

// Texte brut du courriel, pour les messageries qui n'affichent pas le HTML.
function recap_texte($html, $urlPage) {
    $t = preg_replace('#<(br|/tr|/div|/td)\b[^>]*>#i', "\n", $html);
    $t = html_entity_decode(strip_tags($t), ENT_QUOTES, 'UTF-8');
    $t = preg_replace("/[ \t]+/", ' ', $t);
    $t = trim(preg_replace("/\n\s*\n+/", "\n\n", $t));
    return $t . "\n\nLe Récapitulatif complet, avec les photos : " . $urlPage . "\n";
}

/**
 * Courriel du Récapitulatif pour smtp_envoyer() : {destinataires, message}, en texte brut et en HTML, encodé en UTF-8.
 * $a : destinataires, tous dans « À » ; $repondreA : adresse des réponses. message : en-têtes et corps, lignes en CRLF.
 * Lève InvalidArgumentException si un destinataire ou $repondreA n'est pas un compte de la famille ($autorise, auth_autorise par défaut).
 */
function recap_courriel($objet, $html, $urlPage, $a, $repondreA, $autorise = 'auth_autorise') {
    if (!is_array($a) || !$a) throw new InvalidArgumentException('aucun destinataire');
    foreach (array_merge($a, array($repondreA)) as $x) {
        if (!is_string($x) || !filter_var($x, FILTER_VALIDATE_EMAIL) || !call_user_func($autorise, $x)) throw new InvalidArgumentException('adresse hors de la famille : ' . (is_string($x) ? $x : gettype($x)));
    }
    $frontiere = 'recap-' . md5($objet . $html);
    // Gmail refuse un courriel sans Message-ID ; le serveur d'envoi d'OVH n'en ajoute pas toujours.
    $entetes = implode("\r\n", array(
        'To: ' . implode(', ', $a),
        'Subject: ' . recap_entete_utf8($objet),
        'Date: ' . date('r'),
        'Message-ID: <' . date('YmdHis') . '.' . bin2hex(openssl_random_pseudo_bytes(8)) . '@damien-gustave.fr>',
        'From: ' . recap_entete_utf8('Recherche de maison') . ' <' . RECAP_EXPEDITEUR . '>',
        'Reply-To: ' . $repondreA,
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $frontiere . '"',
    ));
    $partie = function ($type, $contenu) use ($frontiere) {
        return '--' . $frontiere . "\r\nContent-Type: " . $type . "; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($contenu)) . "\r\n";
    };
    $corps = $partie('text/plain', recap_texte($html, $urlPage))
        . $partie('text/html', '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head><body style="margin:0">' . $html . '</body></html>')
        . '--' . $frontiere . "--\r\n";
    return array('destinataires' => array_values($a), 'message' => $entetes . "\r\n\r\n" . $corps);
}

// Recherches actives (non archivées) avec leurs données et les statuts posés par la famille.
function recap_recherches() {
    $l = json_decode((string) @file_get_contents(__DIR__ . '/recherches.json'), true);
    $out = array();
    if (is_array($l)) foreach ($l as $x) {
        if (!isset($x['id']) || !donnees_recherche_valide($x['id']) || donnees_archivee($x['id']) !== false) continue;
        $st = json_decode((string) @file_get_contents(dirname(dirname(__DIR__)) . '/maison-data/' . $x['id'] . '/statuts.json'), true);
        $out[] = array('id' => $x['id'], 'nom' => isset($x['nom']) ? $x['nom'] : $x['id'], 'd' => donnees_lire($x['id']), 'statuts' => is_array($st) ? $st : array());
    }
    return $out;
}

// Modifie etat.json sous verrou : $fn reçoit l'état (avec « depuis », posé à aujourd'hui au premier appel) et le renvoie.
function recap_modifier_etat($fn) {
    $dir = recap_dossier();
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    $f = $dir . '/etat.json';
    $fp = fopen($f, 'c+');
    if (!$fp) return array('erreur' => 'état du récapitulatif illisible');
    flock($fp, LOCK_EX);
    $etat = json_decode(stream_get_contents($fp), true);
    if (!is_array($etat) || !isset($etat['depuis'])) $etat = array('depuis' => donnees_aujourdhui(), 'biens' => array());
    $etat = call_user_func($fn, $etat);
    ftruncate($fp, 0); rewind($fp); fwrite($fp, json_encode($etat, JSON_UNESCAPED_UNICODE)); fflush($fp);
    flock($fp, LOCK_UN); fclose($fp);
    return $etat;
}

function recap_id_valide($id) { return is_string($id) && preg_match('/^\d{8}-\d{6}$/', $id); }
function recap_fichier($id) { return recap_dossier() . '/' . $id . '.json'; }

// Garde les 60 derniers Récapitulatifs préparés.
function recap_nettoyer() {
    $l = glob(recap_dossier() . '/????????-??????.json');
    if (!$l || count($l) <= 60) return;
    sort($l);
    foreach (array_slice($l, 0, count($l) - 60) as $f) @unlink($f);
}
