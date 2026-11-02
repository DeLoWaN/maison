<?php
// Client SMTP minimal : connexion chiffrée, AUTH LOGIN, un message. Compatible PHP 5.4.

class SmtpException extends RuntimeException {}

/** Ouvre une connexion chiffrée, par exemple smtp_ouvrir('ssl://smtp.mail.ovh.net:465'). Lève SmtpException si la connexion échoue. */
function smtp_ouvrir($adresse, $delai = 30) {
    $flux = @stream_socket_client($adresse, $errno, $errstr, $delai);
    if (!$flux) throw new SmtpException('connexion à ' . $adresse . ' impossible : ' . $errstr . ' (' . $errno . ')');
    stream_set_timeout($flux, $delai);
    return $flux;
}

// Lit une réponse entière, sur une ou plusieurs lignes (« 250-… » puis « 250 … »).
function smtp_lire($flux) {
    $reponse = '';
    while (($ligne = fgets($flux, 1024)) !== false) {
        $reponse .= $ligne;
        if (strlen($ligne) < 4 || $ligne[3] !== '-') return $reponse;
    }
    throw new SmtpException('connexion coupée par le serveur' . ($reponse === '' ? '' : ' après : ' . trim($reponse)));
}

// Envoie une commande et lève SmtpException si le code de la réponse n'est pas attendu. $etape nomme la commande dans l'erreur, sans son contenu.
function smtp_commande($flux, $commande, $codes, $etape) {
    if ($commande !== null && fwrite($flux, $commande . "\r\n") === false) throw new SmtpException($etape . ' : écriture impossible');
    $reponse = smtp_lire($flux);
    if (!in_array((int) substr($reponse, 0, 3), $codes, true)) throw new SmtpException($etape . ' refusé : ' . trim($reponse));
    return $reponse;
}

// Double le point en début de ligne, sinon le serveur lit une ligne « . » comme la fin du message.
function smtp_points($message) {
    $message = preg_replace("/\r?\n/", "\r\n", $message);
    return preg_replace('/^\./m', '..', $message);
}

/**
 * Envoie $message (en-têtes et corps, déjà formés) de $de à chaque adresse de $a, puis ferme la connexion.
 * Lève SmtpException au premier refus du serveur ; le message n'est alors envoyé à personne.
 */
function smtp_envoyer($flux, $utilisateur, $motDePasse, $de, $a, $message) {
    try {
        smtp_commande($flux, null, array(220), 'accueil');
        smtp_commande($flux, 'EHLO ' . substr(strrchr($de, '@'), 1), array(250), 'EHLO');
        smtp_commande($flux, 'AUTH LOGIN', array(334), 'AUTH');
        smtp_commande($flux, base64_encode($utilisateur), array(334), 'identifiant');
        smtp_commande($flux, base64_encode($motDePasse), array(235), 'mot de passe');
        smtp_commande($flux, 'MAIL FROM:<' . $de . '>', array(250), 'expéditeur');
        foreach ($a as $x) smtp_commande($flux, 'RCPT TO:<' . $x . '>', array(250, 251), 'destinataire ' . $x);
        smtp_commande($flux, 'DATA', array(354), 'DATA');
        smtp_commande($flux, rtrim(smtp_points($message), "\r\n") . "\r\n.", array(250), 'message');
        @fwrite($flux, "QUIT\r\n");
    } catch (SmtpException $e) {
        fclose($flux);
        throw $e;
    }
    fclose($flux);
}
