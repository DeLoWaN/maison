<?php
// Photo d'une maison : d'abord la copie du dépôt (recherches/<r>/photos/<id>.jpg),
// sinon téléchargement unique depuis photo_url, gardé en cache hors du dossier déployé.

$r = isset($_GET['r']) ? (string) $_GET['r'] : '';
$id = isset($_GET['id']) ? (string) $_GET['id'] : '';
if (!preg_match('/^[a-z0-9-]{1,40}$/', $r) || !preg_match('/^[a-z0-9-]{1,60}$/', $id)) { http_response_code(400); exit; }
$base = __DIR__ . '/recherches/' . $r;
if (!is_dir($base)) { http_response_code(404); exit; }

function servir($chemin) {
    $info = @getimagesize($chemin);
    header('Content-Type: ' . ($info ? $info['mime'] : 'image/jpeg'));
    header('Cache-Control: public, max-age=604800');
    readfile($chemin);
    exit;
}

$local = $base . '/photos/' . $id . '.jpg';
if (is_file($local)) servir($local);

$cacheDir = dirname(dirname(__DIR__)) . '/maison-data/' . $r . '/photos';
$cache = $cacheDir . '/' . $id;
if (is_file($cache) && filesize($cache) > 0) servir($cache);

$data = json_decode(@file_get_contents($base . '/data.json'), true);
$url = null;
$biens = (is_array($data) && isset($data['biens'])) ? $data['biens'] : array();
foreach ($biens as $b) {
    if (isset($b['id']) && $b['id'] === $id) { $url = isset($b['photo_url']) ? $b['photo_url'] : null; break; }
}
if (!$url || !preg_match('#^https://#', $url)) { http_response_code(404); exit; }

if (!is_dir($cacheDir)) @mkdir($cacheDir, 0750, true);
$ctx = stream_context_create(['http' => [
    'timeout' => 15,
    'header' => "User-Agent: Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/124 Safari/537.36\r\nAccept: image/*\r\n",
]]);
$img = @file_get_contents($url, false, $ctx);
if ($img === false || strlen($img) < 500 || !@getimagesizefromstring($img)) { http_response_code(404); exit; }
file_put_contents($cache, $img);
servir($cache);
