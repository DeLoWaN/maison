<?php
// État du stockage côté serveur (connexion requise). GET diag.php
//   Tailles par dossier de maison-data, fichiers temporaires restés, limites PHP, essai d'écriture.
// GET diag.php?nettoyer=1 : retire les fichiers .tmp restés et ne garde que les 15 dernières copies datées par recherche.
require_once __DIR__ . '/auth-lib.php';
auth_exiger();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$base = dirname(dirname(__DIR__)) . '/maison-data';
function diag_taille($dir, &$nb) {
    $t = 0; $nb = 0;
    if (!is_dir($dir)) return 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) if ($f->isFile()) { $t += $f->getSize(); $nb++; }
    return $t;
}
$out = array('dossiers' => array(), 'tmp' => array(), 'nettoyage' => null);
if (isset($_GET['nettoyer'])) {
    $retires = 0; $octets = 0;
    foreach (glob($base . '/*', GLOB_ONLYDIR) as $r) {
        foreach (glob($r . '/*.tmp') as $f) { $octets += filesize($f); if (@unlink($f)) $retires++; }
        $copies = glob($r . '/historique/*.json');
        if ($copies && count($copies) > 15) { sort($copies); foreach (array_slice($copies, 0, count($copies) - 15) as $c) { $octets += filesize($c); if (@unlink($c)) $retires++; } }
    }
    foreach (glob($base . '/*.tmp') as $f) { $octets += filesize($f); if (@unlink($f)) $retires++; }
    $out['nettoyage'] = array('fichiers_retires' => $retires, 'octets_liberes' => $octets);
}
$total = 0; $nbTotal = 0;
foreach (glob($base . '/*', GLOB_ONLYDIR) as $r) {
    foreach (array('', '/historique', '/photos') as $sous) {
        $nb = 0; $t = diag_taille($r . $sous, $nb);
        if ($sous === '') { $total += $t; $nbTotal += $nb; }
        $out['dossiers'][basename($r) . ($sous ? $sous : '')] = array('ko' => round($t / 1024), 'fichiers' => $nb);
    }
    foreach (glob($r . '/*.tmp') as $f) $out['tmp'][] = basename($r) . '/' . basename($f) . ' ' . round(filesize($f) / 1024) . ' ko';
}
$racine = 0; foreach (glob($base . '/*') as $f) if (is_file($f)) $racine += filesize($f);
$out['maison_data_ko'] = round(($total + $racine) / 1024);
$nb = 0; $out['site_deploye_ko'] = round(diag_taille(dirname(__DIR__), $nb) / 1024);
$out['espace_libre_disque_mo'] = @disk_free_space($base) !== false ? round(disk_free_space($base) / 1048576) : null;
$essai = $base . '/essai-ecriture.tmp';
$bloc = str_repeat('x', 262144);
$ecrit = @file_put_contents($essai, $bloc);
$out['essai_ecriture_256ko'] = $ecrit === 262144 ? 'ok' : ('échec (' . var_export($ecrit, true) . ')');
@unlink($essai);
$out['php'] = array('version' => PHP_VERSION, 'memory_limit' => ini_get('memory_limit'), 'max_execution_time' => ini_get('max_execution_time'), 'post_max_size' => ini_get('post_max_size'));
$e = error_get_last(); if ($e) $out['derniere_erreur_php'] = $e['message'];
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
