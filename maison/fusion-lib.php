<?php
// Fusion des recherches « longere » et « plainpied » dans la recherche unique « maison ».
// Fonctions pures : elles reçoivent les listes et renvoient la liste fusionnée, sans lire ni écrire de fichier.
// Compatible PHP 5.4.
require_once __DIR__ . '/donnees-lib.php';

// Règle du 22/10/2026 : au moins une vraie chambre au RDC. Ces biens de « longere » n'en ont aucune
// (seulement une pièce transformable) : ils passent en etat « ecartee ». Numéros relevés à la main sur les textes du critère rdc.
function fusion_sans_chambre_rdc() {
    return array(3, 6, 7, 12, 13, 17, 19, 23, 25, 27, 31, 34, 35, 37, 40, 45, 47, 49, 50, 87, 103, 105, 106, 110, 111, 113, 133, 134);
}
// Biens de « longere » dont la répartition des chambres n'est pas connue : critère rdc « unk », à revérifier.
function fusion_rdc_inconnu() {
    return array(9, 10, 15, 24, 32, 36, 39, 42, 104, 109);
}
// Biens gardés visibles à la demande de la famille, même sans chambre au RDC.
function fusion_gardes_visibles() {
    return array(23);
}

// Union-find minimal sur des indices.
function fusion_racine(&$parent, $i) {
    while ($parent[$i] !== $i) { $parent[$i] = $parent[$parent[$i]]; $i = $parent[$i]; }
    return $i;
}

// Critère rdc d'un bien de « plainpied » dans la nouvelle grille : deux chambres = ok, une chambre = warn.
function fusion_rdc_plainpied($c) {
    if (!is_array($c) || count($c) < 2) return array('unk', 'Répartition des chambres à confirmer');
    if ($c[0] === 'ok') return $c;
    return array('warn', $c[1]);
}
// Critère rdc d'un bien de « longere » qui a une chambre au RDC : ok seulement si le texte en annonce deux ou plus.
function fusion_rdc_longere($c) {
    if (!is_array($c) || count($c) < 2) return array('unk', 'Répartition des chambres à confirmer');
    if ($c[0] !== 'ok') return $c;
    $deux = preg_match('/(\b[2-9]\b|deux|trois|quatre|cinq)\s+chambres?[^.;]{0,30}(rdc|rez|en bas)|plain-?pied/iu', (string) $c[1]);
    return $deux ? $c : array('warn', $c[1]);
}

function fusion_rang_adresse($a) {
    if (!is_array($a) || !isset($a['confiance'])) return 0;
    $r = array('non_trouvee' => 1, 'probable' => 2, 'forte' => 3);
    return isset($r[$a['confiance']]) ? $r[$a['confiance']] : 0;
}

/**
 * Fusionne les deux recherches.
 * $sources : array('longere' => data, 'plainpied' => data) ; chaque data a biens, vus, sources.
 * $statuts, $avis : array(recherche => array(id => entrée)) tels que dans statuts.json et avis.json.
 * Renvoie array(
 *   'data'    => liste « maison » (biens, vus, sources),
 *   'statuts' => array(id => entrée), 'avis' => array(id => entrée),
 *   'carte'   => array('maison/<id>' => num), 'alias' => array(ancien num => num gardé),
 *   'photos'  => array(array('r' => ancienne recherche, 'de' => ancien id, 'vers' => nouvel id)),
 *   'rapport' => résumé lisible des décisions)
 * Lève une InvalidArgumentException si un bien n'a pas de numéro.
 */
function fusion_calculer($sources, $statuts, $avis) {
    $tous = array();
    foreach (array('longere', 'plainpied') as $r) {
        $biens = isset($sources[$r]['biens']) && is_array($sources[$r]['biens']) ? $sources[$r]['biens'] : array();
        foreach ($biens as $b) {
            if (!isset($b['num'])) throw new InvalidArgumentException('bien sans numéro : ' . $r . '/' . (isset($b['id']) ? $b['id'] : '?'));
            $tous[] = array('r' => $r, 'b' => $b);
        }
    }
    // Regroupement : deux biens qui partagent une adresse d'annonce sont la même maison.
    $parent = array_keys($tous);
    $parUrl = array();
    foreach ($tous as $i => $x) {
        $liens = isset($x['b']['liens']) && is_array($x['b']['liens']) ? $x['b']['liens'] : array();
        foreach ($liens as $l) {
            if (!isset($l['url'])) continue;
            $k = donnees_cle_url($l['url']);
            if (isset($parUrl[$k])) { $a = fusion_racine($parent, $i); $c = fusion_racine($parent, $parUrl[$k]); if ($a !== $c) $parent[$a] = $c; }
            else $parUrl[$k] = $i;
        }
    }
    $groupes = array();
    foreach ($tous as $i => $x) $groupes[fusion_racine($parent, $i)][] = $i;

    $sansRdc = array_flip(fusion_sans_chambre_rdc());
    $rdcInconnu = array_flip(fusion_rdc_inconnu());
    $gardes = array_flip(fusion_gardes_visibles());
    $out = array('biens' => array(), 'statuts' => array(), 'avis' => array(), 'carte' => array(), 'alias' => array(), 'photos' => array());
    $rapport = array('groupes_fusionnes' => array(), 'ecartes' => array(), 'rdc_inconnu' => array(), 'gardes_visibles' => array(), 'ids_renommes' => array());
    $idsPris = array();

    // Ordre stable : par numéro du bien gardé.
    $ordre = array();
    foreach ($groupes as $g) {
        $nums = array(); foreach ($g as $i) $nums[] = (int) $tous[$i]['b']['num'];
        $ordre[] = array('g' => $g, 'min' => min($nums));
    }
    usort($ordre, function ($a, $c) { return $a['min'] - $c['min']; });

    foreach ($ordre as $o) {
        $g = $o['g'];
        // Bien gardé : celui qui a un statut ou un avis de la famille, sinon le plus petit numéro.
        usort($g, function ($a, $c) use ($tous, $statuts, $avis) {
            $fa = isset($statuts[$tous[$a]['r']][$tous[$a]['b']['id']]) || isset($avis[$tous[$a]['r']][$tous[$a]['b']['id']]);
            $fc = isset($statuts[$tous[$c]['r']][$tous[$c]['b']['id']]) || isset($avis[$tous[$c]['r']][$tous[$c]['b']['id']]);
            if ($fa !== $fc) return $fa ? -1 : 1;
            return (int) $tous[$a]['b']['num'] - (int) $tous[$c]['b']['num'];
        });
        $p = $tous[$g[0]];
        $b = $p['b'];
        $num = (int) $b['num'];
        $id = $b['id'];
        if (isset($idsPris[$id])) {
            $nouvel = $id . '-n' . $num;
            $rapport['ids_renommes'][] = $p['r'] . '/' . $id . ' → ' . $nouvel . ' (n° ' . $num . ')';
            $id = $nouvel;
        }
        $idsPris[$id] = 1;
        $b['id'] = $id;

        $membres = array();
        $avecPlainpied = null; $avecLongere = null;
        foreach ($g as $i) {
            $x = $tous[$i];
            $membres[] = $x;
            if ($x['r'] === 'plainpied' && $avecPlainpied === null) $avecPlainpied = $x['b'];
            if ($x['r'] === 'longere' && $avecLongere === null) $avecLongere = $x['b'];
        }
        if (count($membres) > 1) {
            $txt = array(); foreach ($membres as $x) $txt[] = $x['r'] . ' n° ' . $x['b']['num'];
            $rapport['groupes_fusionnes'][] = 'n° ' . $num . ' ← ' . implode(' + ', $txt);
        }

        // Liens : union, sans doublon d'adresse.
        $liens = array(); $vusCles = array();
        foreach ($membres as $x) if (isset($x['b']['liens']) && is_array($x['b']['liens'])) foreach ($x['b']['liens'] as $l) {
            if (!isset($l['url'])) continue;
            $k = donnees_cle_url($l['url']);
            if (isset($vusCles[$k])) continue;
            $vusCles[$k] = 1; $liens[] = $l;
        }
        $b['liens'] = $liens;
        // Dates : repéré au plus tôt, vérifié et vu en liste au plus tard. Historique des prix : union triée.
        $hist = array(); $cleHist = array();
        foreach ($membres as $x) {
            $m = $x['b'];
            if (isset($m['repere_le']) && (!isset($b['repere_le']) || $m['repere_le'] < $b['repere_le'])) $b['repere_le'] = $m['repere_le'];
            foreach (array('verifie_le', 'vu_en_liste_le') as $champ) if (isset($m[$champ]) && (!isset($b[$champ]) || $m[$champ] > $b[$champ])) $b[$champ] = $m[$champ];
            if (isset($m['prix_hist']) && is_array($m['prix_hist'])) foreach ($m['prix_hist'] as $h) {
                $k = (isset($h['d']) ? $h['d'] : '') . '/' . (isset($h['p']) ? $h['p'] : '');
                if (!isset($cleHist[$k])) { $cleHist[$k] = 1; $hist[] = $h; }
            }
            if (isset($m['photos']) && is_array($m['photos']) && count($m['photos']) > (isset($b['photos']) && is_array($b['photos']) ? count($b['photos']) : 0)) $b['photos'] = $m['photos'];
            if (fusion_rang_adresse(isset($m['adresse']) ? $m['adresse'] : null) > fusion_rang_adresse(isset($b['adresse']) ? $b['adresse'] : null)) $b['adresse'] = $m['adresse'];
            if (empty($b['photo_url']) && !empty($m['photo_url'])) $b['photo_url'] = $m['photo_url'];
            if (empty($b['surface']) && !empty($m['surface'])) $b['surface'] = $m['surface'];
            // Un bien encore en vente sur une recherche reste actif.
            if (isset($m['etat']) && $m['etat'] === 'active') { $b['etat'] = 'active'; $b['retiree_le'] = null; }
        }
        usort($hist, function ($a, $c) { return strcmp(isset($a['d']) ? $a['d'] : '', isset($c['d']) ? $c['d'] : ''); });
        $b['prix_hist'] = $hist;

        // Critères : type de maison jugé par « longere », chambres en bas jugées par « plainpied ».
        $crit = isset($b['crit']) && is_array($b['crit']) ? $b['crit'] : array();
        if ($avecLongere && isset($avecLongere['crit']['pierre'])) $crit['pierre'] = $avecLongere['crit']['pierre'];
        if ($avecPlainpied) {
            $crit['rdc'] = fusion_rdc_plainpied(isset($avecPlainpied['crit']['rdc']) ? $avecPlainpied['crit']['rdc'] : null);
        } else {
            $nl = (int) $avecLongere['num'];
            if (isset($sansRdc[$nl])) {
                $crit['rdc'] = array('ko', 'Pas de chambre au RDC' . (isset($crit['rdc'][1]) ? ' (' . $crit['rdc'][1] . ')' : ''));
                if (isset($gardes[$nl])) {
                    $rapport['gardes_visibles'][] = 'n° ' . $num . ' ' . $id . ' : reste visible à la demande de la famille';
                } elseif ($b['etat'] === 'active') {
                    $b['etat'] = 'ecartee';
                    $b['ecartee_motif'] = 'pas de chambre au rez-de-chaussée';
                    $rapport['ecartes'][] = 'n° ' . $num . ' ' . $id;
                }
            } elseif (isset($rdcInconnu[$nl])) {
                $crit['rdc'] = array('unk', 'Répartition des chambres à confirmer' . (isset($crit['rdc'][1]) ? ' (' . $crit['rdc'][1] . ')' : ''));
                $rapport['rdc_inconnu'][] = 'n° ' . $num . ' ' . $id;
            } else {
                $crit['rdc'] = fusion_rdc_longere(isset($crit['rdc']) ? $crit['rdc'] : null);
            }
        }
        $b['crit'] = $crit;
        $b['num'] = $num;
        $out['biens'][] = $b;
        $out['carte']['maison/' . $id] = $num;

        foreach ($membres as $x) {
            $ancien = (int) $x['b']['num'];
            if ($ancien !== $num) $out['alias'][(string) $ancien] = $num;
            $out['photos'][] = array('r' => $x['r'], 'de' => $x['b']['id'], 'vers' => $id);
            // Statut et avis : le plus récent des biens du groupe.
            foreach (array('statuts', 'avis') as $quoi) {
                $src = $quoi === 'statuts' ? $statuts : $avis;
                if (!isset($src[$x['r']][$x['b']['id']])) continue;
                $e = $src[$x['r']][$x['b']['id']];
                $le = isset($e['le']) ? $e['le'] : '';
                if (!isset($out[$quoi][$id]) || $le > (isset($out[$quoi][$id]['le']) ? $out[$quoi][$id]['le'] : '')) $out[$quoi][$id] = $e;
            }
        }
    }

    // Annonces écartées : union, sans celles qui sont devenues des liens d'un bien.
    $cles = array();
    foreach ($out['biens'] as $b) foreach ($b['liens'] as $l) $cles[donnees_cle_url($l['url'])] = 1;
    $vus = array();
    foreach (array('longere', 'plainpied') as $r) if (isset($sources[$r]['vus']) && is_array($sources[$r]['vus'])) foreach ($sources[$r]['vus'] as $v) {
        if (!isset($v['url'])) continue;
        $k = donnees_cle_url($v['url']);
        if (isset($cles[$k])) continue;
        $cles[$k] = 1; $vus[] = $v;
    }
    // Sources : la lecture complète la plus ancienne des deux, pour que le premier passage relise tout ce qui manque.
    $src = array();
    foreach (array('longere', 'plainpied') as $r) if (isset($sources[$r]['sources']) && is_array($sources[$r]['sources'])) foreach ($sources[$r]['sources'] as $s) {
        if (!isset($s['nom'])) continue;
        $k = strtolower($s['nom']);
        if (!isset($src[$k])) { $src[$k] = $s; continue; }
        $a = isset($src[$k]['dernier_ok']) ? $src[$k]['dernier_ok'] : null;
        $c = isset($s['dernier_ok']) ? $s['dernier_ok'] : null;
        if ($a === null || $c === null) $src[$k]['dernier_ok'] = null; elseif ($c < $a) $src[$k]['dernier_ok'] = $c;
    }

    $rapport['total'] = array(
        'biens_avant' => count($tous), 'biens_apres' => count($out['biens']),
        'actifs' => count(array_filter($out['biens'], function ($b) { return isset($b['etat']) && $b['etat'] === 'active'; })),
        'ecartes' => count($rapport['ecartes']), 'vus' => count($vus)
    );
    return array(
        'data' => array('biens' => $out['biens'], 'vus' => $vus, 'sources' => array_values($src)),
        'statuts' => $out['statuts'], 'avis' => $out['avis'], 'carte' => $out['carte'], 'alias' => $out['alias'],
        'photos' => $out['photos'], 'rapport' => $rapport
    );
}
