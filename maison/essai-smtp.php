<?php
// Essai temporaire de livraison : POST {"a": [adresses], "variante": "A" | "B" | "C"} -> {"ok": true} ou 502.
// A : texte court seul. B : texte et HTML courts, même forme que le Récapitulatif. C : Récapitulatif 20261102-124534 complet.
require_once __DIR__ . '/auth-lib.php';
require_once __DIR__ . '/recapitulatif-lib.php';
require_once __DIR__ . '/smtp-lib.php';
auth_exiger();
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
$in = json_decode(file_get_contents('php://input'), true);
$a = isset($in['a']) ? $in['a'] : null;
$v = isset($in['variante']) ? (string) $in['variante'] : '';
$base = 'https://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/';
$page = $base . 'recapitulatif.php?id=20261102-124534';
// P : accueil et réponse EHLO du serveur joint, sans authentification.
if ($v === 'P') {
    $ctx = stream_context_create(array('ssl' => array('capture_peer_cert' => true)));
    $flux = @stream_socket_client(RECAP_SMTP, $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $ctx);
    if (!$flux) { http_response_code(502); echo json_encode(array('erreur' => $errstr)); exit; }
    $accueil = smtp_lire($flux);
    fwrite($flux, "EHLO damien-gustave.fr\r\n");
    $ehlo = smtp_lire($flux);
    fwrite($flux, "QUIT\r\n");
    $o = stream_context_get_options($ctx);
    $cert = isset($o['ssl']['peer_certificate']) ? openssl_x509_parse($o['ssl']['peer_certificate']) : null;
    echo json_encode(array('ip' => stream_socket_get_name($flux, true), 'accueil' => $accueil, 'ehlo' => $ehlo,
        'certificat' => $cert ? $cert['subject'] : null, 'emetteur' => $cert ? $cert['issuer'] : null, 'local' => stream_socket_get_name($flux, false)), JSON_UNESCAPED_UNICODE);
    fclose($flux);
    exit;
}
try {
    if ($v === 'A') {
        $b = recap_courriel('Essai A', '<p>x</p>', $page, $a, $a[0]);
        $entetes = explode("\r\n\r\n", $b['message'], 2);
        $entetes = preg_replace('/Content-Type: multipart\/alternative; boundary="[^"]+"/', "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit", $entetes[0]);
        $m = array('destinataires' => $b['destinataires'], 'message' => $entetes . "\r\n\r\nBonjour,\r\n\r\nCeci est l'essai A, en texte court.\r\n");
    } elseif ($v === 'B') {
        $m = recap_courriel('Essai B', '<p>Bonjour,</p><p>Ceci est l\'essai B, en HTML court.</p>', $page, $a, $a[0]);
    } elseif ($v === 'C') {
        $p = json_decode((string) @file_get_contents(recap_fichier('20261102-124534')), true);
        $m = recap_courriel('Essai C : ' . $p['objet'], recap_html($p['calc'], $base, RECAP_FICHES_MAX, $page), $page, $a, $a[0]);
    } else {
        http_response_code(400); echo '{"erreur":"variante A, B ou C"}'; exit;
    }
    $smtp = recap_smtp_identifiants();
    $reponse = smtp_envoyer(smtp_ouvrir(RECAP_SMTP), $smtp['utilisateur'], $smtp['mot_de_passe'], RECAP_EXPEDITEUR, $m['destinataires'], $m['message']);
} catch (Exception $e) {
    http_response_code(502); echo json_encode(array('erreur' => $e->getMessage()), JSON_UNESCAPED_UNICODE); exit;
}
echo json_encode(array('ok' => true, 'variante' => $v, 'octets' => strlen($m['message']), 'reponse' => $reponse), JSON_UNESCAPED_UNICODE);
