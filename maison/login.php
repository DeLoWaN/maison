<?php
// GET  login.php          -> {"email": "..."} si connecté, sinon 401
// POST login.php {credential: <jeton Google>}  -> vérifie le jeton auprès de Google, ouvre la session
// POST login.php?sortir   -> ferme la session
require __DIR__ . '/auth-lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $u = auth_utilisateur();
    if ($u === null) { http_response_code(401); echo json_encode(array('erreur' => 'connexion requise', 'https' => auth_https())); exit; }
    echo json_encode(array('email' => $u, 'avis' => auth_peut_aviser($u)));
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

if (isset($_GET['sortir'])) { auth_fermer(); echo '{"ok":true}'; exit; }

$in = json_decode(file_get_contents('php://input'), true);
$jeton = is_array($in) && isset($in['credential']) ? (string) $in['credential'] : '';
if (!preg_match('/^[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+$/', $jeton)) {
    http_response_code(400); echo '{"erreur":"jeton absent"}'; exit;
}

// Vérification par Google lui-même (signature, expiration), puis contrôles locaux.
$ch = curl_init('https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($jeton));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
$rep = curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$t = json_decode((string) $rep, true);
if ($code !== 200 || !is_array($t)) { http_response_code(403); echo '{"erreur":"jeton refusé par Google"}'; exit; }

$ok = isset($t['aud'], $t['iss'], $t['email'], $t['exp'])
    && $t['aud'] === GOOGLE_CLIENT_ID
    && in_array($t['iss'], array('accounts.google.com', 'https://accounts.google.com'), true)
    && (int) $t['exp'] > time()
    && isset($t['email_verified']) && ($t['email_verified'] === true || $t['email_verified'] === 'true');
if (!$ok) { http_response_code(403); echo '{"erreur":"jeton invalide"}'; exit; }

if (!auth_autorise($t['email'])) {
    http_response_code(403);
    echo json_encode(array('erreur' => 'compte non autorisé', 'email' => $t['email']));
    exit;
}
auth_ouvrir($t['email']);
echo json_encode(array('ok' => true, 'email' => strtolower($t['email'])));
