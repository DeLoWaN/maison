<?php
// Fusion unique des recherches « longere » et « plainpied » dans « maison » (voir fusion-lib.php).
// GET  migrer.php                      -> simulation : rapport des décisions, rien n'est écrit.
// POST migrer.php {"appliquer": true}  -> écrit <home>/maison-data/maison/ (data.json, statuts.json, avis.json, photos/)
//                                         et met à jour numeros.json (copie de l'ancien dans numeros.avant-fusion.json).
// Refusé si la recherche « maison » contient déjà des biens : la fusion ne se fait qu'une fois.
// Les données des anciennes recherches ne sont ni modifiées ni supprimées.
// Compatible PHP 5.4.
require_once __DIR__ . '/auth-lib.php';
auth_exiger();
require_once __DIR__ . '/fusion-lib.php';
require_once __DIR__ . '/numeros-lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$base = dirname(dirname(__DIR__)) . '/maison-data';
function m_json($f) { $j = json_decode((string) @file_get_contents($f), true); return is_array($j) ? $j : array(); }

$cible = donnees_lire('maison');
if (count($cible['biens'])) { http_response_code(409); echo '{"erreur":"la recherche maison contient déjà des biens : fusion déjà faite"}'; exit; }

$sources = array(); $statuts = array(); $avis = array();
foreach (array('longere', 'plainpied') as $r) {
    $sources[$r] = donnees_lire($r);
    $statuts[$r] = m_json($base . '/' . $r . '/statuts.json');
    $avis[$r] = m_json($base . '/' . $r . '/avis.json');
}
try { $f = fusion_calculer($sources, $statuts, $avis); }
catch (InvalidArgumentException $e) { http_response_code(500); echo json_encode(array('erreur' => $e->getMessage()), JSON_UNESCAPED_UNICODE); exit; }

$appliquer = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $in = json_decode(file_get_contents('php://input'), true);
    $appliquer = is_array($in) && !empty($in['appliquer']);
}
if (!$appliquer) { echo json_encode(array('simulation' => true, 'rapport' => $f['rapport'], 'alias' => $f['alias']), JSON_UNESCAPED_UNICODE); exit; }

$dir = $base . '/maison';
if (!is_dir($dir . '/photos')) @mkdir($dir . '/photos', 0750, true);
$modele = json_decode((string) @file_get_contents(__DIR__ . '/recherches/maison/data.json'), true);
$data = $modele;
$data['biens'] = $f['data']['biens'];
$data['vus'] = $f['data']['vus'];
$data['sources'] = $f['data']['sources'];
$data['maj'] = donnees_aujourdhui();

// Photos en cache : <id>-<empreinte> de l'ancienne recherche, recopiées sous le nouvel id.
$copiees = 0;
foreach ($f['photos'] as $p) {
    foreach ((array) glob($base . '/' . $p['r'] . '/photos/' . $p['de'] . '-*') as $src) {
        if (!preg_match('/^' . preg_quote($p['de'], '/') . '-([0-9a-f]{10})$/', basename($src), $m)) continue;
        $dst = $dir . '/photos/' . $p['vers'] . '-' . $m[1];
        if (!is_file($dst) && @copy($src, $dst)) $copiees++;
    }
}

$erreurs = array();
$x = donnees_ecrire_json($dir . '/data.json', $data); if ($x !== true) $erreurs[] = 'data.json : ' . $x;
$x = donnees_ecrire_json($dir . '/statuts.json', (object) $f['statuts']); if ($x !== true) $erreurs[] = 'statuts.json : ' . $x;
$x = donnees_ecrire_json($dir . '/avis.json', (object) $f['avis']); if ($x !== true) $erreurs[] = 'avis.json : ' . $x;

// Numéros : même verrou que numeroter() ; les anciennes clés longere/… et plainpied/… sont retirées.
$nf = numeros_fichier();
$fp = fopen($nf, 'c+');
if ($fp) flock($fp, LOCK_EX);
$j = json_decode($fp ? stream_get_contents($fp) : '', true);
if (!is_array($j) || !isset($j['carte'])) $j = array('suivant' => 1, 'carte' => array());
@file_put_contents(dirname($nf) . '/numeros.avant-fusion.json', json_encode($j));
foreach (array_keys($j['carte']) as $k) if (strpos($k, 'longere/') === 0 || strpos($k, 'plainpied/') === 0) unset($j['carte'][$k]);
foreach ($f['carte'] as $k => $n) $j['carte'][$k] = $n;
$j['alias'] = $f['alias'];
if ($fp) { ftruncate($fp, 0); rewind($fp); fwrite($fp, json_encode($j)); fflush($fp); flock($fp, LOCK_UN); fclose($fp); }
else $erreurs[] = 'numeros.json : ouverture impossible';

if ($erreurs) http_response_code(500);
echo json_encode(array('simulation' => false, 'ok' => !$erreurs, 'erreurs' => $erreurs, 'photos_copiees' => $copiees, 'rapport' => $f['rapport'], 'alias' => $f['alias']), JSON_UNESCAPED_UNICODE);
