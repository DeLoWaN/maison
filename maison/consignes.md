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
