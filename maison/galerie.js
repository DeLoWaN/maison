// Relève la galerie de photos de la fiche d'annonce affichée, puis l'envoie au site de la famille
// en ouvrant recevoir.html (les données passent après le #, jamais dans l'adresse envoyée au serveur).
// Usage dans l'onglet de la fiche : window.__E = [{r: "longere", id: "<id>"}]; puis charger ce script.
// Sans __E (ou avec moins de 2 photos), rien n'est envoyé : le résultat est dans window.__galerie et le titre de l'onglet.
(function () {
  var E = window.__E || [];
  var og = (document.querySelector('meta[property="og:image"]') || {}).content || "";
  var html = document.documentElement.innerHTML.replace(/&amp;/g, "&").replace(/\\u002F/g, "/").replace(/\\\//g, "/");
  // On s'arrête avant le bloc des annonces voisines, dont les photos ne sont pas celles de la maison.
  var coupe = html.search(/annonces similaires|biens similaires|vous aimerez aussi|autres annonces|dans le m[eê]me secteur|ces biens pourraient/i);
  if (coupe > html.length * 0.3) html = html.slice(0, coupe);
  var urls = (html.match(/https?:\/\/[^"'\s<>()\\,]+?\.(?:jpe?g|png|webp)(?:\?[^"'\s<>()\\,]*)?/gi) || []).filter(function (u) {
    return !/logo|avatar|favicon|icon|sprite|agence|agent|realtor|client\/|picto|banner|placeholder/i.test(u);
  });
  // Plusieurs tailles d'une même photo : on garde une taille d'écran (600 à 1100 px de large), sinon la plus grande.
  var w = function (u) { var m = u.match(/(\d{3,4})[-x](\d{3,4})/) || u.match(/width=(\d{3,4})/); return m ? +m[1] : 0; };
  var sc = function (u) { var x = w(u); return x >= 600 && x <= 1100 ? 2000 + x : x === 0 ? 1000 : x; };
  var grouper = function (liste) {
    var G = new Map();
    liste.forEach(function (u) {
      var k = u.replace(/\?.*$/, "").replace(/_rcrop_\d+-\d+_/, "").replace(/[-_]\d{2,4}x\d{2,4}/, "");
      var g = G.get(k); if (!g || sc(u) > sc(g)) G.set(k, u);
    });
    return Array.from(G.values());
  };
  // 1) Photos dont l'adresse contient l'identifiant de l'annonce (Bien'ici, Ouest-France…).
  var toks = [];
  location.pathname.split(/[\/.]/).forEach(function (t) {
    (t.match(/\d{6,}/g) || []).forEach(function (n) { toks.push(n); });
    if (t.length >= 8 && !/^(annonce|annonces|vente|maison|maisons|immobilier|achat|acheter)$/i.test(t)) toks.push(t);
  });
  var P = [], cle = "";
  for (var i = 0; i < toks.length && P.length < 2; i++) { P = grouper(urls.filter(function (u) { return u.indexOf(toks[i]) >= 0; })); cle = toks[i]; }
  // 2) Sinon, photos rangées au même endroit que la photo principale (même site, même dossier).
  if (P.length < 2) {
    var dossier = function (u) { try { var x = new URL(u); return x.host + x.pathname.split("/").slice(0, 4).join("/"); } catch (e) { return ""; } };
    var ref = og ? dossier(og) : "";
    if (!ref) {
      var n = {}; urls.forEach(function (u) { var d = dossier(u); n[d] = (n[d] || 0) + 1; });
      ref = Object.keys(n).sort(function (a, b) { return n[b] - n[a]; })[0] || "";
    }
    P = ref ? grouper(urls.filter(function (u) { return dossier(u) === ref; })) : [];
    cle = ref;
  }
  P = P.map(function (u) {
    return /file\.bienici\.com/.test(u) ? u.replace(/\?.*$/, "") + "?width=1000&height=750&fit=cover" : u;
  }).slice(0, 25);
  window.__galerie = { cle: cle, photos: P };
  document.title = "GALERIE " + P.length + " photo(s), clé " + cle;
  if (P.length < 2 || !E.length) return;
  location.href = "https://damien-gustave.fr/maison/recevoir.html#" + encodeURIComponent(JSON.stringify({
    envois: E.map(function (e) { return { r: e.r, id: e.id, photos: P }; })
  }));
})();
