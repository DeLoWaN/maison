# Consignes complémentaires pour la veille

Ce fichier complète la consigne de la tâche planifiée. Lis-le à chaque passage, depuis l'onglet du site : `await (await fetch('consignes.md?x=' + Date.now())).text()`. En cas de contradiction, la consigne de la tâche l'emporte.

## Surface habitable

Chaque bien a un champ `surface` : la surface habitable en m², nombre entier (ex. `"surface": 148`). La page l'affiche sous le prix.
- Prends la surface habitable annoncée par la fiche (souvent dans le titre de la page : « Maison 6 pièces 150 m² », « à vendre / 185m² »). Ne la confonds pas avec le terrain, le séjour, le garage ou les annexes.
- Si les sites d'un même bien donnent des surfaces différentes, garde celle de la fiche la plus détaillée et signale l'écart dans verifier.
- Bien nouveau : renseigne-la. Bien déjà suivi sans surface : renseigne-la à l'étape 3. Ne la change pas ensuite sauf erreur manifeste.

## Galerie de photos de chaque maison

La page affiche toutes les photos d'une annonce, avec des flèches pour passer de l'une à l'autre. Le champ `photos` d'un bien est rempli par le serveur. Ne l'écris jamais toi-même dans data.json : le serveur garde la galerie quand le champ manque.

Quand le faire : à l'étape 3, pour chaque bien actif dont `photos` est absent ou contient moins de 2 adresses, et pour chaque bien ajouté ce jour.

N'injecte aucun script extérieur dans une page d'annonce et ne fais pas changer de page à la fiche : le contrôle de sécurité le refuse (« Code from External », « Browser JS Exfil »). L'ancienne méthode (galerie.js, recevoir.html) est supprimée. La méthode ci-dessous lit la fiche puis envoie le résultat depuis l'onglet du site, comme pour data.json.

1. Ouvre la fiche du bien dans ton onglet d'annonces. Prends de préférence le lien Bien'ici, sinon Ouest-France Immo, puis Etreproprio, FNAIM ou iad. Attends que la page soit affichée (environ 4 secondes pour Bien'ici).
2. Avec javascript_tool sur l'onglet de la fiche, lance exactement ce script de lecture. Il ne charge rien et ne change pas de page :

```js
(() => {
  const og = document.querySelector('meta[property="og:image"]')?.content || "";
  let html = document.documentElement.innerHTML.replace(/&amp;/g, "&").replace(/\\u002F/g, "/").replace(/\\\//g, "/");
  const coupe = html.search(/annonces similaires|biens similaires|autres biens|autres annonces|vous aimerez aussi|dans le m[eê]me secteur|ces biens pourraient/i);
  if (coupe > html.length * 0.3) html = html.slice(0, coupe);
  const urls = (html.match(/https?:\/\/[^"'\s<>()\\,]+/gi) || []).filter(u => /\.(?:jpe?g|png|webp)(?:[_?#]|$)/i.test(u) && !/logo|avatar|favicon|icon|sprite|agence|agent|realtor|client\/|picto|banner|placeholder/i.test(u));
  const w = u => { const m = u.match(/(\d{3,4})[-x](\d{3,4})/) || u.match(/width=(\d{3,4})/); return m ? +m[1] : 0; };
  const sc = u => { const x = w(u); return x >= 600 && x <= 1100 ? 2000 + x : x === 0 ? 1000 : x; };
  const grouper = l => { const G = new Map(); l.forEach(u => { const k = u.replace(/\?.*$/, "").replace(/_rcrop_\d+-\d+_/, "").replace(/[-_]\d{2,4}x\d{2,4}/, ""); const g = G.get(k); if (!g || sc(u) > sc(g)) G.set(k, u); }); return [...G.values()]; };
  const toks = [];
  location.pathname.split(/[\/.]/).forEach(t => { (t.match(/\d{6,}/g) || []).forEach(n => toks.push(n)); if (t.length >= 8 && !/^(annonce|annonces|vente|maison|maisons|immobilier|achat|acheter)$/i.test(t)) toks.push(t); });
  let P = [];
  for (const t of toks) { if (P.length >= 2) break; P = grouper(urls.filter(u => u.includes(t))); }
  if (P.length < 2) {
    const dossier = u => { try { const x = new URL(u); return x.host + x.pathname.split("/").slice(0, -1).slice(0, 4).join("/"); } catch (e) { return ""; } };
    let ref = og ? dossier(og) : "";
    if (!ref) { const n = {}; urls.forEach(u => { const d = dossier(u); n[d] = (n[d] || 0) + 1; }); ref = Object.keys(n).sort((a, b) => n[b] - n[a])[0] || ""; }
    P = ref ? grouper(urls.filter(u => dossier(u) === ref)) : [];
  }
  P = P.map(u => /file\.bienici\.com/.test(u) ? u.replace(/\?.*$/, "") + "?width=1000&height=750&fit=cover" : u).slice(0, 25);
  let p = P[0] || "", s = P[0] || "";
  for (const u of P) { while (!u.startsWith(p)) p = p.slice(0, -1); while (!u.endsWith(s)) s = s.slice(1); }
  const m = Math.min(...P.map(u => u.length));
  if (p.length + s.length > m) s = s.slice(p.length + s.length - m);
  const t = JSON.stringify(P); let h = 0; for (const c of t) h = (h * 31 + c.codePointAt(0)) >>> 0;
  return { n: P.length, L: t.length, h, p, s, f: P.map(u => u.slice(p.length, u.length - s.length)) };
})()
```

   Le résultat est compact : `p` est le début commun des adresses, `s` la fin commune, `f` la partie qui change pour chaque photo, `L` et `h` la longueur et l'empreinte de la liste complète. Si `n` vaut moins de 2, la page ne donne pas sa galerie : essaie un autre lien du bien, sinon laisse tomber. Ce n'est pas une erreur à signaler. C'est le cas de SeLoger et des Clefs de chez moi, et Square Habitat bloque les scripts.
3. Sur l'onglet https://damien-gustave.fr/maison/ , recopie exactement `p`, `s`, `f`, `L` et `h` dans ce script, avec la bonne recherche et le bon id, puis lance-le :

```js
const g = { r: "longere", id: "<id>", L: <L>, h: <h>, p: "<p>", s: "<s>", f: [ /* recopie f */ ] };
const photos = g.f.map(x => g.p + x + g.s);
const t = JSON.stringify(photos); let h = 0; for (const c of t) h = (h * 31 + c.codePointAt(0)) >>> 0;
let out;
if (t.length !== g.L || h !== g.h) out = { erreur: "empreinte différente : rien envoyé", L: t.length, h };
else { const r = await fetch('photos.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ r: g.r, id: g.id, photos }) }); out = { s: r.status, t: await r.text() }; }
out
```

   « empreinte différente » : une partie a été mal recopiée et rien n'a été envoyé. Recopie de nouveau à partir du résultat de l'étape 2. La réponse attendue est `{"ok":true, ... "photos": N}` avec N égal à `n`. Si la même maison est suivie dans les deux recherches, relance le script avec l'autre recherche et l'autre id.
4. Un bien à la fois : relis une fiche, envoie sa galerie, puis passe au bien suivant.

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
   - Ne relève pas la galerie sur une fiche Leboncoin, car la méthode n'est pas encore éprouvée sur ce site. Si le bien a un autre lien, relève la galerie sur cet autre site.
7. Source « Leboncoin » :
   - Statut « ok » quand toutes les annonces des courriels ont été traitées ; dernier_ok prend alors la date du jour.
   - Note : le nombre de courriels et d'annonces lus, d'annonces écartées d'emblée et de fiches ouvertes.
   - Si aucun courriel n'est arrivé depuis plus de 3 jours ouvrés, statut « partiel » avec la note « aucune alerte reçue depuis le … : vérifier les alertes Leboncoin ou le libellé perso-lbc ». Signale-le dans le message de fin.

Gmail en lecture seule : n'envoie, ne supprime, n'archive et ne marque comme lu aucun courriel, et ne change aucun libellé.
