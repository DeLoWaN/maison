# Méthode : retrouver l'adresse d'une maison à partir de ses annonces

Méthode testée le 19 octobre 2026 sur 10 maisons (9 adresses trouvées, dont 6 avec une confiance forte).
À suivre dans l'ordre, sans réinventer : chaque étape indique ce qui marche et ce qui a échoué.

## Règles
- Uniquement des données publiques ouvertes sur les bâtiments : DPE (ADEME), cadastre (Etalab, sans nom de propriétaire), Base Adresse Nationale, photos aériennes IGN.
- Ne cherche jamais le nom du propriétaire ou du vendeur. Ne consulte aucun annuaire de personnes, réseau social ou registre d'entreprises.
- L'adresse sert à situer la maison et son environnement. Ne contacte jamais le vendeur et ne publie jamais l'adresse sur une page publique.
- Ne retape jamais une valeur ou une adresse à la main : copie ce que renvoient la page ou l'outil.

## Outils (ceux qui marchent)
- **DPE** : connecteur ADEME `search_data`, datasetId `meg-83tjwtg8dyz4vv7h1dqe` (DPE logements existants depuis juillet 2021).
  - Sans connecteur, depuis un onglet du navigateur intégré : `fetch('https://data.ademe.fr/data-fair/api/v1/datasets/dpe03existant/lines?size=50&select=...&<filtres>')`.
  - Filtres : `champ_eq`, `champ_in`, `champ_gte`, `champ_lte`, `champ_search`. Le paramètre `qs` provoque une erreur : ne pas l'utiliser.
  - Champs utiles : `adresse_ban`, `code_insee_ban`, `code_postal_ban`, `type_batiment`, `surface_habitable_logement`, `conso_5_usages_par_m2_ep`, `emission_ges_5_usages_par_m2`, `etiquette_dpe`, `etiquette_ges`, `cout_total_5_usages`, `date_etablissement_dpe`, `periode_construction`, `nombre_niveau_logement`.
- **Code INSEE** des communes d'un code postal : `https://geo.api.gouv.fr/communes?codePostal=14310&fields=nom,code`.
  - Codes vérifiés des communes nouvelles : Thue et Mue 14098, Val d'Arry 14475, Laize-Clinchamps 14349. Les anciennes communes (Putot, Cheux, Noyers-Bocage…) sont dans la commune nouvelle : `adresse_ban` contient souvent leur nom.
- **Cadastre** d'une commune entière, rapide : `https://cadastre.data.gouv.fr/bundler/cadastre-etalab/communes/<INSEE>/geojson/parcelles`.
  - Le champ `contenance` est la surface en m².
  - Ne pas utiliser apicarto.ign.fr : il est lent et a échoué plusieurs fois.
- **Géocodage** : `https://api-adresse.data.gouv.fr/search/?limit=1&q=<adresse>`, ou le connecteur ADEME `geocode_address`.
  - Recherche inverse : `https://api-adresse.data.gouv.fr/reverse/?lon=..&lat=..&limit=2`.
- **Vue aérienne avec le cadastre** : carte Leaflet (déjà chargée sur http://damien-gustave.fr/maison/) avec deux couches.
  - `https://data.geopf.fr/wmts?SERVICE=WMTS&REQUEST=GetTile&VERSION=1.0.0&LAYER=ORTHOIMAGERY.ORTHOPHOTOS&STYLE=normal&TILEMATRIXSET=PM&FORMAT=image/jpeg&TILEMATRIX={z}&TILEROW={y}&TILECOL={x}`
  - `https://data.geopf.fr/wmts?SERVICE=WMTS&REQUEST=GetTile&VERSION=1.0.0&LAYER=CADASTRALPARCELS.PARCELLAIRE_EXPRESS&STYLE=PCI%20vecteur&TILEMATRIXSET=PM&FORMAT=image/png&TILEMATRIX={z}&TILEROW={y}&TILECOL={x}`
  - Faire ensuite une capture d'écran.
- Travailler dans un onglet à soi : ne jamais utiliser l'onglet que la personne regarde.

## Étape 1 : relever les indices sur TOUS les liens du bien
Chaque site publie des détails différents. Lis chaque lien, en commençant par les plus riches.
- **Bien'ici** (le plus riche) : consommation en kWh/m²/an, émissions en kg CO₂/m²/an, « Date de réalisation du DPE », fourchette de dépense annuelle (« entre X € et Y € par an »).
  - La page met quelques secondes à s'afficher : attendre 5 s avant de lire.
- **Ouest-France Immo** : « Consommation (Energie primaire) X kWh/m².an », « Emissions Y kgCO2/m².an », surface du terrain au m² près.
  - Certaines annonces n'affichent aucun DPE.
- **iad France** : chiffres du DPE souvent présents.
- **SeLoger, Etreproprio** : seulement les lettres DPE/GES, l'année de construction et le terrain.
- **FNAIM** : fourchette de dépense. Le site renvoie souvent une erreur 503 ; réessayer plus tard.

Extraction, sur le texte de la page :
- kWh : `/(\d{1,4}(?:[.,]\d)?)\s*\*?\s*kWh/gi`
- CO₂ : `/(\d{1,4}(?:[.,]\d)?)\s*\*?\s*kg ?CO2/gi`
- dépense : `/entre [\d\s]+ ?€ et [\d\s]+ ?€/gi`
- date du DPE : `/Date de réalisation[^\n]*/`
- Le premier nombre trouvé est celui de l'annonce. Les suivants viennent souvent d'annonces « similaires » en bas de page.

À noter pour chaque maison :
- consommation, émissions, lettres DPE et GES, dépense min et max, date du DPE ;
- surface habitable, terrain (préciser s'il est exact ou arrondi : « 1 000 m² », « environ », « 1 ha » sont arrondis), année de construction, plain-pied ou non ;
- commune et code postal ;
- lieux cités dans la description (hameau, lieu-dit, « à 5 km de… »).

## Étape 2 : chercher dans la base DPE
Essaie dans cet ordre, et arrête-toi dès qu'un résultat unique apparaît.
1. Commune (`code_insee_ban_eq`) et consommation à ±3 (`_gte`/`_lte`). Garder ensuite les résultats dont les émissions sont à ±2.
2. Sans chiffres : `etiquette_dpe_eq`, `etiquette_ges_eq`, `type_batiment_eq=maison`, surface à ±8 %.
3. Dépense annuelle : `cout_total_5_usages` ≈ (min + max) / 2, à ±2 %. La fourchette affichée vaut à peu près cette dépense ±15 %. Indice très discriminant.
4. Date du DPE de l'annonce : `date_etablissement_dpe` à ±3 jours (la date de visite peut précéder la date d'établissement d'un jour).
5. Plain-pied : `nombre_niveau_logement_eq=1`.
6. **Rien dans la commune** : refais la même recherche sur le code postal (`code_postal_ban_eq`). Les agences donnent souvent la ville voisine (« Villers-Bocage » était à Maisoncelles-Pelvey) ou l'ancienne commune.

Pièges vus :
- La surface du DPE peut différer de celle de l'annonce (dépendance comptée ou non : 183,6 m² au DPE pour 110 m² annoncés). N'écarte pas un candidat sur la seule surface si la consommation, les émissions et la dépense concordent.
- La consommation de l'annonce peut ne correspondre à aucun DPE de la base (ancien DPE, erreur de saisie : 196 annoncés, 173 au DPE). Passe alors au cadastre.
- Un même bâtiment a parfois deux DPE identiques : c'est un doublon, pas deux candidats.
- Garde le lien de la vue filtrée (filteredViewUrl) comme preuve.

## Étape 3 : cadastre
1. **Terrain donné au m² près** : charge les parcelles de la commune et de toutes les communes du code postal. Cherche `contenance` = terrain ±3 m², puis fais une recherche inverse au centre de chaque parcelle.
   - Une parcelle de surface exacte, avec un DPE cohérent (surface, période) à l'adresse voisine : confiance forte.
2. **Pour confirmer un candidat DPE** : prends la parcelle qui contient l'adresse et ses voisines à moins de 150 m. Cherche une somme de 1 à 4 parcelles égale au terrain ±3 %.
   - Avec beaucoup de petites parcelles, une somme qui tombe juste est un indice faible.
   - Dans les grands terrains de campagne, l'absence de somme ne prouve rien.

## Étape 4 : vue aérienne
Place la carte sur l'adresse, au zoom 18 à 19, et vérifie :
- le nombre de bâtiments et de dépendances ;
- maison mitoyenne ou isolée ;
- grand terrain ou lotissement (un lotissement récent écarte une « maison en pierre ») ;
- la forme de la parcelle.

## Étape 5 : conclure
- **Forte** : au moins deux indices indépendants concordent, sans contradiction. Exemples : consommation, émissions et dépense uniques dans la commune ; ou parcelle exacte plus DPE cohérent à l'adresse.
- **Probable** : un seul indice solide, ou une contradiction non expliquée (commune annoncée différente, valeurs DPE décalées).
- **Non trouvée** : rien de concluant. Annonce sans DPE chiffré et terrain arrondi : c'est le cas le plus fréquent d'échec.

Note pour chaque maison : l'adresse, la confiance, une ligne de preuves (valeurs qui concordent), le lien de la vue DPE filtrée.
