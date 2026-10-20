<?php
// Outils communs pour lire et modifier la liste d'une recherche sans la renvoyer en entier.
// Compatible PHP 5.4 (pas de ??, pas de fonctions fléchées).
date_default_timezone_set('Europe/Paris');

function donnees_recherche_valide($r) {
    return is_string($r) && preg_match('/^[a-z0-9-]{1,40}$/', $r) && is_dir(__DIR__ . '/recherches/' . $r);
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
// Une copie datée de la version précédente est gardée (60 dernières), comme maj.php.
function donnees_modifier($r, $fn) {
    $f = donnees_fichier($r);
    $dir = dirname($f);
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    $verrou = fopen($f . '.lock', 'c');
    flock($verrou, LOCK_EX);
    $d = donnees_lire($r);
    $res = call_user_func_array($fn, array(&$d));
    if ($res !== false) {
        $hist = $dir . '/historique';
        if (!is_dir($hist)) @mkdir($hist, 0750, true);
        if (is_file($f)) @copy($f, $hist . '/' . date('Ymd-His') . '.json');
        $copies = glob($hist . '/*.json');
        if ($copies && count($copies) > 60) { sort($copies); foreach (array_slice($copies, 0, count($copies) - 60) as $c) @unlink($c); }
        $tmp = $f . '.tmp';
        $ok = file_put_contents($tmp, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) !== false && rename($tmp, $f);
        if (!$ok) $res = array('erreur' => 'écriture impossible');
    }
    flock($verrou, LOCK_UN);
    fclose($verrou);
    return $res;
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
