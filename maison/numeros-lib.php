<?php
// Numéros de maison partagés par toutes les recherches : <home>/maison-data/numeros.json
// {"suivant": 52, "carte": {"maison/thue-185": 7, ...}, "alias": {"62": 10}}. Compatible PHP 5.4.
// alias : numéro d'une maison suivie deux fois avant la fusion des recherches => numéro gardé.

function numeros_fichier() { return dirname(dirname(__DIR__)) . '/maison-data/numeros.json'; }

function numeros_lire() {
    $j = json_decode((string) @file_get_contents(numeros_fichier()), true);
    if (!is_array($j) || !isset($j['carte']) || !is_array($j['carte'])) $j = array('suivant' => 1, 'carte' => array());
    return $j;
}

// Ajoute le champ num à chaque bien ; donne un nouveau numéro aux biens inconnus,
// dans l'ordre où ils ont été repérés (puis verdict, puis prix).
function numeroter($r, $biens) {
    $f = numeros_fichier();
    if (!is_dir(dirname($f))) @mkdir(dirname($f), 0750, true);
    $fp = fopen($f, 'c+');
    if ($fp) flock($fp, LOCK_EX);
    $j = json_decode($fp ? stream_get_contents($fp) : '', true);
    if (!is_array($j) || !isset($j['carte']) || !is_array($j['carte'])) $j = array('suivant' => 1, 'carte' => array());
    $nouveaux = array();
    foreach ($biens as $i => $b) {
        if (!isset($j['carte'][$r . '/' . $b['id']])) $nouveaux[] = $i;
    }
    $rang = array('top' => 0, 'presque' => 1, 'hors' => 2);
    usort($nouveaux, function ($a, $c) use ($biens, $rang) {
        $ba = $biens[$a]; $bc = $biens[$c];
        $x = strcmp(isset($ba['repere_le']) ? (string) $ba['repere_le'] : '', isset($bc['repere_le']) ? (string) $bc['repere_le'] : '');
        if ($x) return $x;
        $va = isset($ba['verdict'], $rang[$ba['verdict']]) ? $rang[$ba['verdict']] : 3;
        $vc = isset($bc['verdict'], $rang[$bc['verdict']]) ? $rang[$bc['verdict']] : 3;
        if ($va !== $vc) return $va - $vc;
        $pa = isset($ba['prix']) ? (int) $ba['prix'] : 0; $pc = isset($bc['prix']) ? (int) $bc['prix'] : 0;
        if ($pa !== $pc) return $pa - $pc;
        return $a - $c;
    });
    foreach ($nouveaux as $i) { $j['carte'][$r . '/' . $biens[$i]['id']] = (int) $j['suivant']; $j['suivant'] = (int) $j['suivant'] + 1; }
    foreach ($biens as $i => $b) { $biens[$i]['num'] = $j['carte'][$r . '/' . $b['id']]; }
    if ($fp) {
        if ($nouveaux) { ftruncate($fp, 0); rewind($fp); fwrite($fp, json_encode($j)); fflush($fp); }
        flock($fp, LOCK_UN); fclose($fp);
    }
    return $biens;
}
