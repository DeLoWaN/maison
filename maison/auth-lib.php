<?php
// Connexion par compte Google, réservée à la famille. Compatible PHP 5.4.
// Le dépôt étant public, la liste des comptes autorisés n'y figure que sous forme d'empreintes
// SHA-256 des adresses (en minuscules). Pour ajouter quelqu'un :
//   printf '%s' 'adresse@gmail.com' | sha256sum
// La session tient dans un cookie signé (HMAC), valable un an ; la clé de signature est créée
// au premier usage dans <home>/maison-data/secret.key, hors du dossier publié.

define('GOOGLE_CLIENT_ID', '103468284819-hoa0opjn0tqaipicq2tnhrfa8ouhmld0.apps.googleusercontent.com');
define('AUTH_COOKIE', 'maison_auth');
define('AUTH_DUREE', 365 * 86400);

function auth_empreintes() {
    return array(
        '3d5ec50c1db31134b67db2fdd8f18ce3e29c3220c0dfadc5510127dd3eb8434d', // papa
        '5ef515038f6a3a41455c3acabb2fdcd1f9aab806c8cfe41fb25aa4b4d55599ef', // maman
        'dc06959ba0335f038504be0dba3cba2a2124797613eb3bb30683d59555f10d45', // Damien (perso)
        'ef73979e4255d2aee18a039089a3431f2b33af68dfaed27f1367a0ba07590dd5', // Damien (pro)
        'b15f54ea3d6079e2dcd29ab2dabf8d292a25134625722704c44e5c5aa49594c3', // Aurélia
    );
}

// Comptes qui donnent l'avis « Damien et Aurélia » (les parents le voient sans pouvoir le changer).
function auth_aviseurs() {
    return array(
        'dc06959ba0335f038504be0dba3cba2a2124797613eb3bb30683d59555f10d45' => 'Damien',
        'ef73979e4255d2aee18a039089a3431f2b33af68dfaed27f1367a0ba07590dd5' => 'Damien',
        'b15f54ea3d6079e2dcd29ab2dabf8d292a25134625722704c44e5c5aa49594c3' => 'Aurélia',
    );
}
function auth_peut_aviser($email) {
    $a = auth_aviseurs();
    return isset($a[hash('sha256', strtolower(trim((string) $email)))]);
}
function auth_prenom($email) {
    $a = auth_aviseurs();
    $h = hash('sha256', strtolower(trim((string) $email)));
    return isset($a[$h]) ? $a[$h] : '';
}

function auth_autorise($email) {
    return in_array(hash('sha256', strtolower(trim((string) $email))), auth_empreintes(), true);
}

function auth_cle() {
    $f = dirname(dirname(__DIR__)) . '/maison-data/secret.key';
    $k = is_file($f) ? trim((string) @file_get_contents($f)) : '';
    if (strlen($k) < 64) {
        if (!is_dir(dirname($f))) @mkdir(dirname($f), 0750, true);
        $k = bin2hex(openssl_random_pseudo_bytes(32));
        @file_put_contents($f, $k);
        @chmod($f, 0600);
    }
    return $k;
}

function auth_https() {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);
}

function auth_b64($s) { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function auth_b64d($s) { return base64_decode(strtr($s, '-_', '+/')); }

// Adresse du compte connecté, ou null.
function auth_utilisateur() {
    if (empty($_COOKIE[AUTH_COOKIE])) return null;
    $p = explode('.', (string) $_COOKIE[AUTH_COOKIE]);
    if (count($p) !== 3) return null;
    $attendu = hash_hmac('sha256', $p[0] . '.' . $p[1], auth_cle());
    if (!auth_egal($attendu, $p[2])) return null;
    if ((int) $p[1] < time()) return null;
    $email = auth_b64d($p[0]);
    return auth_autorise($email) ? $email : null;
}

function auth_egal($a, $b) {
    if (strlen($a) !== strlen($b)) return false;
    $r = 0;
    for ($i = 0; $i < strlen($a); $i++) $r |= ord($a[$i]) ^ ord($b[$i]);
    return $r === 0;
}

function auth_cookie($valeur, $expire) {
    header('Set-Cookie: ' . AUTH_COOKIE . '=' . $valeur
        . '; Expires=' . gmdate('D, d M Y H:i:s', $expire) . ' GMT; Path=/maison/; HttpOnly; SameSite=Lax'
        . (auth_https() ? '; Secure' : ''), false);
}

function auth_ouvrir($email) {
    $exp = time() + AUTH_DUREE;
    $base = auth_b64(strtolower($email)) . '.' . $exp;
    auth_cookie($base . '.' . hash_hmac('sha256', $base, auth_cle()), $exp);
}

function auth_fermer() { auth_cookie('x', time() - 3600); }

// À appeler en tête des pages de données : répond 401 si personne n'est connecté.
function auth_exiger() {
    if (!auth_https()) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo '{"erreur":"https requis"}';
        exit;
    }
    $u = auth_utilisateur();
    if ($u === null) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo '{"erreur":"connexion requise"}';
        exit;
    }
    return $u;
}
