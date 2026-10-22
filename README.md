# Recherches de maison

Page de suivi de la recherche de maison, déployée sur l'hébergement OVH (intégration Git).

- maison/ : la page de suivi (sélecteur de recherche), api.php (statuts partagés, stockés dans ~/maison-data/<recherche>/), photo.php.
- maison/recherches.json : liste des recherches.
- maison/recherches/<id>/data.json et photos/ : mis à jour chaque matin de semaine par la veille automatique.

Le dépôt contient le code ; chaque push déclenche le déploiement OVH via webhook.
La veille automatique n'écrit pas dans le dépôt : elle envoie la liste à jour à `maison/maj.php?r=<id>` (POST du JSON complet), rangée dans `~/maison-data/<id>/data.json` avec un historique daté. La page la lit via `maison/data.php?r=<id>` ; `maison/recherches/<id>/data.json` ne sert que de liste de départ.

Scripts locaux (`scripts/`, Python, bibliothèque standard) :

- `marche.py` : prix de vente au m² par commune, à partir des fichiers DVF.
- `adresse.py` : adresse des biens à partir de leur champ `indices` (valeurs du DPE et terrain), par la base DPE de l’ADEME et le cadastre Etalab. Il lit et écrit le site avec un jeton de script (`~/.config/maison/token`, empreinte dans `maison/auth-lib.php`). Mode d’emploi : `maison/outils.md`, section 4. Tests : `python3 -m unittest scripts/test_adresse.py`.
- `indices_bienici.py` : complète le champ `indices` des biens actifs à partir de leurs annonces Bien'ici. Bien'ici publie chaque annonce en JSON (`realEstateAd.json?id=<id>`), avec toutes les valeurs du DPE (diagnostic de performance énergétique). Le script ajoute seulement les clés absentes et signale les biens dont la commune diffère de celle de Bien'ici. Il utilise le même jeton que `adresse.py`. Mode d’emploi : `maison/outils.md`, section 4. Tests : `python3 -m unittest scripts/test_indices_bienici.py`.
