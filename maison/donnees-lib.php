<?php
// Outils communs pour lire et modifier la liste d'une recherche sans la renvoyer en entier.
// Compatible PHP 5.4 (pas de ??, pas de fonctions fléchées).
date_default_timezone_set('Europe/Paris');

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

