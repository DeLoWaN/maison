<?php
// Photo d'une maison : d'abord la copie du dépôt (recherches/<r>/photos/<id>.jpg),
// sinon téléchargement unique depuis photo_url, gardé en cache hors du dossier déployé.
// photo.php?r=longere&id=<id>&diag=1 : n'affiche rien, renvoie en JSON le résultat
// du téléchargement (code HTTP, type, format détecté) pour savoir qui bloque.
// Compatible PHP 5.4 (version imposée par l'offre OVH).

$r = isset($_GET['r']) ? (string) $_GET['r'] : '';
$id = isset($_GET['id']) ? (string) $_GET['id'] : '';
$diag = isset($_GET['diag']);
if (!preg_match('/^[a-z0-9-]{1,40}$/', $r) || !preg_match('/^[a-z0-9-]{1,60}$/', $id)) { http_response_code(400); exit; }
$base = __DIR__ . '/recherches/' . $r;
if (!is_dir($base)) { http_response_code(404); exit; }

// Format reconnu d'après les premiers octets (getimagesize de PHP 5.4 ignore le WebP et l'AVIF).
function format_image($octets) {
    if (strlen($octets) < 12) return null;
    if (substr($octets, 0, 3) === "\xFF\xD8\xFF") return 'image/jpeg';
    if (substr($octets, 0, 8) === "\x89PNG\r\n\x1A\n") return 'image/png';
    if (substr($octets, 0, 4) === 'GIF8') return 'image/gif';
    if (substr($octets, 0, 4) === 'RIFF' && substr($octets, 8, 4) === 'WEBP') return 'image/webp';
    if (substr($octets, 4, 4) === 'ftyp' && in_array(substr($octets, 8, 4), array('avif', 'avis'), true)) return 'image/avif';
    return null;
}

function servir($chemin) {
    $type = format_image((string) @file_get_contents($chemin, false, null, 0, 16));
    header('Content-Type: ' . ($type ? $type : 'image/jpeg'));
    header('Cache-Control: public, max-age=604800');
    readfile($chemin);
    exit;
}

function repondre_diag($info) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($info);
    exit;
}

$local = $base . '/photos/' . $id . '.jpg';
if (!$diag && is_file($local)) servir($local);

$vivant = dirname(dirname(__DIR__)) . '/maison-data/' . $r . '/data.json';
$data = json_decode(@file_get_contents(is_file($vivant) ? $vivant : $base . '/data.json'), true);
$bien = null;
$biens = (is_array($data) && isset($data['biens'])) ? $data['biens'] : array();
foreach ($biens as $b) {
    if (isset($b['id']) && $b['id'] === $id) { $bien = $b; break; }
}
$url = ($bien && isset($bien['photo_url'])) ? $bien['photo_url'] : null;
if (!$url || !preg_match('#^https?://#', $url)) {
    if ($diag) repondre_diag(array('id' => $id, 'erreur' => 'pas de photo_url'));
    http_response_code(404); exit;
}

// Le cache dépend de l'adresse : si photo_url change, la nouvelle photo est téléchargée.
$cacheDir = dirname(dirname(__DIR__)) . '/maison-data/' . $r . '/photos';
$cache = $cacheDir . '/' . $id . '-' . substr(md5($url), 0, 10);
if (!$diag && is_file($cache) && filesize($cache) > 0) servir($cache);

// Referer : la fiche de l'annonce sur le même site que la photo si possible, sinon le site de la photo.
$hotePhoto = parse_url($url, PHP_URL_HOST);
$racine = function ($h) { $p = explode('.', (string) $h); return implode('.', array_slice($p, -2)); };
$referer = parse_url($url, PHP_URL_SCHEME) . '://' . $hotePhoto . '/';
if ($bien && isset($bien['liens']) && is_array($bien['liens'])) {
    foreach ($bien['liens'] as $l) {
        if (!isset($l['url'])) continue;
        if ($racine(parse_url($l['url'], PHP_URL_HOST)) === $racine($hotePhoto)) { $referer = $l['url']; break; }
    }
}

$entetes = array(
    'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36',
    'Accept: image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
    'Accept-Language: fr-FR,fr;q=0.9',
    'Referer: ' . $referer,
);

$img = false; $code = 0; $type = ''; $erreur = ''; $via = '';
if (function_exists('curl_init')) {
    $via = 'curl';
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    @curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $entetes);
    curl_setopt($ch, CURLOPT_ENCODING, '');
    $img = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    if ($img === false) $erreur = curl_error($ch);
    curl_close($ch);
    if ($code >= 400) $img = false;
} else {
    $via = 'stream';
    $ctx = stream_context_create(array('http' => array(
        'timeout' => 20, 'ignore_errors' => true,
        'header' => implode("\r\n", $entetes) . "\r\n",
    )));
    $img = @file_get_contents($url, false, $ctx);
    if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) $code = (int) $m[1];
    if ($img === false) { $e = error_get_last(); $erreur = $e ? $e['message'] : 'échec'; }
    if ($code >= 400) $img = false;
}

$format = ($img !== false) ? format_image($img) : null;

if ($diag) {
    repondre_diag(array(
        'id' => $id, 'url' => $url, 'via' => $via, 'referer' => $referer, 'http' => $code,
        'type_annonce' => $type, 'octets' => ($img === false ? 0 : strlen($img)),
        'format' => $format, 'erreur' => $erreur,
        'debut' => ($img === false ? '' : bin2hex(substr($img, 0, 12))),
    ));
}

if ($img === false || strlen($img) < 500 || !$format) { http_response_code(404); exit; }
if (!is_dir($cacheDir)) @mkdir($cacheDir, 0750, true);
// Une seule photo en cache par bien : les versions d'une ancienne photo_url sont retirées.
foreach ((array) glob($cacheDir . '/' . $id . '-*') as $vieux) if ($vieux !== $cache && preg_match('/^' . preg_quote($id, '/') . '-[0-9a-f]{10}$/', basename($vieux))) @unlink($vieux);
if (@file_put_contents($cache, $img) !== strlen($img)) { @unlink($cache); header('Content-Type: ' . ($format ? $format : 'image/jpeg')); echo $img; exit; }
servir($cache);
