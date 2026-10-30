# Outils de la veille

Ces outils remplacent le travail de recopie. Lis ce fichier à l'étape 1, depuis l'onglet du site : `await (await fetch('outils.md?x=' + Date.now())).text()`.

Principe :
- Le modèle juge : il dit si une maison correspond et rédige les critères, le verdict et l'adresse.
- Les scripts et le serveur font le reste : relevés, comparaisons, enregistrement.
- Ne recopie jamais une liste entière : n'envoie que ce qui change.

Tous les appels à `data.php`, `nouveautes.php`, `suivi.php`, `patch.php` et `photo.php` se font avec javascript_tool depuis l'onglet du site (https://damien-gustave.fr/maison/), jamais depuis un onglet d'annonce. Les scripts de lecture se lancent avec javascript_tool sur l'onglet de la page d'annonces affichée : ils lisent la page sans rien charger.

## 0. Les recherches

Le site suit deux recherches : `maison` (au moins une chambre au rez-de-chaussée) et `extensible` (pas de chambre au rez-de-chaussée, mais une extension possible). Une maison va dans une seule des deux.

- Chaque appel à `data.php`, `nouveautes.php`, `suivi.php`, `patch.php` et `api.php` donne la recherche dans l'adresse : `?r=maison` ou `?r=extensible` (avec `&` après un autre paramètre, par exemple `data.php?leger=1&r=extensible`).
- Les scripts Python prennent `--recherche <id>`.
- Sans `r`, le serveur répond pour la première recherche, `maison`.
- Les exemples de ce fichier n'écrivent pas `r` : ajoute-le à chaque appel.

## 1. Lire la liste actuelle (allégée)

`await (await fetch('data.php?leger=1&x=' + Date.now())).json()`

Cette lecture ne contient pas le registre des annonces écartées (seulement `vus_nb`) : c'est le serveur qui fait ce tri. Garde-la en mémoire pour juger, ne la renvoie jamais.

## 2. Lire une page de résultats

Sur la page de résultats affichée, lance ce script en remplaçant `MOTIF` par le motif du site (tableau ci-dessous, à copier avec les guillemets) :

```js
(motif => {
  const re = new RegExp(motif), propre = h => h.split("#")[0], cle = h => propre(h).split("?")[0];
  const res = new Map();
  for (const a of document.querySelectorAll("a[href]")) {
    const u = cle(a.href); if (!re.test(u)) continue;
    let c = a;
    while (c.parentElement && c.parentElement !== document.body) {
      const p = c.parentElement;
      if (p.innerText.length > 5000 || [...p.querySelectorAll("a[href]")].some(x => cle(x.href) !== u && re.test(cle(x.href)))) break;
      c = p;
    }
    const t = (c.innerText || "").replace(/\s+/g, " ").trim();
    if (!res.has(u) || t.length > res.get(u).length) res.set(u, t);
  }
  const prix = t => {
    for (const m of t.matchAll(/(\d{1,3}(?:[ \u00a0\u202f.]\d{3})+|\d{5,7})\s*€/g)) {
      let g = m[1].split(/[ \u00a0\u202f.]/);
      while (g.length > 2 && +g.join("") > 3e6) g = g.slice(1);
      const v = +g.join(""); if (v >= 20000) return v;
    }
    return null;
  };
  const annonces = [...res].map(([url, t]) => ({ url, prix: prix(t),
    m2: [...t.matchAll(/(\d{1,3}(?:[ \u00a0\u202f]\d{3})+(?:[.,]\d+)?|\d+(?:[.,]\d+)?)\s*m(?:²|2)(?![a-z])/g)].map(m => +m[1].replace(/[^\d.,]/g, "").replace(",", ".")).slice(0, 3),
    texte: t.slice(0, 150) }));
  const ici = +(new URL(location.href).searchParams.get("page") || 1);
  const suiv = [...document.querySelectorAll("a[href]")].find(a => a.rel === "next" || /page suivante|suivant/i.test(a.getAttribute("aria-label") || a.title || "") || (a.innerText || "").trim() === String(ici + 1));
  return { n: annonces.length, suivante: suiv ? suiv.href : null, annonces };
})(MOTIF)
```

Le script renvoie, pour chaque annonce de la page, un objet `{url, prix, m2, texte}`, plus l'adresse de la page suivante dans `suivante` (null s'il n'y en a pas). `m2` contient les surfaces lues sur la carte, dans l'ordre (souvent la surface habitable puis le terrain), et `texte` le début de la carte.

| Site | MOTIF |
|---|---|
| Ouest-France Immo | `"/immobilier/vente/[^/]+/[^/]+/\\d+\\.htm"` |
| Bien'ici | `"/annonce/vente/"` |
| Etreproprio | `"/immobilier-\\d+-"` |
| SeLoger | `"seloger\\.com/annonces?/achat"` |
| FNAIM | `"/annonce-immobiliere/\\d+/"` |
| iad France | `"/annonce/[^/]+/r\\d+"` |

SeLoger et Etreproprio ont leur propre script dans consignes.md (pagination par boutons, « Voir plus ») : utilise-le pour ces deux sites.
Bien'ici : la page reste blanche tant que le panneau du navigateur ne l'affiche pas. Après chaque navigation, fais une capture d'écran réduite (scale 0.1), attends 7 secondes, puis lance le script.
Les motifs d'Ouest-France Immo, de Bien'ici et d'Etreproprio ont été testés le 20 octobre 2026. Si le script renvoie `n: 0` alors que la page affiche des annonces, ou des prix tous vides, le site a changé : lis la page comme avant (texte de la page) et signale-le dans la note de la source.

Ensuite, envoie la liste au serveur, qui dit ce qui est nouveau :

```js
const annonces = [/* recopie {url, prix} de chaque annonce */];
await (await fetch('nouveautes.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ annonces }) })).json()
```

Tu peux aussi envoyer `annonces: r.annonces.map(a => ({url: a.url, prix: a.prix}))` si tu as gardé le résultat dans l'onglet du site. Recopie les adresses telles quelles, sans les retaper.

Réponse :
- `nouvelles` : jamais vues (chaque élément donne `url` et `prix`). Ouvre seulement celles qui peuvent correspondre d'après `prix`, `m2` et `texte` de la carte ; pour les autres, ajoute une opération `vu` (section 5) avec le motif.
- `a_rouvrir` : écartées pour leur prix, et leur prix a baissé. Rouvre-les.
- `suivies` : déjà suivies. Le serveur note qu'elles sont toujours en ligne. Si `prix_different` vaut true, ouvre la fiche pour confirmer le nouveau prix.
- `ecartees` : nombre d'annonces déjà examinées et écartées. Il n'y a rien à faire.
- `autre_recherche` : maisons suivies par l'autre recherche, pour information.

Envoie les opérations `vu` des annonces non ouvertes avant de passer à la page suivante (section 5) : c'est ce qui permet au passage suivant de s'arrêter tôt.

### Quand arrêter de tourner les pages

Les listes triées par date (Ouest-France Immo, qui le fait tout seul avec la section 2 bis, Bien'ici et SeLoger avec le tri « Les plus récentes ») mettent les annonces nouvelles en premier. Dès qu'une page ne donne ni `nouvelles` ni `a_rouvrir`, arrête cette recherche et passe à la suivante : les pages d'après ont déjà été lues aux passages précédents. Les annonces mises en avant en tête de liste (« Premium », « À la une ») ne comptent pas.

Lis toutes les pages seulement :
- si le dernier_ok de la source est vide ou date de plus de 7 jours ;
- pour une liste qui n'est pas triée par date (FNAIM, triée par prix ; Etreproprio).

Le 20 octobre, le premier passage outillé a lu les 76 pages d'Ouest-France Immo (1 588 annonces) : c'était un rattrapage, puisque les passages précédents n'en avaient lu que la page 1. Toutes ces annonces sont maintenant connues du serveur.

## 2 bis. Ouest-France Immo : relevé en un seul appel

Pour Ouest-France Immo, n'utilise pas le script de la section 2 page par page. Ce site envoie ses pages toutes faites : un seul script lit les pages de résultats l'une après l'autre (2 à 4 secondes entre deux pages), sans les afficher, et rend une liste courte. Testé le 20 octobre 2026 : 2 pages lues en 4 secondes, 1 900 caractères à recopier au lieu d'environ 15 000.

1. Sur l'onglet du site : `await (await fetch('nouveautes.php?seuil=of&x=' + Date.now())).json()` donne `of_seuil`, le plus grand numéro d'annonce Ouest-France déjà connu. Si le dernier_ok de la source est vide ou date de plus de 7 jours, prends 0 à la place (tout relire).
2. Ouvre dans l'onglet Ouest-France l'adresse de recherche de ta tâche (page 1, tri par date décroissante), attends 3 secondes, puis lance ce script en remplaçant SEUIL par le nombre de l'étape 1 :

```js
(async (seuil, depuis, maxPages) => {
  const base = new URL(location.href); base.searchParams.delete("page");
  const motif = /^\/immobilier\/vente\/([^/]+\/[a-z0-9-]+-14-\d+)\/(\d+)\.htm$/;
  const prixDe = t => {
    for (const m of t.matchAll(/(\d{1,3}(?:[ \u00a0\u202f.]\d{3})+|\d{5,7})\s*€(?!\s*\/)/g)) {
      let g = m[1].split(/[ \u00a0\u202f.]/);
      while (g.length > 2 && +g.join("") > 3e6) g = g.slice(1);
      const v = +g.join(""); if (v >= 20000 && v < 3e6) return v;
    }
    return null;
  };
  const cartes = window.__cartesOF || (window.__cartesOF = {});
  const lignes = []; let page = depuis, lues = 0, fin = null;
  while (lues < maxPages) {
    const u = new URL(base); if (page > 1) u.searchParams.set("page", page);
    const r = await fetch(u, { credentials: "include" });
    if (!r.ok) { fin = "http " + r.status + " page " + page; break; }
    lues++;
    const doc = new DOMParser().parseFromString(await r.text(), "text/html");
    const vus = new Set(); let recents = 0;
    for (const a of doc.querySelectorAll("a[href]")) {
      const h = a.getAttribute("href").split("?")[0], m = h.match(motif);
      if (!m || vus.has(m[2])) continue; vus.add(m[2]);
      let c = a;
      while (c.parentElement && c.parentElement !== doc.body) {
        const p = c.parentElement;
        if (p.textContent.length > 5000 || [...p.querySelectorAll("a[href]")].some(x => { const y = x.getAttribute("href").split("?")[0]; return y !== h && motif.test(y); })) break;
        c = p;
      }
      const t = c.textContent.replace(/\s+/g, " ").trim(), prix = prixDe(t);
      cartes[m[2]] = { url: "https://www.ouestfrance-immo.com" + h, prix, texte: t.slice(0, 300) };
      lignes.push(m[1] + "/" + m[2] + ":" + (prix || ""));
      if (+m[2] > seuil) recents++;
    }
    if (!vus.size) { fin = "page " + page + " vide"; break; }
    if (seuil > 0 && recents === 0) { fin = "page " + page + " : rien de plus récent que le seuil"; break; }
    if (!doc.querySelector('a[href*="page=' + (page + 1) + '"]')) { fin = "dernière page (" + page + ")"; break; }
    page++;
    await new Promise(z => setTimeout(z, 2500 + Math.random() * 2000));
  }
  return { pages_lues: lues, fin, suite: fin ? null : page, n: lignes.length, of: lignes.join(" ") };
})(SEUIL, 1, 8)
```

   Le script s'arrête de lui-même à la première page où aucune annonce n'est plus récente que le seuil, à la dernière page, ou après 8 pages. Dans ce dernier cas, `suite` donne la page suivante : relance le script en remplaçant le `1` final par cette valeur (`…})(SEUIL, suite, 8)`), et envoie chaque résultat au serveur avant de relancer.
3. Sur l'onglet du site, envoie la chaîne `of` telle quelle, sans la retaper ni la découper :

```js
await (await fetch('nouveautes.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ of: "…colle ici la chaîne of…" }) })).json()
```

   Le serveur écarte lui-même, sans opération vu de ta part, les nouvelles annonces hors zone (commune lue dans l'adresse) et celles au-dessus de 385 000 € : il les compte dans `ecartees_auto`. `nouvelles` ne contient donc que des annonces de la zone, dans le budget d'ouverture.
4. Pour lire la carte des `nouvelles` sans rien recharger, sur l'onglet Ouest-France : `["20864921", "20864929"].map(i => i + " " + window.__cartesOF[i].texte)` (le numéro est la fin de l'adresse, avant .htm). Juge sur ce texte : ouvre la fiche (section 3) si la maison peut correspondre ; sinon, opération vu avec le motif (« Programme neuf : maison de constructeur » pour les offres « Devenez propriétaire avec un constructeur », « normes RE 2020 », « terrain + maison »).

Ne recharge pas l'onglet Ouest-France entre les étapes 2 et 4 : `window.__cartesOF` serait perdu (il suffirait alors de relancer le script).

## 3. Lire une fiche d'annonce

Sur la fiche affichée (attends qu'elle soit chargée : environ 4 secondes pour Bien'ici) :

```js
(() => {
  const h1 = (document.querySelector("h1") || {}).innerText || "";
  let t = document.body.innerText;
  const debut = h1 ? t.indexOf(h1.trim().slice(0, 40)) : -1;
  if (debut > 0) t = t.slice(debut);
  const coupe = t.search(/annonces similaires|biens similaires|autres biens|vous aimerez aussi|dans le m[eê]me secteur|ces biens pourraient|autres annonces|annonces à proximité/i);
  if (coupe > 400) t = t.slice(0, coupe);
  const plat = t.replace(/[ \t\u00a0\u202f]+/g, " ");
  const un = re => { const m = plat.match(re); return m ? m[1].trim() : null; };
  const px = plat.match(/(\d{1,3}(?:[ .]\d{3})+|\d{5,7})\s*€/);
  return {
    url: location.href.split("#")[0], titre: h1.trim() || document.title,
    photo: (document.querySelector('meta[property="og:image"]') || {}).content || null,
    prix: px ? +px[1].replace(/\D/g, "") : null,
    kwh: un(/(\d{1,4}(?:[.,]\d)?)\s*\*?\s*kWh/i), co2: un(/(\d{1,4}(?:[.,]\d)?)\s*\*?\s*kg\s?(?:éq\.?\s?|eq)?CO\s?[2₂]/i),
    depense: un(/(entre\s*[\d\s.,]+\s*€?\s*et\s*[\d\s.,]+\s*€)/i),
    date_dpe: un(/(?:date de réalisation(?: du (?:DPE|diagnostic))?|DPE (?:réalisé|établi) le|date du DPE)[^\d\n]{0,25}(\d{2}\/\d{2}\/\d{4}|\d{1,2}(?:er)? [a-zéû]+ \d{4})/i),
    terrain: un(/(\d[\d ]*)\s*m²\s*de terrain/i) || un(/terrain[^\d\n]{0,25}(\d[\d ]*)\s*m²/i),
    chambres: un(/(\d+)\s*chambres?/i), construit: un(/(?:construit|construite|construction|année)\D{0,15}((?:1[6-9]|20)\d{2})/i),
    texte: plat.split("\n").map(l => l.trim()).filter(l => l.length > 1).join("\n").slice(0, 6000)
  };
})()
```

Le script renvoie :
- `titre`, `prix` ;
- `photo` : l'image principale, à utiliser pour photo_url ;
- `kwh`, `co2`, `depense`, `date_dpe` : les valeurs du DPE ;
- `terrain`, `chambres`, `construit` ;
- `texte` : le texte utile de la page (description et caractéristiques), sans les menus ni les annonces voisines, limité à 6 000 caractères.

Juge la maison sur `texte`. Les autres champs sont des repères : en cas de doute (champ vide, valeur étonnante), vérifie dans `texte`.

Garde les valeurs du DPE dans le champ `indices` du bien, telles que le script les renvoie : `{"kwh", "co2", "depense", "date_dpe", "terrain"}`. Mets-les dans l'`ajout`, ou dans une `maj` si une autre fiche du bien en donne de plus complètes. Ajoute `"dpe"` et `"ges"` (lettres) si la fiche les affiche, et `"terrain_exact": false` si le terrain est arrondi. Le script `scripts/adresse.py` cherche l'adresse à partir de ce champ, sans relire l'annonce (section 4).

## 4. Biens suivis : ce qu'il reste à faire

`await (await fetch('suivi.php?x=' + Date.now())).json()`

À appeler APRÈS avoir parcouru les listes de résultats (étape 4 de la tâche), car les annonces vues dans les listes du jour comptent comme vérifiées.
- `a_verifier` : biens actifs qui n'ont été ni vus dans une liste ni vérifiés depuis 3 jours. Ouvre au moins un lien de chacun : s'il est toujours en ligne, opération `maj` avec `"verifie": true` (et le prix s'il a changé) ; si la fiche a disparu sur tous les liens, `maj` avec `"etat": "retiree"`. Si une agence ou un site marque la maison « Vendu » (ou « Vendue »), `maj` avec `"etat": "retiree", "vendue": true`.
- `sans_photo` : biens sans photo_url. Prends le champ `photo` du script de fiche.
- `adresse` : biens à traiter à l'étape 6 bis, déjà dans l'ordre de la consigne (tous, sans limite de nombre). Lance d'abord les deux scripts ci-dessous, dans l'ordre : le premier complète les indices depuis Bien'ici, le second traite seul les biens dont le champ `indices` suffit. Pour les biens qu'il renvoie dans `a_escalader`, suis methode-adresse.md. Quand un de ces biens porte `a_faire`, commence par ce qui y est écrit : cherche l'annonce d'origine (site de l'agence, ou immobilier.notaires.fr pour un notaire) avant toute autre recherche. Si tu ne la trouves pas, ajoute « annonce d'origine introuvable » à adresse.preuves.

### Indices : le script Bien'ici d'abord

Avant le script d'adresse, complète le champ `indices` depuis les annonces Bien'ici. Dans Bash, lance la version de `main`, quelle que soit la branche du clone :
```bash
d=$(mktemp -d) && git -C ~/git_perso/maison fetch -q origin && git -C ~/git_perso/maison archive origin/main scripts | tar -x -C "$d" && python3 -I "$d/scripts/indices_bienici.py" --envoyer
```
Le script lit les biens actifs de `data.php` qui ont un lien bienici.com. Il lit le JSON de chaque annonce (environ 1 seconde par annonce), sans navigateur. Il ajoute au champ `indices` les clés absentes (`kwh`, `co2`, `depense`, `date_dpe`, `terrain`, `dpe`, `ges`) et ne remplace jamais une valeur déjà présente. Il saute les biens qui ont déjà une adresse `forte` ou `probable` et des indices complets.

Il affiche `{"ops": [...], "communes_differentes": [...], "erreurs": [...], "refus": [...]}` :
- `ops` : les `maj` du champ `indices`, déjà envoyées à `patch.php` ;
- `communes_differentes` : biens dont la commune ne correspond pas à celle de Bien'ici (`{id, commune, bienici, url}`). Bien'ici donne parfois la commune voisine ou l'ancienne commune fusionnée. Vérifie la vraie commune dans la description. Si elle est hors zone, signale le bien dans la section « À vérifier » du message de fin ;
- `erreurs` : annonces illisibles, le plus souvent retirées (404) ;
- `refus` : doit être vide.

Sans jeton (401), saute ce script : les fiches lues pendant le passage remplissent `indices` comme à la section 3.

### Adresses : le script d'abord

1. Dans Bash, lance la version de `main`, quelle que soit la branche du clone :
   ```bash
   git -C ~/git_perso/maison fetch -q origin && git -C ~/git_perso/maison show origin/main:scripts/adresse.py | python3 -I - --envoyer
   ```
   Le script lit la liste `adresse` de `suivi.php`, interroge la base DPE, le cadastre et l'API geo (2 à 5 secondes par bien), puis envoie les `maj` du champ `adresse` à `patch.php`. Il s'authentifie avec le jeton de `~/.config/maison/token`.
2. Il affiche `{"ops": [...], "a_escalader": [{id, raison}], "communes_differentes": [{id, commune, trouvee}], "refus": [...]}`. `ops` contient les adresses trouvées, déjà enregistrées, à reprendre dans le message de fin. `refus` doit être vide.
   `communes_differentes` liste les biens dont l'adresse trouvée est dans une autre commune que celle de l'annonce. Si cette commune n'est pas dans la zone, signale le bien dans la section « À vérifier » du message de fin.
   Chaque adresse enregistrée porte `lat` et `lon` : le site ouvre Google Maps sur ces coordonnées. Pour une adresse que tu as écrite à la main, ajoute-les avec `python3 -I scripts/coordonnees.py --envoyer` (il complète toutes les adresses sans point).
3. Traite chaque bien de `a_escalader` avec methode-adresse.md. La `raison` dit ce qui manque, par exemple « pas de DPE chiffré ni de terrain exact dans les indices ». Si tu trouves des chiffres sur une autre fiche, enregistre-les dans `indices` et relance la même commande avec `--ids <id> --envoyer`.

Si le script répond 401 (jeton absent ou révoqué), prépare son entrée depuis l'onglet du site, puis lance-le avec `--entree <fichier>`, sans `--envoyer`, et envoie toi-même `ops` à `patch.php` (section 5) :
```js
const s = await (await fetch('suivi.php?x=' + Date.now())).json();
const d = await (await fetch('data.php?leger=1&x=' + Date.now())).json();
const ids = new Set(s.adresse.map(x => x.id));
JSON.stringify({ biens: d.biens.filter(b => ids.has(b.id)).map(b => ({ id: b.id, commune: b.commune, surface: b.surface, indices: b.indices, adresse: b.adresse })) })
```

## 5. Enregistrer : les opérations

Au lieu de renvoyer la liste complète, envoie la liste des changements :

```js
const ops = [ /* opérations ci-dessous */ ];
await (await fetch('patch.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ ops }) })).json()
```

Opérations possibles :
- **Nouvelle maison** : `{"op":"ajout", "bien":{"id":"tilly-310", "titre":…, "commune":…, "lieu":…, "prix":310000, "surface":148, "verdict":"top|presque|hors", "crit":{"zone":["ok","…"], …}, "verifier":["…"], "liens":[{"site":"Bien'ici","url":"https://…","ok":true}], "photo_url":"https://…"}}`
  - Le serveur ajoute lui-même etat, repere_le, verifie_le, prix_hist et le numéro (renvoyé dans `nums`).
  - Les annonces de ses liens sont retirées des écartées.
  - Ajoute `"adresse":{…}` si elle est déjà trouvée.
- **Bien suivi modifié** : `{"op":"maj", "id":"tilly-310", "champs":{"prix":299000}, "verifie":true}`
  - Champs modifiables : titre, commune, lieu, prix, verdict, crit, verifier, photo_url, surface, adresse, etat, retiree_le, liens, vendue.
  - Un prix différent est ajouté à l'historique automatiquement. `"etat":"retiree"` date le retrait au jour même. `"vendue": true` (seulement avec `"etat":"retiree"`) affiche « Vendue le … » au lieu de « Retirée le … ».
  - `"verifie": true` met verifie_le à aujourd'hui.
- **Lien ajouté ou corrigé** : `{"op":"lien", "id":"tilly-310", "site":"Safti (agence)", "url":"https://…", "ok":true}`
- **Doublon** (la même maison suivie deux fois) : `{"op":"fusionner", "id":"<bien gardé>", "avec":"<doublon>"}`. Le bien gardé reçoit les liens, l'historique des prix, la photo et l'adresse la plus sûre ; le numéro du doublon mène ensuite au bien gardé.
- **Règles contrôlées par le serveur** : un ajout ou une mise à jour d'un bien actif est refusé si sa commune n'est pas une commune de la zone ou de la bordure (accents et majuscules ignorés), si le prix dépasse le plafond d'ouverture (385 000 €), si le critère rdc vaut « ko », si un verdict « top » a un critère « ko », si un verdict « hors » n'en a aucun, ou s'il y a deux critères « ko » ou plus. Le message de refus dit quoi faire.
- **Annonce écartée** : `{"op":"vu", "url":"https://…", "motif":"Construite en 1977", "prix":285000}`
  - Pour en retirer une : `{"op":"oublier_vu", "url":"https://…"}`.
- **Source** : `{"op":"source", "nom":"Bien'ici", "statut":"ok|partiel|echec|nt", "note":"…", "dernier_ok":"2026-10-20"}`
  - Avec le statut "ok" et sans dernier_ok, le serveur met la date du jour.
  - Pour garder l'ancienne date (lecture incomplète), n'envoie pas dernier_ok.
  - Statut autre que "ok" : la note doit donner la vraie cause, ce qui a été essayé et ce qui a échoué. Le serveur refuse « manque de temps », « faute de temps » (la tâche n'a aucune limite de temps) et « pas tenté » sans raison.
- **Ordre des sources** : lis les sources de l'étape 4 dans l'ordre du champ `ordre_sources` de la liste allégée (section 1) : les moins récemment lues en entier passent en premier, pour qu'une source sautée un jour soit lue le lendemain.
- **Fin du passage** (une fois, à la fin) : `{"op":"fin", "note_passage":"…"}`
  - Le serveur refuse fin tant qu'une des sources de la tâche n'a pas reçu son opération source pendant ce passage ; le refus donne leur nom. Lis-les, ou envoie leur opération source avec la vraie cause, puis renvoie fin.
  - maj passe à la date du jour, et les annonces écartées de plus de 90 jours sont retirées.

Envoie par paquets de 40 opérations au plus, au fil du passage (ne garde pas tout pour la fin).

### Envoi d'un fichier par la session principale

Un sous-agent écrit ses opérations dans un fichier JSON. La session principale les envoie avec `scripts/envoyer.py`, sans les recopier dans javascript_tool. Le script lit le jeton de `~/.config/maison/token`, envoie par paquets de 40 et rend `nums` et les refus :
```bash
d=$(mktemp -d) && git -C ~/git_perso/maison fetch -q origin && git -C ~/git_perso/maison archive origin/main scripts | tar -x -C "$d" && python3 -I "$d/scripts/envoyer.py" ops <fichier.json>
```
- `ops <fichier.json>` : tableau d'opérations → patch.php.
- `nouveautes <dossier>` : `<dossier>/annonces_nouveautes.json` → nouveautes.php ; la réponse va dans `<dossier>/reponse_nouveautes.json`.

Termine toujours par un envoi à part, qui ne contient que les opérations `source` (une par site, y compris ceux en échec) et l'opération `fin`, et vérifie que la réponse dit `"ok": true`.

Si patch.php répond une erreur (code 500, « écriture impossible »), rien n'a été enregistré. Réessaie une seule fois, une minute plus tard. Si l'erreur revient, arrête le passage : continuer ne servirait à rien, puisque rien ne serait gardé. Termine alors par le message d'échec, avec le texte de l'erreur (« envoi au site échoué : … »).

 Lis `resultats` : chaque opération refusée porte une `erreur` en clair (id déjà utilisé, champ invalide…). Corrige seulement celle-là et renvoie-la. Le serveur garde une copie datée de chaque version.

## 6. Contrôle des photos (après les envois)

```js
const d = await (await fetch('data.php?leger=1&x=' + Date.now())).json();
const actifs = d.biens.filter(b => b.etat === 'active'), ko = [];
for (let i = 0; i < actifs.length; i += 6) await Promise.all(actifs.slice(i, i + 6).map(async b => {
  const x = await fetch('photo.php?id=' + encodeURIComponent(b.id)).catch(() => null);
  if (!x || !x.ok || !(x.headers.get('content-type') || '').startsWith('image/')) {
    const g = await fetch('photo.php?id=' + encodeURIComponent(b.id) + '&diag=1').then(y => y.json()).catch(() => ({}));
    ko.push({ id: b.id, num: b.num, diag: g });
  }
}));
({ actifs: actifs.length, ko })
```

Seuls les biens en échec sont renvoyés. Pour chacun, trouve une autre photo sur ses liens et envoie une opération `maj` avec `photo_url`. Un code 403 sur ouestfrance-immo.com est acceptable si le bien n'a aucun autre site.
