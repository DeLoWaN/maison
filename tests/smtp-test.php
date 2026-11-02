<?php
// Tests de smtp-lib.php avec un faux serveur SMTP. Lancement : php tests/smtp-test.php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../maison/smtp-lib.php';

$echecs = 0;
function verifier($nom, $attendu, $obtenu) {
    global $echecs;
    if ($attendu === $obtenu) { echo "ok    $nom\n"; return; }
    $echecs++;
    echo "ÉCHEC $nom\n  attendu : " . json_encode($attendu, JSON_UNESCAPED_UNICODE) . "\n  obtenu  : " . json_encode($obtenu, JSON_UNESCAPED_UNICODE) . "\n";
}

// Faux serveur : répond à chaque commande reçue par la réponse suivante de FauxServeur::$reponses et garde tout ce que le client écrit.
class FauxServeur {
    public static $reponses = array();
    public static $recu = '';
    public static $ferme = false;
    private $tampon = '';
    private $dansData = false;
    public $context;
    function stream_open($chemin, $mode, $options, &$ouvert) { self::$recu = ''; self::$ferme = false; $this->suivante(); return true; }
    private function suivante() { if (self::$reponses) $this->tampon .= array_shift(self::$reponses); }
    function stream_read($n) { $x = substr($this->tampon, 0, $n); $this->tampon = (string) substr($this->tampon, $n); return $x; }
    function stream_write($x) {
        self::$recu .= $x;
        // Après DATA, le serveur ne répond qu'à la ligne « . » finale.
        if ($this->dansData && substr(self::$recu, -5) !== "\r\n.\r\n") return strlen($x);
        $this->dansData = !$this->dansData && $x === "DATA\r\n";
        $this->suivante();
        return strlen($x);
    }
    function stream_eof() { return $this->tampon === '' && !self::$reponses; }
    function stream_close() { self::$ferme = true; }
    function stream_set_option($o, $a, $b) { return false; }
}
stream_wrapper_register('faux', 'FauxServeur');

function dialogue($reponses, $de, $a, $message) {
    FauxServeur::$reponses = $reponses;
    $flux = fopen('faux://smtp', 'r+');
    try { return smtp_envoyer($flux, 'maison@exemple.fr', 'secret', $de, $a, $message); }
    catch (SmtpException $e) { return $e->getMessage(); }
}
$accepte = array("220 smtp prêt\r\n", "250-smtp bonjour\r\n250-AUTH LOGIN PLAIN\r\n250 8BITMIME\r\n", "334 VXNlcm5hbWU6\r\n", "334 UGFzc3dvcmQ6\r\n",
    "235 2.7.0 ok\r\n", "250 ok\r\n", "250 ok\r\n", "251 ok\r\n", "354 go\r\n", "250 2.0.0 accepté\r\n", "221 au revoir\r\n");
$message = "Subject: essai\r\n\r\nligne un\n.ligne avec un point\r\n.\r\nfin\r\n";

// Envoi accepté.
$reponse = dialogue($accepte, 'maison@exemple.fr', array('a@exemple.fr', 'b@exemple.fr'), $message);
verifier('envoi accepté : renvoie la réponse du serveur au message', '250 2.0.0 accepté', $reponse);
verifier('commandes dans l\'ordre, identifiants en base64', implode("\r\n", array(
    'EHLO exemple.fr', 'AUTH LOGIN', base64_encode('maison@exemple.fr'), base64_encode('secret'),
    'MAIL FROM:<maison@exemple.fr>', 'RCPT TO:<a@exemple.fr>', 'RCPT TO:<b@exemple.fr>', 'DATA',
    "Subject: essai\r\n\r\nligne un\r\n..ligne avec un point\r\n..\r\nfin", '.', 'QUIT', '')), FauxServeur::$recu);
verifier('connexion fermée après l\'envoi', true, FauxServeur::$ferme);

// Refus du serveur : l'erreur nomme l'étape et cite le serveur, sans le mot de passe.
$refus = $accepte; $refus[4] = "535 5.7.8 authentication failed\r\n";
$erreur = dialogue($refus, 'maison@exemple.fr', array('a@exemple.fr'), $message);
verifier('mot de passe refusé', 'mot de passe refusé : 535 5.7.8 authentication failed', $erreur);
verifier('aucun message envoyé après le refus', false, strpos(FauxServeur::$recu, 'MAIL FROM'));
verifier('connexion fermée après le refus', true, FauxServeur::$ferme);
verifier('le mot de passe n\'apparaît pas dans l\'erreur', false, strpos($erreur, 'secret') !== false || strpos($erreur, base64_encode('secret')) !== false);

$refus = $accepte; $refus[6] = "550 5.1.1 adresse inconnue\r\n";
verifier('destinataire refusé', 'destinataire a@exemple.fr refusé : 550 5.1.1 adresse inconnue', dialogue($refus, 'maison@exemple.fr', array('a@exemple.fr', 'b@exemple.fr'), $message));
verifier('pas de DATA après un destinataire refusé', false, strpos(FauxServeur::$recu, 'DATA'));

$refus = $accepte; $refus[9] = "550 5.7.26 rejeté : SPF ou DKIM\r\n";
verifier('message refusé', 'message refusé : 550 5.7.26 rejeté : SPF ou DKIM', dialogue($refus, 'maison@exemple.fr', array('a@exemple.fr', 'b@exemple.fr'), $message));

verifier('serveur qui coupe la connexion', 'connexion coupée par le serveur après : 250-smtp bonjour', dialogue(array("220 prêt\r\n", "250-smtp bonjour\r\n"), 'maison@exemple.fr', array('a@exemple.fr'), $message));
verifier('accueil refusé', 'accueil refusé : 554 pas de service', dialogue(array("554 pas de service\r\n"), 'maison@exemple.fr', array('a@exemple.fr'), $message));

// Connexion impossible.
try { smtp_ouvrir('tcp://127.0.0.1:1', 2); $erreur = null; } catch (SmtpException $e) { $erreur = $e->getMessage(); }
verifier('connexion impossible : SmtpException', true, is_string($erreur) && strpos($erreur, 'connexion à tcp://127.0.0.1:1 impossible') === 0);

echo $echecs ? "\n$echecs test(s) en échec.\n" : "\nTous les tests passent.\n";
exit($echecs ? 1 : 0);
