<?php
// Outils communs pour lire et modifier la liste d'une recherche sans la renvoyer en entier.
// Compatible PHP 5.4 (pas de ??, pas de fonctions fléchées).
date_default_timezone_set('Europe/Paris');

// Recherche visée par une requête : la valeur de r, ou la première de recherches.json quand r est absent.
// Le site n'a qu'une recherche active : la veille n'a pas à donner r.
function donnees_recherche_demandee($valeur) {
    $r = is_string($valeur) ? $valeur : '';
    if ($r !== '') return $r;
    $l = json_decode((string) @file_get_contents(__DIR__ . '/recherches.json'), true);
    return is_array($l) && isset($l[0]['id']) ? (string) $l[0]['id'] : '';
}
function donnees_recherche_valide($r) {
    return is_string($r) && preg_match('/^[a-z0-9-]{1,40}$/', $r) && is_dir(__DIR__ . '/recherches/' . $r);
}
// Recherche fusionnée dans une autre : lisible, mais plus aucune écriture (recherches/<r>/archivee.txt donne la raison).
function donnees_archivee($r) {
    $f = __DIR__ . '/recherches/' . $r . '/archivee.txt';
    return is_file($f) ? trim((string) @file_get_contents($f)) : false;
}
// Arrête la requête avec 409 si la recherche est archivée.
function donnees_refuser_si_archivee($r) {
    $m = donnees_archivee($r);
    if ($m === false) return;
    http_response_code(409);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('erreur' => 'recherche archivée : ' . $m), JSON_UNESCAPED_UNICODE);
    exit;
}
function donnees_fichier($r) { return dirname(dirname(__DIR__)) . '/maison-data/' . $r . '/data.json'; }
function donnees_aujourdhui() { return date('Y-m-d'); }

// Lecture seule.
function donnees_lire($r) {
    $f = donnees_fichier($r);
    $src = is_file($f) ? $f : __DIR__ . '/recherches/' . $r . '/data.json';
    $d = json_decode((string) @file_get_contents($src), true);
    if (!is_array($d)) $d = array();
    if (!isset($d['biens']) || !is_array($d['biens'])) $d['biens'] = array();
    if (!isset($d['vus']) || !is_array($d['vus'])) $d['vus'] = array();
    if (!isset($d['sources']) || !is_array($d['sources'])) $d['sources'] = array();
    return $d;
}

// Modification sous verrou (même verrou que photos.php) : $fn reçoit la liste et la renvoie modifiée.
// Copie datée de la version précédente (compressée, 30 dernières), au plus une toutes les 10 minutes :
// le 20 octobre, une copie complète à chaque envoi (près de 200 copies de 700 ko) a rempli l'hébergement.
// $copie = false : pas de copie (simple marquage, comme vu_en_liste_le dans nouveautes.php).
function donnees_modifier($r, $fn, $copie = true) {
    $f = donnees_fichier($r);
    $dir = dirname($f);
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    $verrou = fopen($f . '.lock', 'c');
    flock($verrou, LOCK_EX);
    $d = donnees_lire($r);
    $res = call_user_func_array($fn, array(&$d));
    if ($res !== false) {
        if ($copie && is_file($f)) donnees_copie_datee($f);
        $ok = donnees_ecrire_json($f, $d);
        if ($ok !== true) $res = array('erreur' => $ok);
    }
    flock($verrou, LOCK_UN);
    fclose($verrou);
    return $res;
}

function donnees_copie_datee($f) {
    $hist = dirname($f) . '/historique';
    if (!is_dir($hist)) @mkdir($hist, 0750, true);
    $copies = glob($hist . '/*.json*');
    if (!$copies) $copies = array();
    sort($copies);
    $derniere = $copies ? end($copies) : null;
    if ($derniere && time() - filemtime($derniere) < 600) return;
    $gz = function_exists('gzencode');
    $cible = $hist . '/' . date('Ymd-His') . ($gz ? '.json.gz' : '.json');
    if ($gz) @file_put_contents($cible, gzencode((string) @file_get_contents($f), 6)); else @copy($f, $cible);
    $copies[] = $cible;
    if (count($copies) > 30) foreach (array_slice($copies, 0, count($copies) - 30) as $c) @unlink($c);
}

// Écriture atomique (fichier .tmp puis renommage). Renvoie true ou un message d'erreur en clair.
function donnees_ecrire_json($f, $d) {
    $json = json_encode($d, JSON_UNESCAPED_UNICODE);
    if ($json === false || $json === '') return 'écriture impossible : données non encodables';
    $tmp = $f . '.tmp';
    $n = @file_put_contents($tmp, $json);
    if ($n !== strlen($json) || !@rename($tmp, $f)) {
        @unlink($tmp);
        $libre = @disk_free_space(dirname($f));
        return 'écriture impossible : espace de l\'hébergement plein probablement (voir diag.php)' . ($libre !== false ? ' ; libre ' . round($libre / 1048576) . ' Mo' : '');
    }
    return true;
}

// Adresse d'annonce comparable d'un passage à l'autre : sans « www. », sans ce qui suit ? ou #,
// sans / final. Leboncoin : seul le numéro d'annonce compte (/vi/123.htm et /ad/…/123 sont la même annonce).
function donnees_cle_url($u) {
    $u = trim((string) $u);
    if (preg_match('#leboncoin\.fr/.*?(\d{8,})#', $u, $m)) return 'leboncoin:' . $m[1];
    $u = preg_replace('/[?#].*$/', '', $u);
    $u = preg_replace('#^https?://(www\.)?#i', '', $u);
    $u = rtrim($u, '/');
    $p = strpos($u, '/');
    return $p === false ? strtolower($u) : strtolower(substr($u, 0, $p)) . substr($u, $p);
}

// Index des adresses connues : clé => array('type' => 'bien'|'vu', 'i' => indice).
function donnees_index($d) {
    $ix = array();
    foreach ($d['biens'] as $i => $b) {
        if (!isset($b['liens']) || !is_array($b['liens'])) continue;
        foreach ($b['liens'] as $l) if (isset($l['url'])) $ix[donnees_cle_url($l['url'])] = array('type' => 'bien', 'i' => $i);
    }
    foreach ($d['vus'] as $i => $v) {
        if (isset($v['url'])) { $k = donnees_cle_url($v['url']); if (!isset($ix[$k])) $ix[$k] = array('type' => 'vu', 'i' => $i); }
    }
    return $ix;
}

function donnees_entier($x) {
    if (is_int($x)) return $x;
    if (is_float($x)) return (int) round($x);
    if (is_string($x) && preg_match('/^\s*\d[\d\s.\x{202f}\x{a0}]*$/u', $x)) return (int) preg_replace('/\D/', '', $x);
    return null;
}

// Sources que la tâche doit lire à chaque passage (recherches/<r>/sources.json).
function donnees_sources_attendues($r) {
    $j = json_decode((string) @file_get_contents(__DIR__ . '/recherches/' . $r . '/sources.json'), true);
    return is_array($j) && isset($j['attendues']) && is_array($j['attendues']) ? $j['attendues'] : array();
}
// Ordre de lecture : les sources les moins récemment lues en entier d'abord (jamais lues en tête).
function donnees_ordre_sources($r, $d) {
    $dates = array();
    foreach ($d['sources'] as $s) if (isset($s['nom'])) $dates[strtolower($s['nom'])] = isset($s['dernier_ok']) && $s['dernier_ok'] ? $s['dernier_ok'] : '';
    $att = donnees_sources_attendues($r); $rang = array();
    foreach ($att as $i => $n) $rang[] = array('nom' => $n, 'd' => isset($dates[strtolower($n)]) ? $dates[strtolower($n)] : '', 'i' => $i);
    usort($rang, function ($a, $b) { return $a['d'] === $b['d'] ? $a['i'] - $b['i'] : strcmp($a['d'], $b['d']); });
    $out = array(); foreach ($rang as $x) $out[] = $x['nom'] . ' (dernière lecture complète : ' . ($x['d'] ? $x['d'] : 'jamais') . ')';
    return $out;
}


// Plafond d'ouverture des annonces (recherches/<r>/zone.json), null s'il n'est pas fixé.
function donnees_plafond($r) {
    $z = json_decode((string) @file_get_contents(__DIR__ . '/recherches/' . $r . '/zone.json'), true);
    return is_array($z) && isset($z['plafond_ouverture']) ? (int) $z['plafond_ouverture'] : null;
}

// Nom de commune écrit comme dans zone.json : minuscules, sans accents, mots reliés par des tirets.
function donnees_cle_commune($c) {
    $c = strtr((string) $c, array(
        'à' => 'a', 'â' => 'a', 'ä' => 'a', 'À' => 'a', 'Â' => 'a', 'Ä' => 'a', 'ç' => 'c', 'Ç' => 'c',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'É' => 'e', 'È' => 'e', 'Ê' => 'e', 'Ë' => 'e',
        'î' => 'i', 'ï' => 'i', 'Î' => 'i', 'Ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'Ô' => 'o', 'Ö' => 'o',
        'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'Ù' => 'u', 'Û' => 'u', 'Ü' => 'u', 'ÿ' => 'y', 'œ' => 'oe', 'Œ' => 'oe'));
    return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($c)), '-');
}

// Communes admises pour un bien actif (zone.json : communes et bordure), indexées par donnees_cle_commune ; null sans liste.
function donnees_communes_zone($r) {
    $z = json_decode((string) @file_get_contents(__DIR__ . '/recherches/' . $r . '/zone.json'), true);
    if (!is_array($z) || !isset($z['communes']) || !is_array($z['communes'])) return null;
    $communes = array();
    foreach (array_merge($z['communes'], isset($z['bordure']) && is_array($z['bordure']) ? $z['bordure'] : array()) as $c) $communes[donnees_cle_commune($c)] = 1;
    return $communes;
}

/**
 * Règles de la consigne contrôlées par le serveur sur un bien actif.
 * Renvoie true, ou le message de refus à donner à la veille.
 * - commune absente de $communes (donnees_communes_zone), accents et majuscules ignorés : refusé ; $communes null : pas de contrôle ;
 * - prix au-dessus du plafond d'ouverture : refusé ;
 * - critère rdc « ko » (aucune chambre au rez-de-chaussée) : refusé ;
 * - verdict « top » avec un critère « ko », « hors » sans critère « ko », deux critères « ko » ou plus : refusé.
 */
function donnees_regles_bien($b, $plafond, $communes = null) {
    if ($communes !== null && !isset($communes[donnees_cle_commune(isset($b['commune']) ? $b['commune'] : '')]))
        return 'commune hors liste de zone : mettre une commune de la liste ; le doute va dans lieu, crit.zone et verifier';
    if ($plafond !== null && isset($b['prix']) && $b['prix'] !== null && (int) $b['prix'] > $plafond)
        return 'prix ' . $b['prix'] . ' € au-dessus du plafond de ' . $plafond . ' € : envoie une opération vu (motif « Au-dessus du budget »)';
    $crit = isset($b['crit']) && is_array($b['crit']) ? $b['crit'] : array();
    if (isset($crit['rdc'][0]) && $crit['rdc'][0] === 'ko')
        return 'aucune chambre au rez-de-chaussée : envoie une opération vu (motif « Pas de chambre au RDC »), ou etat « ecartee » pour un bien déjà suivi';
    $ko = 0; foreach ($crit as $c) if (is_array($c) && isset($c[0]) && $c[0] === 'ko') $ko++;
    $verdict = isset($b['verdict']) ? $b['verdict'] : '';
    if ($ko >= 2) return $ko . ' critères « ko » : le bien n\'est pas gardé, envoie une opération vu';
    if ($verdict === 'top' && $ko) return 'verdict « top » avec un critère « ko » : choisis « presque » ou « hors »';
    if ($verdict === 'hors' && !$ko) return 'verdict « hors » sans critère « ko » : choisis « top » ou « presque »';
    return true;
}

// Rang de confiance d'une adresse : la plus sûre est gardée quand deux biens fusionnent.
function donnees_rang_adresse($a) {
    $r = array('non_trouvee' => 1, 'probable' => 2, 'forte' => 3);
    return is_array($a) && isset($a['confiance'], $r[$a['confiance']]) ? $r[$a['confiance']] : 0;
}

/**
 * Fusionne le bien $a (doublon) dans le bien $g (gardé) et renvoie le bien gardé complété :
 * liens et historique des prix réunis, repéré au plus tôt, vérifié et vu en liste au plus tard,
 * galerie la plus longue, adresse la plus sûre. Le reste (id, num, verdict, crit, titre) vient de $g.
 */
function donnees_fusionner_biens($g, $a) {
    $liens = array(); $cles = array();
    foreach (array($g, $a) as $b) if (isset($b['liens']) && is_array($b['liens'])) foreach ($b['liens'] as $l) {
        if (!isset($l['url'])) continue;
        $k = donnees_cle_url($l['url']);
        if (isset($cles[$k])) continue;
        $cles[$k] = 1; $liens[] = $l;
    }
    $g['liens'] = $liens;
    $hist = array(); $vus = array();
    foreach (array($g, $a) as $b) if (isset($b['prix_hist']) && is_array($b['prix_hist'])) foreach ($b['prix_hist'] as $h) {
        $k = (isset($h['d']) ? $h['d'] : '') . '/' . (isset($h['p']) ? $h['p'] : '');
        if (!isset($vus[$k])) { $vus[$k] = 1; $hist[] = $h; }
    }
    usort($hist, function ($x, $y) { return strcmp(isset($x['d']) ? $x['d'] : '', isset($y['d']) ? $y['d'] : ''); });
    $g['prix_hist'] = $hist;
    if (isset($a['repere_le']) && (!isset($g['repere_le']) || $a['repere_le'] < $g['repere_le'])) $g['repere_le'] = $a['repere_le'];
    foreach (array('verifie_le', 'vu_en_liste_le') as $c) if (isset($a[$c]) && (!isset($g[$c]) || $a[$c] > $g[$c])) $g[$c] = $a[$c];
    $ng = isset($g['photos']) && is_array($g['photos']) ? count($g['photos']) : 0;
    if (isset($a['photos']) && is_array($a['photos']) && count($a['photos']) > $ng) $g['photos'] = $a['photos'];
    if (empty($g['photo_url']) && !empty($a['photo_url'])) $g['photo_url'] = $a['photo_url'];
    if (donnees_rang_adresse(isset($a['adresse']) ? $a['adresse'] : null) > donnees_rang_adresse(isset($g['adresse']) ? $g['adresse'] : null)) $g['adresse'] = $a['adresse'];
    if (empty($g['surface']) && !empty($a['surface'])) $g['surface'] = $a['surface'];
    // Valeurs du DPE (scripts/adresse.py) : celles du bien gardé, complétées par celles du doublon.
    if (isset($a['indices']) && is_array($a['indices'])) $g['indices'] = (isset($g['indices']) && is_array($g['indices']) ? $g['indices'] : array()) + $a['indices'];
    return $g;
}

// Statut et avis de la famille : ceux du doublon passent au bien gardé quand celui-ci n'en a pas.
function donnees_transferer_choix($r, $de, $vers) {
    $dir = dirname(dirname(__DIR__)) . '/maison-data/' . $r;
    foreach (array('statuts.json', 'avis.json') as $nom) {
        $f = $dir . '/' . $nom;
        if (!is_file($f)) continue;
        $fp = fopen($f, 'c+'); if (!$fp) continue;
        flock($fp, LOCK_EX);
        $j = json_decode(stream_get_contents($fp), true);
        if (is_array($j) && isset($j[$de])) {
            if (!isset($j[$vers])) $j[$vers] = $j[$de];
            unset($j[$de]);
            ftruncate($fp, 0); rewind($fp); fwrite($fp, json_encode((object) $j, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)); fflush($fp);
        }
        flock($fp, LOCK_UN); fclose($fp);
    }
}
