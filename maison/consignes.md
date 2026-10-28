# Consignes complémentaires pour la veille

Ce fichier complète la consigne de la tâche planifiée. Lis-le à chaque passage, depuis l'onglet du site : `await (await fetch('consignes.md?x=' + Date.now())).text()`. En cas de contradiction, la consigne de la tâche l'emporte.

## Travailler dans l'onglet du site

Pour les deux recherches, il n'y a plus de fichier data.json local ni de renvoi de la liste complète.
- Lecture : liste allégée (`data.php?leger=1`), gardée pour juger.
- Tri des annonces lues : `nouveautes.php`.
- Biens à revérifier : `suivi.php`.
- Enregistrement : opérations envoyées à `patch.php`, au fil du passage.

Tout est décrit dans outils.md. L'onglet du site ne sert qu'au site : les annonces s'ouvrent dans un autre onglet. Envoie une première fois dès la première source terminée, puis au fil du passage : un passage interrompu ne perd ainsi presque rien.

Galeries en attente (bien nouveau dont la galerie est relevée avant son ajout) : envoie-les à `photos.php` juste après l'opération ajout.

## Lire les sites d'annonces

### Bien'ici
- Une page de Bien'ici reste blanche tant que le panneau du navigateur ne l'affiche pas. Après chaque `navigate`, fais une capture d'écran réduite (`computer` screenshot, scale 0.1), attends 7 secondes, puis lis la page. Le titre de l'onglet (« Achat maison 5 pièces 117 m², … - 300 000 € ») suffit pour vérifier un prix.
- La recherche a changé ses communes toute seule une fois (Castine-en-Plaine et Le Castelet retirées, Saint-Martin-de-Fontenay ajoutée) : filtre toujours toi-même sur la zone.
- Si le champ « Surface du terrain » n'apparaît pas dans « Afficher tous les critères », ne le force pas : fais la seconde lecture (sans le critère plain-pied) triée par « Les plus récentes », jusqu'à la date dernier_ok.

Pour SeLoger et Etreproprio, utilise les scripts ci-dessous plutôt que le script de liste générique d'outils.md. Envoie ensuite les adresses obtenues à `nouveautes.php` comme pour les autres sites.

### SeLoger
- Le site n'a pas de filtre plain-pied. La recherche se fait en texte libre depuis la page d'accueil (« Nouvelle recherche ») : « maison à acheter à <commune> <code postal>, … jusqu'à 385000 euros ». Mets 5 communes au plus par recherche, chacune avec son code postal (sans lui, « Vendes » donne Montaigu-Vendée). Le titre de la page dit combien de communes ont été retenues (« Bayeux (+5) ») : relance avec celles qui manquent.
- Lis chaque page de résultats avec ce script, une page par appel (au-delà, l'appel dépasse le délai) : `window.__go(1)`, puis `window.__go(2)`, etc. Il clique sur le numéro de page, fait défiler la liste et garde les cartes dans `window.__all`.

```js
window.__all = window.__all || [];
window.__go = async (pg) => {
  const b = [...document.querySelectorAll('nav li button')].find(e => e.textContent.trim() === String(pg));
  if (b && pg > 1) { b.click(); await new Promise(r => setTimeout(r, 4000)); }
  window.scrollTo(0, 0); for (let y = 0; y < 12; y++) { window.scrollBy(0, 1200); await new Promise(r => setTimeout(r, 200)); }
  const s = [...document.querySelectorAll('[data-testid="serp-core-classified-card-testid"]')].map(c => {
    const a = c.querySelector('a'); const t = c.innerText.replace(/\s+/g, ' ');
    const p = +((t.match(/([\d  ]{5,}) €/) || [])[1] || '0').replace(/\D/g, '');
    const ter = +((t.match(/([\d  ]+) m² de terrain/) || [])[1] || '0').replace(/\D/g, '');
    return { pg, u: a ? a.href.split('?')[0] : '', p, ter, t: t.slice(0, 140) };
  });
  s.forEach(x => window.__all.push(x));
  return { pg, n: s.length, pages: [...document.querySelectorAll('nav li button')].map(e => e.textContent.trim()).filter(x => /^\d+$/.test(x)).join(',') };
};
```

  Puis garde les cartes utiles : `window.__all.filter(x => /calvados-14|-14\//.test(x.u) && !/seloger-construire|selogerneuf/.test(x.u) && x.p <= 385000 && (x.ter === 0 || x.ter >= 900))`, sans doublon d'adresse.
- Sur une fiche, attends 3 secondes ; la description commence après « Calculer un temps de trajet ». La photo est le og:image (mms.seloger.com). SeLoger ne donne pas de galerie.

### Etreproprio
- Une page de commune (`immobilier-vente-<commune>-v14XXX/maison`, liens sur https://www.etreproprio.com/maison-a-vendre/14) affiche 60 annonces au plus, même après « Voir plus ». Lis donc la recherche de la commune. La page de commune n'a pas de lien vers elle : ouvre `https://www.etreproprio.com/annonces/th.lc<code INSEE>-r0.odd` (tri « Date : plus récentes »), puis passe d'une page à l'autre avec le bouton « Page suivante ». Elle donne 20 annonces par page, triées par date : arrête-toi à la première page sans nouvelle.
- La lecture directe répond 403 (Cloudflare) : passe par le navigateur.
- Lis chaque page avec ce script : il garde le lien complet de chaque fiche (ne reconstruis jamais un lien à partir du seul numéro : la page répond « introuvable »).

```js
for (let k = 0; k < 10; k++) { const b = [...document.querySelectorAll('a,button')].find(e => /^\s*Voir plus/i.test(e.textContent) && e.offsetParent); if (!b) break; b.click(); await new Promise(r => setTimeout(r, 2000)); }
const vu = new Set(), out = [];
document.querySelectorAll('a[href*="/immobilier-"][href*="vente-maison"]').forEach(a => {
  const m = a.href.match(/immobilier-(\d+)-/); if (!m || vu.has(m[1])) return; vu.add(m[1]);
  let c = a; for (let i = 0; i < 8 && c.parentElement && !/€/.test(c.innerText); i++) c = c.parentElement;
  const t = c.innerText.replace(/\s+/g, ' ');
  const p = +((t.match(/([\d ]{5,}) €/) || [])[1] || '0').replace(/\D/g, '');
  if (p > 385000 || /neuve? à construire|VEFA/i.test(t)) return;
  out.push(a.href + ' | ' + p + ' | ' + (/plain|RDC|rez-de-chauss/i.test(t) ? 'PP ' : '') + t.slice(0, 110));
});
({ titre: document.title, annonces: vu.size, out })
```

- Sur une fiche, `document.body.innerText` à partir de « Surface » donne la surface, le terrain exact et la description. Etreproprio n'affiche que les lettres du DPE.
- Les prix d'Etreproprio sont parfois périmés (329 000 € affichés pour 299 000 € chez l'agence, 326 500 € pour 305 500 €). Pour un bien nouveau trouvé sur Etreproprio, vérifie toujours le prix sur le site de l'agence (« Proposé par … ») et retiens celui-là. Signale l'écart dans verifier.

### Safti
- Le site de Safti donne le DPE chiffré, la fourchette de dépense et l'état de la vente. La liste d'une commune est https://www.safti.fr/annonces/achat/maison/<commune>-<code postal> (ex. `mouen-14790`) ; elle inclut les communes voisines. Repère la fiche par la surface, le terrain et le prix.
- Une annonce Safti absente de cette liste alors qu'elle est encore sur un agrégateur est peut-être vendue : signale-le dans verifier.

## Vérifier qu'une annonce est toujours en vente

Sur Ouest-France Immo, ne cherche pas le mot « vendu » dans la page : il apparaît dans les avis clients de l'agence. Une annonce retirée affiche en haut de page « Ce bien n'est plus disponible sur ouestfrance-immo.com ». Regarde seulement les 600 premiers caractères du texte, et le prix de la ligne « Prix : ».

## Doublons à prix différents

Une même maison peut être proposée à des prix différents selon l'agence ou le site. Traite-la comme un seul bien quand la commune, la surface (à 3 % près), le terrain (à 2 % près) et la description concordent : ajoute le lien au bien existant, garde son prix, et écris l'écart dans verifier (« Une autre agence la propose à … : quel est le bon prix ? »). Si un seul de ces points diffère, crée un bien séparé.

## Surface habitable

Chaque bien a un champ `surface` : la surface habitable en m², nombre entier (ex. `"surface": 148`). La page l'affiche sous le prix.
- Prends la surface habitable annoncée par la fiche (souvent dans le titre de la page : « Maison 6 pièces 150 m² », « à vendre / 185m² »). Ne la confonds pas avec le terrain, le séjour, le garage ou les annexes.
- Si les sites d'un même bien donnent des surfaces différentes, garde celle de la fiche la plus détaillée et signale l'écart dans verifier.
- Bien nouveau : renseigne-la. Bien déjà suivi sans surface : renseigne-la à l'étape 3. Ne la change pas ensuite sauf erreur manifeste.

## Galerie de photos de chaque maison

La page affiche toutes les photos d'une annonce, avec des flèches pour passer de l'une à l'autre. Le champ `photos` d'un bien est rempli par le serveur. Ne l'envoie jamais toi-même dans une opération : il s'enregistre uniquement avec photos.php.

Quand le faire : à l'étape 3, pour chaque bien de la liste `galerie` de suivi.php, et pour chaque bien ajouté ce jour avec le verdict top ou presque. Pas de galerie pour un bien « hors ».

N'injecte aucun script extérieur dans une page d'annonce et ne fais pas changer de page à la fiche : le contrôle de sécurité le refuse (« Code from External », « Browser JS Exfil »). L'ancienne méthode (galerie.js, recevoir.html) est supprimée. La méthode ci-dessous lit la fiche puis envoie le résultat depuis l'onglet du site.

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
3. Sur l'onglet https://damien-gustave.fr/maison/ , recopie exactement `p`, `s`, `f`, `L` et `h` dans ce script, avec le bon id, puis lance-le :

```js
const g = { id: "<id>", L: <L>, h: <h>, p: "<p>", s: "<s>", f: [ /* recopie f */ ] };
const photos = g.f.map(x => g.p + x + g.s);
const t = JSON.stringify(photos); let h = 0; for (const c of t) h = (h * 31 + c.codePointAt(0)) >>> 0;
let out;
if (t.length !== g.L || h !== g.h) out = { erreur: "empreinte différente : rien envoyé", L: t.length, h };
else { const r = await fetch('photos.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id: g.id, photos }) }); out = { s: r.status, t: await r.text() }; }
out
```

   « empreinte différente » : une partie a été mal recopiée et rien n'a été envoyé. Recopie de nouveau à partir du résultat de l'étape 2. La réponse attendue est `{"ok":true, ... "photos": N}` avec N égal à `n`. Si la même maison est suivie dans les deux recherches, relance le script avec l'autre recherche et l'autre id.
4. Un bien à la fois : relis une fiche, envoie sa galerie, puis passe au bien suivant.

Ce relevé lit seulement la page affichée : il ne fait aucun appel à l'API du site d'annonces.

## Photo principale venant d'Ouest-France Immo

Depuis le 20 octobre 2026, Ouest-France Immo répond 404 (et non plus 403) quand le site télécharge ses photos. La page affiche quand même la photo depuis le navigateur du visiteur. Au contrôle des photos (étape 7) :
- http 403 ou 404 sur ouestfrance-immo.com : acceptable si le bien n'a aucun autre site ;
- si le bien a un autre lien, prends le og:image de cet autre site (Immonot, SeLoger, Safti, Bien'ici, Etreproprio, Laforêt, Optimhome…) et refais le contrôle.

Ne signale pas ces biens comme « restés sans photo ».

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
   - une annonce que la règle d'ouverture de l'étape 2 de ta recherche fait écarter (prix, terrain) ;
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

## Adresses : DPE illisible sur l'agrégateur

La méthode d'adresse (methode-adresse.md, étape 1 bis) demande de remonter à l'annonce d'origine, sur le site de l'agence, quand le DPE est illisible ou ne correspond à aucun DPE de la base.

Pour les biens déjà marqués « non trouvée » : à l'étape 3, prends au plus 4 biens actifs par passage qui remplissent toutes ces conditions :
- adresse.confiance vaut "non_trouvee" ;
- adresse.essais est inférieur à 3 ;
- aucun lien du bien ne se termine par « (agence) ».
- adresse.preuves ne contient pas « annonce d'origine introuvable ».

Pour chacun, cherche l'annonce d'origine comme indiqué. Si tu la trouves, ajoute-la aux liens du bien. C'est un nouveau lien : traite alors le bien à l'étape 6 bis du même passage. Si tu ne la trouves pas, ajoute à adresse.preuves « annonce d'origine introuvable », pour ne pas refaire cette recherche.
