# Consignes complémentaires pour la veille

Ce fichier complète la consigne de la tâche planifiée. Lis-le à chaque passage, depuis l'onglet du site : `await (await fetch('consignes.md?x=' + Date.now())).text()`. En cas de contradiction, la consigne de la tâche l'emporte.

## Surface habitable

Chaque bien a un champ `surface` : la surface habitable en m², nombre entier (ex. `"surface": 148`). La page l'affiche sous le prix.
- Prends la surface habitable annoncée par la fiche (souvent dans le titre de la page : « Maison 6 pièces 150 m² », « à vendre / 185m² »). Ne la confonds pas avec le terrain, le séjour, le garage ou les annexes.
- Si les sites d'un même bien donnent des surfaces différentes, garde celle de la fiche la plus détaillée et signale l'écart dans verifier.
- Bien nouveau : renseigne-la. Bien déjà suivi sans surface : renseigne-la à l'étape 3. Ne la change pas ensuite sauf erreur manifeste.

## Galerie de photos de chaque maison

La page affiche toutes les photos d'une annonce, avec des flèches pour passer de l'une à l'autre. Le champ `photos` d'un bien est rempli par le serveur ; ne l'écris jamais toi-même dans data.json (le serveur garde la galerie quand le champ manque).

Quand le faire : à l'étape 3, pour chaque bien actif dont `photos` est absent ou contient moins de 2 adresses, et pour chaque bien ajouté ce jour.

Comment :
1. Ouvre la fiche du bien dans ton onglet, de préférence le lien Bien'ici, sinon Ouest-France Immo, sinon Etreproprio, FNAIM ou iad. Attends que la page soit affichée (environ 4 secondes pour Bien'ici).
2. Avec javascript_tool sur cet onglet, lance exactement (en remplaçant la recherche et l'id) :
   `window.__E = [{r: 'longere', id: 'thue-287'}]; document.head.append(Object.assign(document.createElement('script'), {src: 'https://damien-gustave.fr/maison/galerie.js'}));`
   Si la même maison est suivie dans les deux recherches, mets les deux : `[{r: 'longere', id: '…'}, {r: 'plainpied', id: '…'}]`.
3. Le script relève les photos de la fiche puis ouvre https://damien-gustave.fr/maison/recevoir.html, qui les enregistre. Lis le texte de cette page : « OK longere/thue-287 : 18 photos » veut dire que c'est fait.
4. Si l'onglet reste sur la fiche et que son titre commence par « GALERIE 0 » ou « GALERIE 1 », la page ne donne pas sa galerie (SeLoger, Les Clefs de chez moi) ou bloque le script (Square Habitat) : essaie un autre lien du bien, sinon laisse tomber. Ce n'est pas une erreur à signaler.

Ce relevé lit seulement la page affichée : il ne fait aucun appel à l'API du site d'annonces.

## Leboncoin par les alertes e-mail

Leboncoin bloque la lecture de ses listes de résultats. Damien a créé sur Leboncoin des alertes (recherches enregistrées) qui envoient les nouvelles annonces par courriel. Ces courriels arrivent dans la boîte Gmail reliée à la tâche, sous le libellé « perso-lbc » ; certains sont transférés à la main par Damien. Ils remplacent la lecture des listes Leboncoin, pour les deux recherches.

Quand le faire : à l'étape 4, à la place de toute liste Leboncoin.

1. Avec le connecteur Gmail (search_threads), cherche : `(label:perso-lbc OR (leboncoin subject:"à vendre")) after:AAAA/MM/JJ`, où la date est la veille du dernier_ok de la source « Leboncoin ». Si dernier_ok est vide ou ancien de plus de 30 jours, remplace le filtre de date par `newer_than:30d`. Lis chaque fil en entier avec get_thread au format PLAIN_TEXT : la liste de recherche ne montre pas tout.
2. Chaque annonce du courriel donne :
   - le lien `https://www.leboncoin.fr/vi/<numéro>.htm` : garde-le sans ce qui suit le # ;
   - le prix ;
   - « Maison · N pièces · S m² » ;
   - le terrain ;
   - la commune et son code postal, parfois précédés du quartier ou de l'ancienne commune (« Laize-la-Ville / Laize-Clinchamps 14320 »).

   Le nombre de pièces est parfois faux (« 55 pièces ») : seule la fiche fait foi.
3. Écarte d'emblée, sans ouvrir la fiche, et ajoute à vus avec l'adresse sans le # :
   - une commune hors zone (Troarn par exemple), motif « Hors zone : … » ;
   - une annonce que la règle d'ouverture de l'étape 2 de ta recherche fait écarter (prix, terrain pour « plainpied ») ;
   - une surface habitable sous 80 m², motif « Trop petite : N m² ».

   Une annonce déjà dans vus ou dans les liens d'un bien suivi ne demande rien. Les alertes ne filtrent pas comme nous : la plupart des annonces seront écartées à ce stade, c'est normal.
4. Pour chaque annonce restante :
   - Ouvre la fiche Leboncoin dans le navigateur intégré : une fiche à la fois, 20 à 45 secondes (durée variable) entre deux fiches. N'ouvre jamais de liste de résultats Leboncoin.
   - Fais ensuite les étapes 5 et 6 comme pour les autres sites.
   - Avant de créer un bien, regarde s'il n'est pas déjà suivi sous un autre lien (même commune, surface et prix proches). Si oui, ajoute seulement le lien Leboncoin à ce bien.
5. Si une fiche affiche une page de vérification, un CAPTCHA ou « accès refusé » :
   - N'insiste pas et n'ouvre plus Leboncoin pendant ce passage.
   - N'ajoute pas à vus les annonces qui n'ont pas pu être lues : elles seront reprises au passage suivant, puisque dernier_ok ne change pas.
   - Note la source « partiel ». Tu peux chercher la même maison sur les autres sites (commune, surface, prix).
6. Photos :
   - Prends le og:image de la fiche. Sinon, prends l'adresse de la photo du courriel (https://img.leboncoin.fr/api/v1/…jpg?rule=ad-image), copiée du code HTML : get_thread au format FULL_CONTENT.
   - Ne lance pas galerie.js sur une fiche Leboncoin, car il n'est pas encore éprouvé sur ce site. Si le bien a un autre lien, relève la galerie sur cet autre site.
7. Source « Leboncoin » :
   - Statut « ok » quand toutes les annonces des courriels ont été traitées ; dernier_ok prend alors la date du jour.
   - Note : le nombre de courriels et d'annonces lus, d'annonces écartées d'emblée et de fiches ouvertes.
   - Si aucun courriel n'est arrivé depuis plus de 3 jours ouvrés, statut « partiel » avec la note « aucune alerte reçue depuis le … : vérifier les alertes Leboncoin ou le libellé perso-lbc ». Signale-le dans le message de fin.

Gmail en lecture seule : n'envoie, ne supprime, n'archive et ne marque comme lu aucun courriel, et ne change aucun libellé.
