# Outils de la veille

Ces outils remplacent le travail de recopie. Lis ce fichier à l'étape 1, depuis l'onglet du site : `await (await fetch('outils.md?x=' + Date.now())).text()`.

Principe :
- Le modèle juge : il dit si une maison correspond et rédige les critères, le verdict et l'adresse.
- Les scripts et le serveur font le reste : relevés, comparaisons, enregistrement.
- Ne recopie jamais une liste entière : n'envoie que ce qui change.

Tous les appels à `data.php`, `nouveautes.php`, `suivi.php`, `patch.php` et `photo.php` se font avec javascript_tool depuis l'onglet du site (https://damien-gustave.fr/maison/), jamais depuis un onglet d'annonce. Les scripts de lecture se lancent avec javascript_tool sur l'onglet de la page d'annonces affichée : ils lisent la page sans rien charger.

## 1. Lire la liste actuelle (allégée)

`await (await fetch('data.php?r=RECHERCHE&leger=1&x=' + Date.now())).json()`

Cette lecture ne contient ni les galeries de photos (seulement `nb_photos`) ni le registre des annonces écartées (seulement `vus_nb`) : c'est le serveur qui fait ce tri. Garde-la en mémoire pour juger, ne la renvoie jamais.

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

Les trois premiers motifs ont été testés le 20 octobre 2026. Si le script renvoie `n: 0` alors que la page affiche des annonces, ou des prix tous vides, le site a changé : lis la page comme avant (texte de la page) et signale-le dans la note de la source.

Ensuite, envoie la liste au serveur, qui dit ce qui est nouveau :

```js
const annonces = [/* recopie {url, prix} de chaque annonce */];
await (await fetch('nouveautes.php?r=RECHERCHE', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ annonces }) })).json()
```

Tu peux aussi envoyer `annonces: r.annonces.map(a => ({url: a.url, prix: a.prix}))` si tu as gardé le résultat dans l'onglet du site. Recopie les adresses telles quelles, sans les retaper.

Réponse :
- `nouvelles` : jamais vues. Ouvre seulement celles qui peuvent correspondre d'après `prix`, `m2` et `texte` de la carte ; pour les autres, ajoute une opération `vu` (section 5) avec le motif.
- `a_rouvrir` : écartées pour leur prix, et leur prix a baissé. Rouvre-les.
- `suivies` : déjà suivies. Le serveur note qu'elles sont toujours en ligne. Si `prix_different` vaut true, ouvre la fiche pour confirmer le nouveau prix.
- `ecartees` : nombre d'annonces déjà examinées et écartées. Il n'y a rien à faire.
- `autre_recherche` : maisons suivies par l'autre recherche, pour information.

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
    depense: un(/(entre\s*[\d\s.,]+\s*€\s*et\s*[\d\s.,]+\s*€)/i),
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

## 4. Biens suivis : ce qu'il reste à faire

`await (await fetch('suivi.php?r=RECHERCHE&x=' + Date.now())).json()`

À appeler APRÈS avoir parcouru les listes de résultats (étape 4 de la tâche), car les annonces vues dans les listes du jour comptent comme vérifiées.
- `a_verifier` : biens actifs qui n'ont été ni vus dans une liste ni vérifiés depuis 3 jours. Ouvre au moins un lien de chacun : s'il est toujours en ligne, opération `maj` avec `"verifie": true` (et le prix s'il a changé) ; si la fiche a disparu sur tous les liens, `maj` avec `"etat": "retiree"`.
- `galerie` : biens sans galerie de photos (voir « Galerie de photos » dans consignes.md).
- `sans_photo` : biens sans photo_url. Prends le champ `photo` du script de fiche.
- `adresse` : biens à traiter à l'étape 6 bis, déjà dans l'ordre de la consigne (au plus 8).

## 5. Enregistrer : les opérations

Au lieu de renvoyer la liste complète, envoie la liste des changements :

```js
const ops = [ /* opérations ci-dessous */ ];
await (await fetch('patch.php?r=RECHERCHE', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ ops }) })).json()
```

Opérations possibles :
- **Nouvelle maison** : `{"op":"ajout", "bien":{"id":"tilly-310", "titre":…, "commune":…, "lieu":…, "prix":310000, "surface":148, "verdict":"top|presque|hors", "crit":{"zone":["ok","…"], …}, "verifier":["…"], "liens":[{"site":"Bien'ici","url":"https://…","ok":true}], "photo_url":"https://…"}}`
  - Le serveur ajoute lui-même etat, repere_le, verifie_le, prix_hist et le numéro (renvoyé dans `nums`).
  - Les annonces de ses liens sont retirées des écartées.
  - Ajoute `"adresse":{…}` si elle est déjà trouvée.
- **Bien suivi modifié** : `{"op":"maj", "id":"tilly-310", "champs":{"prix":299000}, "verifie":true}`
  - Champs modifiables : titre, commune, lieu, prix, verdict, crit, verifier, photo_url, surface, adresse, etat, retiree_le, liens.
  - Un prix différent est ajouté à l'historique automatiquement. `"etat":"retiree"` date le retrait au jour même.
  - `"verifie": true` met verifie_le à aujourd'hui.
- **Lien ajouté ou corrigé** : `{"op":"lien", "id":"tilly-310", "site":"Safti (agence)", "url":"https://…", "ok":true}`
- **Annonce écartée** : `{"op":"vu", "url":"https://…", "motif":"Construite en 1977", "prix":285000}`
  - Pour en retirer une : `{"op":"oublier_vu", "url":"https://…"}`.
- **Source** : `{"op":"source", "nom":"Bien'ici", "statut":"ok|partiel|echec|nt", "note":"…", "dernier_ok":"2026-10-20"}`
  - Avec le statut "ok" et sans dernier_ok, le serveur met la date du jour.
  - Pour garder l'ancienne date (lecture incomplète), n'envoie pas dernier_ok.
- **Fin du passage** (une fois, à la fin) : `{"op":"fin", "note_passage":"…"}`
  - maj passe à la date du jour, et les annonces écartées de plus de 90 jours sont retirées.

Envoie par paquets de 40 opérations au plus. Lis `resultats` : chaque opération refusée porte une `erreur` en clair (id déjà utilisé, champ invalide…). Corrige seulement celle-là et renvoie-la. Le serveur garde une copie datée de chaque version.

## 6. Contrôle des photos (après les envois)

```js
const r = 'RECHERCHE';
const d = await (await fetch('data.php?r=' + r + '&leger=1&x=' + Date.now())).json();
const actifs = d.biens.filter(b => b.etat === 'active'), ko = [];
for (let i = 0; i < actifs.length; i += 6) await Promise.all(actifs.slice(i, i + 6).map(async b => {
  const x = await fetch('photo.php?r=' + r + '&id=' + encodeURIComponent(b.id)).catch(() => null);
  if (!x || !x.ok || !(x.headers.get('content-type') || '').startsWith('image/')) {
    const g = await fetch('photo.php?r=' + r + '&id=' + encodeURIComponent(b.id) + '&diag=1').then(y => y.json()).catch(() => ({}));
    ko.push({ id: b.id, num: b.num, diag: g });
  }
}));
({ actifs: actifs.length, ko })
```

Seuls les biens en échec sont renvoyés. Pour chacun, trouve une autre photo sur ses liens et envoie une opération `maj` avec `photo_url`. Un code 403 sur ouestfrance-immo.com est acceptable si le bien n'a aucun autre site.
