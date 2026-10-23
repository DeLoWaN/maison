<?php
// Prix du marché d'une recherche (marche.json de la recherche qui fournit sa zone, construit par scripts/marche.py).
// GET marche.php[?r=<recherche>] : quartiles du prix au m² des maisons vendues, par commune et par bande.
// Compatible PHP 5.4.
require_once __DIR__ . '/auth-lib.php';
auth_exiger(); // réservé aux comptes Google de la famille
require_once __DIR__ . '/donnees-lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=86400');
$r = donnees_recherche_demandee(isset($_GET['r']) ? $_GET['r'] : '');
$f = __DIR__ . '/recherches/' . donnees_zone_source($r) . '/marche.json';
if (!donnees_recherche_valide($r) || !is_file($f)) { http_response_code(404); echo '{"erreur":"pas de prix du marché pour cette recherche"}'; exit; }
readfile($f);
