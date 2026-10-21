# Méthode : retrouver l'adresse d'une maison à partir de ses annonces

Méthode testée le 19 octobre 2026 sur 10 maisons (9 adresses trouvées, dont 7 avec une confiance forte, après correction d'une erreur relevée par Damien).
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
  - Les chiffres sont dans une vignette graphique : le texte brut de la page ne les contient pas toujours au bon endroit. Ce qui marche : prendre le plus petit élément qui contient à la fois « kWh » et « kgCO2 » et lire son texte (`C 171 kWh/m² .an 6* kgCO2/m² .an` = lettre, consommation, émissions).
  - Surfaces : les lignes qui ne contiennent que « N m² » donnent la surface habitable puis le terrain.
  - Attention : la fourchette « entre X € et Y € » peut venir d'un bloc commun à plusieurs annonces (même fourchette vue sur deux maisons différentes de Sommervieu). Ne t'en sers que si elle est dans le texte de l'annonce elle-même.
  - Certaines annonces n'affichent aucun DPE.
- **iad France** : chiffres du DPE souvent présents.
- **SeLoger, Etreproprio** : seulement les lettres DPE/GES, l'année de construction et le terrain. Pour les chiffres, va sur l'annonce d'origine (étape 1 bis).
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

## Étape 1 bis : DPE illisible ou introuvable → l'annonce d'origine, sur le site de l'agence
Les agrégateurs (Etreproprio, SeLoger, Zimo, Les Clefs de chez moi, parfois Ouest-France Immo) recopient mal le DPE de l'annonce d'origine : lettres seules, vignette vide, chiffres faux. Le site de l'agence ou du réseau publie presque toujours le DPE complet : kWh, kg CO₂, fourchette de dépense, parfois la date.
Quand le faire :
- l'annonce n'a pas de DPE chiffré lisible ;
- ou ses chiffres ne correspondent à aucun DPE de la base (étape 2 sans résultat), car l'agrégateur les a peut-être mal recopiés.

1. Sur la fiche de l'agrégateur, relève qui propose le bien (« Ce bien est proposé par Safti », « Contactez votre conseiller SAFTI », « Agence … ») et la référence si elle figure (« Réf. », numéro de mandat).
2. Va sur le site de cette agence ou de ce réseau, depuis sa page d'accueil. Si l'adresse du site n'est pas sur la fiche, cherche-la avec WebSearch « <nom de l'agence> <commune> ». Ouvre la liste des maisons à vendre de la commune, ou du département, et repère l'annonce par la commune, la surface, le nombre de pièces, le terrain et le prix (le prix honoraires inclus est le même partout).
3. Lis le DPE sur cette fiche, avec les mêmes expressions qu'à l'étape 1. Reprends ensuite à l'étape 2.
4. Ajoute cette fiche aux liens du bien : `{site: "<Agence> (agence)", url, ok: true}`. C'est un nouveau lien : un bien « non_trouvee » avec moins de 3 essais peut donc être retraité au passage suivant.

**Bien vendu par un notaire** (« Immobilier.notaires® et l'office notarial … vous proposent », « Annonce de l'étude … Notaires à … », référence de la forme « 14020-230 ») : l'annonce d'origine est sur immobilier.notaires.fr.
- Ouvre-la dans le navigateur intégré, jamais avec WebFetch : en lecture directe, la page revient vide. C'est pour ça que la source était notée « échec, page vide » ; dans le navigateur, le site marche.
- Liste par prix (le prix est le même que sur l'agrégateur) : `https://www.immobilier.notaires.fr/fr/annonces-immobilieres-liste?typeTransaction=VENTE&departement=14&prixMin=<prix - 1000>&prixMax=<prix + 1000>`. Repère la commune dans les adresses des fiches (`/annonce-immo/vente/maison/<commune>-14/<numéro>`), ouvre la fiche et vérifie la « Référence ».
- Sous « Les diagnostics énergétiques », clique « Plus de détails » : les kWh/m²/an et kg CO2/m²/an n'apparaissent qu'après ce clic. La fourchette « Dépenses théoriques annuelles d'énergie » est affichée juste dessous.
- Exemple du 21 octobre 2026 (n° 98, Anctoville) : SeLoger n'affichait que C/C. La fiche notaire donnait 161 kWh, 26 kg CO2, dépense de 2 410 à 3 300 €. Un seul DPE du 14240 concordait, avec une dépense de 2 853,80 € (milieu exact de la fourchette) : confiance forte. Le passage précédent avait vu ce DPE parmi deux pistes « non confirmées », faute d'avoir cherché l'annonce d'origine.

Règles :
- Lecture seule : ne remplis aucun formulaire de contact et ne te connecte à aucun compte.
- Le nom du conseiller sert seulement à reconnaître le réseau : ne le note nulle part.
- Si l'agence n'a pas de site, ou si l'annonce n'y figure plus, passe à l'étape 3 (cadastre).

Exemple du 20 octobre 2026 (n° 49, Tilly-sur-Seulles) :
- Etreproprio n'affichait que les lettres DPE et GES, sans chiffres.
- La fiche Safti (safti.fr, liste par commune « /annonces/achat/maison/tilly-sur-seulles-14250 ») donnait 345 kWh, 97 kg CO₂ et une dépense de 5 620 à 7 660 €.
- Un seul DPE du 14250 correspondait : mêmes valeurs, dépense de 6 640 € (le milieu exact de la fourchette), 132,9 m². Confiance forte.

Vérifications du 20 octobre 2026 sur des annonces Ouest-France Immo :
- **Trouver l'agence sur Ouest-France Immo** : son nom est dans le bloc de contact, juste avant « SIREN » (ex. « ORPI MDG CAEN … SIREN: … »). Ne te fie pas à une phrase comme « Nouvelle exclusivité chez UNI Immobilier ! » ailleurs dans la page : elle vient des annonces voisines. La « Référence agence » de la description permet de vérifier que c'est la bonne annonce.
- **Recherche du site de l'agence** : WebSearch « <réseau> <ville de l'agence> », puis la liste des biens de l'agence (Orpi : page « acheter » de l'agence, Laforêt : fiche « …/acheter/<commune>/… », UNI : liste « Maison » de uni-immobilier.fr).
- **Ouest-France recopie bien les chiffres du DPE** (182/27, 250/8, 228/69 : identiques sur le site de l'agence). Ce que le site de l'agence apporte surtout, c'est la fourchette de dépense (la plus discriminante), la vraie commune et l'état de la vente.
- **La fourchette « Estimation des coûts annuels » de la fenêtre « Voir le DPE » d'Ouest-France est un calcul du site** (« prix moyens des énergies indexés… ») : ne l'utilise pas. Seule compte la fourchette écrite par l'agence.
- **Vraie commune** : l'agence peut donner une autre commune que l'agrégateur. La n° 26/80, annoncée « Tourville-sur-Odon » sur Ouest-France, est à Trois-Monts (Montillières-sur-Orne) d'après Orpi : hors zone. Vérifie toujours la zone avec la commune donnée par l'agence, et signale l'écart dans le message de fin.
- **Vendu** : le site de l'agence peut marquer le bien « Vendu » alors que l'agrégateur l'affiche encore (UNI, Tourville-sur-Odon, 2 305 m², même DPE que la n° 60). Signale-le dans le message de fin ; ne retire pas le bien tant que ses liens sont en ligne.

Ne conclus jamais « non trouvée » sur un bien dont l'annonce n'a pas de DPE chiffré sans avoir fait cette étape 1 bis : deux DPE « proches mais non confirmés » sont justement le cas où les chiffres de l'annonce d'origine tranchent.

Agences déjà vues sur les biens sans adresse : Orpi, UNI Immobilier, Laforêt, Safti, iad France, Rue du Particulier, Maisons Anciennes, Just in Home, De Fombelle Immobilier, Normandie Privilège, AXO, Immo Bocage.

## Étape 2 : chercher dans la base DPE
Essaie dans cet ordre, et arrête-toi dès qu'un résultat unique apparaît sur lequel au moins deux valeurs concordent.
0. Date du DPE connue : commune (`code_insee_ban_eq`) et `date_etablissement_dpe` à ±3 jours, `type_batiment_eq=maison`.
1. Commune (`code_insee_ban_eq`) et consommation à ±3 (`_gte`/`_lte`). Garder ensuite les résultats dont les émissions sont à ±2.
2. Sans chiffres : `etiquette_dpe_eq`, `etiquette_ges_eq`, `type_batiment_eq=maison`, surface à ±8 %.
3. Dépense annuelle : `cout_total_5_usages` ≈ (min + max) / 2, à ±2 %. La fourchette affichée vaut à peu près cette dépense ±15 %. Indice très discriminant.
4. Date du DPE de l'annonce : `date_etablissement_dpe` à ±3 jours (la date de visite peut précéder la date d'établissement d'un jour).
5. Plain-pied : `nombre_niveau_logement_eq=1`.
6. **Rien dans la commune** : refais la même recherche sur le code postal (`code_postal_ban_eq`). Les agences donnent souvent la ville voisine (« Villers-Bocage » était à Maisoncelles-Pelvey) ou l'ancienne commune.

Pièges vus :
- **Ne déduis jamais la date du DPE de la date de publication de l'annonce.** Un DPE peut avoir plusieurs années (DPE de janvier 2022 pour une annonce de 2026). Cherche la vraie date sur Bien'ici (« Date de réalisation du DPE ») avant de conclure. Un DPE récent qui colle en surface et en lettres mais pas en date est un faux positif : c'est l'erreur commise sur la première version de cette méthode.
- Quand la date du DPE est connue, la recherche « commune + date ±3 jours » donne presque toujours un résultat unique. C'est la recherche à faire en premier.
- La consommation affichée par l'annonce peut s'écarter de celle de la base (252 annoncés, 242,7 au DPE) alors que la date, les émissions et la dépense concordent exactement. Fie-toi à l'ensemble, pas à une seule valeur.
- La surface du DPE peut différer de celle de l'annonce (dépendance comptée ou non : 183,6 m² au DPE pour 110 m² annoncés). N'écarte pas un candidat sur la seule surface si la consommation, les émissions et la dépense concordent.
- La consommation de l'annonce peut ne correspondre à aucun DPE de la base (ancien DPE, erreur de saisie : 196 annoncés, 173 au DPE). Passe alors au cadastre.
- Un même bâtiment a parfois deux DPE identiques : c'est un doublon, pas deux candidats.
- Garde le lien de la vue filtrée (filteredViewUrl) comme preuve.
- Date du DPE connue mais rien dans le code postal : refais la recherche sur tout le Calvados (`code_departement_ban_eq=14`, date ±3 jours, consommation ±10). Les agences de la Suisse normande ou de Bayeux mettent souvent le nom de la ville du bureau.
- Quelques DPE de 2023 annoncés sur Bien'ici sont absents de la base, même sans filtre de département : dans ce cas, conclure « non trouvée » plutôt que forcer un candidat.
- Résultats du 19 octobre 2026 sur 86 maisons : la recherche « date du DPE » (Bien'ici) a donné une adresse forte presque à chaque fois ; consommation et émissions identiques (Ouest-France) suffisent souvent quand un seul DPE de la commune a la bonne surface.

## Étape 3 : cadastre
Le cadastre Etalab et la BAN répondent depuis un onglet du navigateur intégré (fetch), pas toujours depuis le shell du conteneur.
Exemple qui a marché : un terrain annoncé au m² près correspondait à une seule parcelle de la commune, et le DPE de l'adresse voisine avait les mêmes émissions et la même surface que l'annonce.
Les grands terrains (plus de 2000 m²) sont presque toujours faits de plusieurs parcelles : la recherche d'une parcelle unique échoue, il faut passer par les sommes (point 2).

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
- **Non trouvée** : rien de concluant. Annonce sans DPE chiffré et terrain arrondi : c'est le cas le plus fréquent d'échec. Ne conclus « non trouvée » pour un DPE illisible qu'après avoir cherché l'annonce d'origine (étape 1 bis), et note dans preuves si elle a été trouvée.

Note pour chaque maison : l'adresse, la confiance, une ligne de preuves (valeurs qui concordent), le lien de la vue DPE filtrée.
