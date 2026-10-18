# Recherches de maison

Page de suivi de la recherche de maison, déployée sur l'hébergement OVH (intégration Git).

- maison/ : la page de suivi (sélecteur de recherche), api.php (statuts partagés, stockés dans ~/maison-data/<recherche>/), photo.php.
- maison/recherches.json : liste des recherches.
- maison/recherches/<id>/data.json et photos/ : mis à jour chaque matin de semaine par la veille automatique.

Le dépôt contient le code ; chaque push déclenche le déploiement OVH via webhook.
La veille automatique n'écrit pas dans le dépôt : elle envoie la liste à jour à `maison/maj.php?r=<id>` (POST du JSON complet), rangée dans `~/maison-data/<id>/data.json` avec un historique daté. La page la lit via `maison/data.php?r=<id>` ; `maison/recherches/<id>/data.json` ne sert que de liste de départ.
