// Relève la galerie de photos de la fiche d'annonce affichée, puis l'envoie au site de la famille
// en ouvrant recevoir.html (les données passent après le #, jamais dans l'adresse envoyée au serveur).
// Usage dans l'onglet de la fiche : window.__E = [{r: "longere", id: "<id>"}]; puis charger ce script.
(function () {
  var E = window.__E || [];
  var og = (document.querySelector('meta[property="og:image"]') || {}).content || "";
  var html = document.documentElement.innerHTML.replace(/&amp;/g, "&").replace(/\\u002F/g, "/").replace(/\\\//g, "/");
  var toks = location.pathname.split(/[\/.]/).filter(function (t) {
    return t.length >= 6 && !/^(annonce|annonces|vente|maison|maisons|immobilier|achat|acheter)$/i.test(t);
  }).sort(function (a, b) { return b.length - a.length; });
  var key = toks.filter(function (t) { return og.indexOf(t) >= 0; })[0] || toks.filter(function (t) { return html.split(t).length > 4; })[0] || "";
  if (!key && og) { var u0 = new URL(og); key = u0.host + u0.pathname.split("/").slice(0, -1).join("/"); }
  var urls = (html.match(/https?:\/\/[^"'\s<>()\\,]+?\.(?:jpe?g|png|webp)(?:\?[^"'\s<>()\\,]*)?/gi) || []).filter(function (u) {
    return key && u.indexOf(key) >= 0 && !/logo|avatar|agence|agent|client\//i.test(u);
  });
  // Plusieurs tailles d'une même photo : on garde une taille d'écran (600 à 1100 px de large), sinon l'originale.
  var w = function (u) { var m = u.match(/(\d{3,4})[-x](\d{3,4})/) || u.match(/width=(\d{3,4})/); return m ? +m[1] : 0; };
  var sc = function (u) { var x = w(u); return x >= 600 && x <= 1100 ? 2000 + x : x === 0 ? 1000 : x; };
  var G = new Map();
  urls.forEach(function (u) {
    var k = u.replace(/\?.*$/, "").replace(/_rcrop_\d+-\d+_/, "").replace(/[-_]\d{2,4}x\d{2,4}/, "");
    var g = G.get(k); if (!g || sc(u) > sc(g)) G.set(k, u);
  });
  var P = Array.from(G.values()).map(function (u) {
    return /file\.bienici\.com/.test(u) ? u.replace(/\?.*$/, "") + "?width=1000&height=750&fit=cover" : u;
  }).slice(0, 25);
  window.__galerie = { key: key, photos: P };
  if (P.length < 2 || !E.length) { document.title = "GALERIE " + P.length + " photo(s), clé " + key; return; }
  location.href = "https://damien-gustave.fr/maison/recevoir.html#" + encodeURIComponent(JSON.stringify({
    envois: E.map(function (e) { return { r: e.r, id: e.id, photos: P }; })
  }));
})();
