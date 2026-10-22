<?php
// Modifications de la liste d'une recherche, envoyées une à une par la veille : le serveur applique,
// contrôle et numérote. La veille n'a plus à relire ni renvoyer la liste complète.
// POST patch.php[?r=<recherche>]  {"ops": [ {...}, ... ]}   Réponse : {"ok":true, "resultats":[...], "nums":{id:num}, "maj":...}
// Opérations :
//   {"op":"ajout", "bien":{id, titre, commune, lieu, prix, verdict, crit, verifier, liens:[{site,url,ok}], photo_url, surface, adresse?, indices?}}
//   indices : valeurs brutes du script de fiche (outils.md, section 3) que lit scripts/adresse.py :
//             {kwh, co2, depense, date_dpe, terrain, terrain_exact?, dpe?, ges?}
//   {"op":"maj", "id":..., "champs":{...}, "verifie":true}   (prix modifié : ajouté à prix_hist ; etat "retiree" : retiree_le = aujourd'hui ;
//                                                                     etat "ecartee" avec ecartee_motif : ne remplit plus les critères)
//   {"op":"lien", "id":..., "site":..., "url":..., "ok":true}
//   {"op":"vu", "url":..., "motif":..., "prix":...}       {"op":"oublier_vu", "url":...}
//   {"op":"fusionner", "id":<bien gardé>, "avec":<doublon>}   (liens, prix, galerie et adresse réunis ; le numéro du doublon mène au bien gardé)
//   {"op":"source", "nom":..., "statut":"ok|partiel|echec|nt", "note":..., "dernier_ok":"AAAA-MM-JJ"|null}
// ajout et maj d'un bien actif sont refusés s'ils contredisent les règles de donnees_regles_bien (plafond de prix,
// chambre au rez-de-chaussée, verdict cohérent avec les critères « ko »).
//   {"op":"fin", "note_passage":...}   (maj = aujourd'hui, retrait des « vus » de plus de 90 jours)
// Une opération refusée n'empêche pas les autres ; chaque refus est expliqué dans resultats.
require_once __DIR__ . '/auth-lib.php';
auth_exiger();
require_once __DIR__ . '/donnees-lib.php';
require_once __DIR__ . '/numeros-lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo '{"erreur":"POST attendu"}'; exit; }
$r = donnees_recherche_demandee(isset($_GET['r']) ? $_GET['r'] : '');
if (!donnees_recherche_valide($r)) { http_response_code(404); echo '{"erreur":"recherche inconnue"}'; exit; }
donnees_refuser_si_archivee($r);
$brut = file_get_contents('php://input');
if (strlen($brut) > 600000) { http_response_code(413); echo '{"erreur":"trop gros : envoyez en plusieurs fois"}'; exit; }
$in = json_decode($brut, true);
if (!is_array($in) || !isset($in['ops']) || !is_array($in['ops'])) { http_response_code(400); echo '{"erreur":"format attendu : {\"ops\": [...]}"}'; exit; }
$ops = $in['ops'];

$VERDICTS = array('top', 'presque', 'hors');
// ecartee : bien qui ne remplit plus les critères (motif dans ecartee_motif), gardé pour l'historique de la famille.
$ETATS = array('active', 'retiree', 'ecartee');
$STATUTS = array('ok', 'partiel', 'echec', 'nt');
$CHAMPS = array('titre', 'commune', 'lieu', 'prix', 'verdict', 'crit', 'verifier', 'liens', 'photo_url', 'surface', 'adresse', 'etat', 'retiree_le', 'geo', 'note', 'verifie_le', 'ecartee_motif', 'indices');
$CRIT_ETATS = array('ok', 'warn', 'ko', 'unk');

function p_date($s) { return is_string($s) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s); }
function p_liens_ok($l) {
    if (!is_array($l) || !count($l)) return 'liens : au moins un lien {site, url, ok}';
    foreach ($l as $x) if (!is_array($x) || !isset($x['url']) || !preg_match('#^https?://[^\s"<>]+$#', (string) $x['url'])) return 'lien invalide : adresse https:// attendue';
    return true;
}
function p_crit_ok($c, $etats) {
    if (!is_array($c)) return 'crit : objet attendu';
    foreach ($c as $k => $v) if (!is_array($v) || count($v) < 2 || !in_array($v[0], $etats, true)) return 'crit.' . $k . ' : [ok|warn|ko|unk, texte] attendu';
    return true;
}
function p_adresse_ok($a) {
    if (!is_array($a) || !isset($a['confiance']) || !in_array($a['confiance'], array('forte', 'probable', 'non_trouvee'), true)) return 'adresse : confiance forte|probable|non_trouvee attendue';
    if ($a['confiance'] !== 'non_trouvee' && (!isset($a['texte']) || trim((string) $a['texte']) === '')) return 'adresse : texte attendu pour forte ou probable';
    return true;
}
function p_indices_ok($x) {
    if (!is_array($x)) return 'indices : objet attendu';
    foreach ($x as $k => $v) if (!in_array($k, array('kwh', 'co2', 'depense', 'date_dpe', 'terrain', 'terrain_exact', 'dpe', 'ges'), true)) return 'indices : clé inconnue ' . $k;
    return true;
}
// Une source non lue doit avoir une cause réelle : pas de « manque de temps » (la tâche n'a pas de limite de temps)
// ni de « pas tenté » sans raison. Ces deux excuses revenaient alors que les passages duraient 17 à 30 minutes.
function p_note_source($note) {
    if (preg_match('/(faute|manque|pas eu le|plus le|par manque)\s+(de\s+|du\s+)?temps|temps\s+(insuffisant|manquant|limit)/iu', $note))
        return "note refusée : il n'y a aucune limite de temps. Lis cette source ; elle ne peut rester non lue que pour une cause réelle et vérifiable (site bloqué, page en erreur, adresse de liste introuvable après avoir navigué depuis l'accueil), à écrire dans la note.";
    if (trim(preg_replace('/(pas|non)\s+(tent[ée]e?|fait|relu|lu)|(à|a)?\s*ce passage|[.\s()]/iu', '', $note)) === '')
        return 'note refusée : donne la vraie cause (ce qui a été essayé et ce qui a échoué), ou lis la source.';
    return true;
}
function p_sources_manquantes($r, $d, $auj) {
    $vues = array(); foreach ($d['sources'] as $s) if (isset($s['nom']) && isset($s['passage_le']) && $s['passage_le'] === $auj) $vues[strtolower($s['nom'])] = 1;
    $m = array(); foreach (donnees_sources_attendues($r) as $n) if (!isset($vues[strtolower($n)])) $m[] = $n;
    return $m;
}
function p_indice_bien($d, $id) { foreach ($d['biens'] as $i => $b) if (isset($b['id']) && $b['id'] === $id) return $i; return -1; }
// Retire des écartées les annonces données ; l'index (construit une seule fois par envoi) est tenu à jour.
function p_retirer_vus(&$d, &$ix, $urls) {
    $suppr = array();
    foreach ($urls as $u) { $k = donnees_cle_url($u); if (isset($ix[$k]) && $ix[$k]['type'] === 'vu') $suppr[$ix[$k]['i']] = 1; }
    if (!$suppr) return;
    $garde = array(); foreach ($d['vus'] as $i => $v) if (!isset($suppr[$i])) $garde[] = $v;
    $d['vus'] = $garde;
    $ix = donnees_index($d);
}

$resultats = array();
$nouveaux = array();
$fusions = array();
$plafond = donnees_plafond($r);
$res = donnees_modifier($r, function (&$d) use ($ops, &$resultats, &$nouveaux, &$fusions, $VERDICTS, $ETATS, $STATUTS, $CHAMPS, $CRIT_ETATS, $r, $plafond) {
    $auj = donnees_aujourdhui();
    $ix = donnees_index($d);
    foreach ($ops as $n => $o) {
        $op = is_array($o) && isset($o['op']) ? $o['op'] : '';
        $err = null;
        if ($op === 'ajout') {
            $b = isset($o['bien']) && is_array($o['bien']) ? $o['bien'] : null;
            if (!$b || !isset($b['id']) || !preg_match('/^[a-z0-9-]{1,60}$/', (string) $b['id'])) $err = 'bien.id invalide (minuscules, chiffres, tirets)';
            elseif (p_indice_bien($d, $b['id']) >= 0) $err = 'id déjà utilisé : ' . $b['id'] . ' (bien déjà suivi : utilisez maj ou lien)';
            elseif (!isset($b['titre']) || trim((string) $b['titre']) === '') $err = 'bien.titre manquant';
            elseif (!isset($b['verdict']) || !in_array($b['verdict'], $VERDICTS, true)) $err = 'bien.verdict : top|presque|hors';
            elseif (($x = p_liens_ok(isset($b['liens']) ? $b['liens'] : null)) !== true) $err = $x;
            elseif (($x = p_crit_ok(isset($b['crit']) ? $b['crit'] : null, $CRIT_ETATS)) !== true) $err = $x;
            elseif (isset($b['adresse']) && ($x = p_adresse_ok($b['adresse'])) !== true) $err = $x;
            elseif (($x = donnees_regles_bien(array('prix' => isset($b['prix']) ? donnees_entier($b['prix']) : null, 'crit' => $b['crit'], 'verdict' => $b['verdict']), $plafond)) !== true) $err = $x;
            elseif (isset($b['indices']) && ($x = p_indices_ok($b['indices'])) !== true) $err = $x;
            else {
                foreach ($b['liens'] as $l) { $k = donnees_cle_url($l['url']); if (isset($ix[$k]) && $ix[$k]['type'] === 'bien') { $err = 'lien déjà suivi par ' . $d['biens'][$ix[$k]['i']]['id']; break; } }
            }
            if (!$err) {
                $prix = isset($b['prix']) ? donnees_entier($b['prix']) : null;
                $nb = array(
                    'id' => $b['id'], 'etat' => 'active', 'verdict' => $b['verdict'], 'titre' => (string) $b['titre'],
                    'commune' => isset($b['commune']) ? (string) $b['commune'] : '', 'lieu' => isset($b['lieu']) ? (string) $b['lieu'] : '',
                    'prix' => $prix, 'prix_hist' => $prix !== null ? array(array('d' => $auj, 'p' => $prix)) : array(),
                    'repere_le' => $auj, 'verifie_le' => $auj, 'retiree_le' => null,
                    'crit' => $b['crit'], 'verifier' => isset($b['verifier']) && is_array($b['verifier']) ? array_values($b['verifier']) : array(),
                    'liens' => array_values($b['liens']), 'photo' => null,
                    'photo_url' => isset($b['photo_url']) ? (string) $b['photo_url'] : null
                );
                if (isset($b['surface']) && donnees_entier($b['surface'])) $nb['surface'] = donnees_entier($b['surface']);
                if (isset($b['adresse'])) $nb['adresse'] = $b['adresse'];
                if (isset($b['indices'])) $nb['indices'] = $b['indices'];
                if (isset($b['geo']) && is_array($b['geo'])) $nb['geo'] = $b['geo'];
                $d['biens'][] = $nb;
                $iBien = count($d['biens']) - 1;
                foreach ($nb['liens'] as $l) { $k = donnees_cle_url($l['url']); if (!isset($ix[$k]) || $ix[$k]['type'] !== 'vu') $ix[$k] = array('type' => 'bien', 'i' => $iBien); }
                p_retirer_vus($d, $ix, array_map(function ($l) { return $l['url']; }, $b['liens']));
                $nouveaux[] = $b['id'];
            }
        } elseif ($op === 'maj') {
            $id = isset($o['id']) ? (string) $o['id'] : '';
            $i = p_indice_bien($d, $id);
            $ch = isset($o['champs']) && is_array($o['champs']) ? $o['champs'] : array();
            if ($i < 0) $err = 'bien inconnu : ' . $id;
            else {
                foreach ($ch as $k => $v) {
                    if (!in_array($k, $CHAMPS, true)) { $err = 'champ non modifiable : ' . $k; break; }
                    if ($k === 'verdict' && !in_array($v, $VERDICTS, true)) { $err = 'verdict : top|presque|hors'; break; }
                    if ($k === 'etat' && !in_array($v, $ETATS, true)) { $err = 'etat : active|retiree|ecartee'; break; }
                    if ($k === 'liens' && ($x = p_liens_ok($v)) !== true) { $err = $x; break; }
                    if ($k === 'crit' && ($x = p_crit_ok($v, $CRIT_ETATS)) !== true) { $err = $x; break; }
                    if ($k === 'adresse' && ($x = p_adresse_ok($v)) !== true) { $err = $x; break; }
                    if ($k === 'indices' && ($x = p_indices_ok($v)) !== true) { $err = $x; break; }
                    if (($k === 'retiree_le' || $k === 'verifie_le') && $v !== null && !p_date($v)) { $err = $k . ' : AAAA-MM-JJ'; break; }
                }
            }
            if (!$err) {
                $b = $d['biens'][$i];
                foreach ($ch as $k => $v) {
                    if ($k === 'prix') {
                        $p = donnees_entier($v);
                        if ($p !== null && (!isset($b['prix']) || (int) $b['prix'] !== $p)) {
                            if (!isset($b['prix_hist']) || !is_array($b['prix_hist'])) $b['prix_hist'] = array();
                            $b['prix_hist'][] = array('d' => $auj, 'p' => $p);
                        }
                        $b['prix'] = $p;
                    } elseif ($k === 'surface') { $b['surface'] = donnees_entier($v); }
                    elseif ($k === 'adresse' && isset($b['adresse']['texte']) && (!isset($v['texte']) || $v['texte'] === '') && $v['confiance'] !== 'non_trouvee') { /* garde l'adresse connue */ }
                    else $b[$k] = $v;
                }
                if (isset($ch['etat']) && $ch['etat'] === 'retiree' && empty($b['retiree_le'])) $b['retiree_le'] = $auj;
                if (isset($ch['etat']) && $ch['etat'] === 'active') $b['retiree_le'] = null;
                if (!empty($o['verifie'])) $b['verifie_le'] = $auj;
                $touche = array_intersect(array_keys($ch), array('verdict', 'crit', 'prix', 'etat'));
                if ($touche && isset($b['etat']) && $b['etat'] === 'active' && ($x = donnees_regles_bien($b, $plafond)) !== true) $err = $x;
                else $d['biens'][$i] = $b;
            }
        } elseif ($op === 'lien') {
            $id = isset($o['id']) ? (string) $o['id'] : '';
            $i = p_indice_bien($d, $id);
            $url = isset($o['url']) ? (string) $o['url'] : '';
            if ($i < 0) $err = 'bien inconnu : ' . $id;
            elseif (!preg_match('#^https?://[^\s"<>]+$#', $url)) $err = 'url invalide';
            else {
                $k = donnees_cle_url($url); $fait = false;
                $liens = isset($d['biens'][$i]['liens']) && is_array($d['biens'][$i]['liens']) ? $d['biens'][$i]['liens'] : array();
                foreach ($liens as $j => $l) if (isset($l['url']) && donnees_cle_url($l['url']) === $k) {
                    if (isset($o['ok'])) $liens[$j]['ok'] = (bool) $o['ok'];
                    if (isset($o['site'])) $liens[$j]['site'] = (string) $o['site'];
                    $fait = true;
                }
                if (!$fait) $liens[] = array('site' => isset($o['site']) ? (string) $o['site'] : '', 'url' => $url, 'ok' => isset($o['ok']) ? (bool) $o['ok'] : true);
                $d['biens'][$i]['liens'] = $liens;
                p_retirer_vus($d, $ix, array($url));
                $ix[$k] = array('type' => 'bien', 'i' => $i);
            }
        } elseif ($op === 'vu') {
            $url = isset($o['url']) ? (string) $o['url'] : '';
            if (!preg_match('#^https?://[^\s"<>]+$#', $url)) $err = 'url invalide';
            elseif (!isset($o['motif']) || trim((string) $o['motif']) === '') $err = 'motif manquant';
            else {
                $k = donnees_cle_url($url);
                if (isset($ix[$k]) && $ix[$k]['type'] === 'bien') $err = 'annonce suivie par ' . $d['biens'][$ix[$k]['i']]['id'] . ' : pas dans vus';
                else {
                    $e = array('url' => $url, 'motif' => (string) $o['motif'], 'prix' => isset($o['prix']) ? donnees_entier($o['prix']) : null, 'le' => $auj);
                    if (isset($ix[$k])) $d['vus'][$ix[$k]['i']] = $e; else { $d['vus'][] = $e; $ix[$k] = array('type' => 'vu', 'i' => count($d['vus']) - 1); }
                }
            }
        } elseif ($op === 'supprimer') {
            // Réservé aux corrections demandées par Damien (bien ajouté par erreur) : jamais pendant une veille.
            // Le bien disparaît de la liste ; ses annonces passent dans les écartées avec le motif, pour ne pas revenir.
            $id = isset($o['id']) ? (string) $o['id'] : '';
            $i = p_indice_bien($d, $id);
            $motif = isset($o['motif']) ? trim((string) $o['motif']) : '';
            if ($i < 0) $err = 'bien inconnu : ' . $id;
            elseif ($motif === '') $err = 'motif manquant';
            else {
                $b = $d['biens'][$i];
                array_splice($d['biens'], $i, 1);
                if (isset($b['liens']) && is_array($b['liens'])) foreach ($b['liens'] as $l) if (isset($l['url'])) $d['vus'][] = array('url' => $l['url'], 'motif' => $motif, 'prix' => isset($b['prix']) ? $b['prix'] : null, 'le' => $auj);
                $ix = donnees_index($d);
            }
        } elseif ($op === 'fusionner') {
            $id = isset($o['id']) ? (string) $o['id'] : '';
            $avec = isset($o['avec']) ? (string) $o['avec'] : '';
            $i = p_indice_bien($d, $id); $j = p_indice_bien($d, $avec);
            if ($i < 0) $err = 'bien inconnu : ' . $id;
            elseif ($j < 0) $err = 'bien inconnu : ' . $avec;
            elseif ($i === $j) $err = 'un bien ne se fusionne pas avec lui-même';
            else {
                $a = $d['biens'][$j];
                $d['biens'][$i] = donnees_fusionner_biens($d['biens'][$i], $a);
                array_splice($d['biens'], $j, 1);
                $ix = donnees_index($d);
                $fusions[] = array('de' => $avec, 'vers' => $id, 'num_de' => isset($a['num']) ? $a['num'] : null, 'num_vers' => isset($d['biens'][p_indice_bien($d, $id)]['num']) ? $d['biens'][p_indice_bien($d, $id)]['num'] : null);
            }
        } elseif ($op === 'oublier_vu') {
            p_retirer_vus($d, $ix, array(isset($o['url']) ? (string) $o['url'] : ''));
        } elseif ($op === 'source') {
            $nom = isset($o['nom']) ? trim((string) $o['nom']) : '';
            if ($nom === '') $err = 'nom manquant';
            elseif (!isset($o['statut']) || !in_array($o['statut'], $STATUTS, true)) $err = 'statut : ok|partiel|echec|nt';
            elseif (isset($o['dernier_ok']) && $o['dernier_ok'] !== null && !p_date($o['dernier_ok'])) $err = 'dernier_ok : AAAA-MM-JJ ou null';
            elseif ($o['statut'] !== 'ok' && ($x = p_note_source(isset($o['note']) ? (string) $o['note'] : '')) !== true) $err = $x;
            else {
                $j = -1; foreach ($d['sources'] as $x => $s) if (isset($s['nom']) && strtolower($s['nom']) === strtolower($nom)) $j = $x;
                $s = $j >= 0 ? $d['sources'][$j] : array('nom' => $nom, 'dernier_ok' => null);
                $s['statut'] = $o['statut'];
                $s['passage_le'] = $auj;
                if (isset($o['note'])) $s['note'] = (string) $o['note'];
                if (array_key_exists('dernier_ok', $o)) $s['dernier_ok'] = $o['dernier_ok'];
                elseif ($o['statut'] === 'ok') $s['dernier_ok'] = $auj;
                if ($j >= 0) $d['sources'][$j] = $s; else $d['sources'][] = $s;
            }
        } elseif ($op === 'fin' && ($manque = p_sources_manquantes($r, $d, $auj))) {
            $err = 'fin refusée : aucune opération source ce passage pour ' . implode(', ', $manque) . '. Lis ces sources (aucune limite de temps), ou envoie pour chacune une opération source avec la vraie cause, puis renvoie fin.';
        } elseif ($op === 'fin') {
            $d['maj'] = $auj;
            $d['note_passage'] = isset($o['note_passage']) ? (string) $o['note_passage'] : '';
            $limite = date('Y-m-d', strtotime('-90 days'));
            $garde = array(); foreach ($d['vus'] as $v) if (!isset($v['le']) || $v['le'] >= $limite) $garde[] = $v;
            $d['vus'] = $garde;
            $ix = donnees_index($d);
        } else {
            $err = 'op inconnue : ' . $op;
        }
        $resultats[] = $err ? array('n' => $n, 'op' => $op, 'ok' => false, 'erreur' => $err) : array('n' => $n, 'op' => $op, 'ok' => true);
    }
    if ($nouveaux) $d['biens'] = numeroter($r, $d['biens']);
    return true;
});
if (is_array($res) && isset($res['erreur'])) { http_response_code(500); echo json_encode(array('erreur' => $res['erreur'], 'enregistre' => false, 'conseil' => 'Rien n\'a été enregistré. Ne renvoie pas en boucle : signale « envoi au site échoué » avec ce message dans le message de fin.'), JSON_UNESCAPED_UNICODE); exit; }
// Après l'écriture de la liste : numéro et choix de la famille du doublon passent au bien gardé.
foreach ($fusions as $f) {
    if ($f['num_de'] !== null && $f['num_vers'] !== null) numeros_alias($f['num_de'], $f['num_vers']);
    donnees_transferer_choix($r, $f['de'], $f['vers']);
}
$d = donnees_lire($r);
$nums = array();
foreach ($d['biens'] as $b) if (in_array($b['id'], $nouveaux, true)) $nums[$b['id']] = isset($b['num']) ? $b['num'] : null;
$refus = 0; foreach ($resultats as $x) if (!$x['ok']) $refus++;
echo json_encode(array('ok' => $refus === 0, 'refus' => $refus, 'resultats' => $resultats, 'nums' => $nums, 'maj' => isset($d['maj']) ? $d['maj'] : null, 'biens' => count($d['biens']), 'vus' => count($d['vus'])), JSON_UNESCAPED_UNICODE);
