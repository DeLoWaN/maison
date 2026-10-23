"""Finds the postal address of tracked houses from their listing clues (methode-adresse.md, steps 2, 3 and 5).

The clues are the values that the listing publishes about the energy diagnosis (DPE): primary energy use
in kWh/m²/year, CO2 emissions in kg/m²/year, the yearly cost range, the date of the diagnosis, plus the
land area. The script looks for the DPE that matches them in the open ADEME database, checks the land
area against the Etalab cadastre, and scores the result like the method: "forte", "probable" or no result.

Clues come from the `indices` field of each house:
    {"kwh": "229", "co2": "7", "depense": "entre 1 960 € et 2 710 €", "date_dpe": "30/04/2023",
     "terrain": "6 862", "terrain_exact": true, "dpe": "D", "ges": "B"}
Every key is optional. The strings are the raw output of the listing script of outils.md, section 3.

Usage:
    python3 scripts/adresse.py                     # houses of the `adresse` list of suivi.php, nothing sent
    python3 scripts/adresse.py --envoyer           # same, and sends the operations to patch.php
    python3 scripts/adresse.py --ids a,b           # only these houses
    python3 scripts/adresse.py --entree biens.json # reads {"biens": [...]} from a file instead of the site

Reading and writing the site needs the script token in ~/.config/maison/token (see auth-lib.php).

Output (stdout, JSON): {"ops": [maj operations for patch.php], "a_escalader": [{id, raison}], "refus"?: [...]}.
Houses in a_escalader need step 1 bis of the method (listing on the agency site), done by a model.
Exit status 1 when patch.php refuses an operation.
"""
import argparse
import gzip
import json
import math
import pathlib
import re
import sys
import tempfile
import time
import unicodedata
import urllib.error
import urllib.parse
import urllib.request
from datetime import date, timedelta

ADEME = "https://data.ademe.fr/data-fair/api/v1/datasets/dpe03existant/lines"
ADEME_VUE = "https://data.ademe.fr/datasets/dpe03existant/full?adresse_ban_eq="
GEO = "https://geo.api.gouv.fr"
CADASTRE = "https://cadastre.data.gouv.fr/bundler/cadastre-etalab/communes/{insee}/geojson/parcelles"
CACHE = pathlib.Path(tempfile.gettempdir()) / "maison-cadastre"
SITE = "https://damien-gustave.fr/maison/"
JETON = pathlib.Path.home() / ".config" / "maison" / "token"
# patch.php accepts at most 40 operations per call.
TAILLE_PAQUET = 40
ESSAIS_RESEAU = 3
DEPARTEMENT = "14"
ZONE_DEPARTEMENT = "le département"
ZONE_CODE_POSTAL = "le code postal"
ZONE_PARCELLE = "la parcelle exacte"
AUTRES_COMMUNES = (ZONE_CODE_POSTAL, ZONE_DEPARTEMENT)
CHAMPS_DPE = ",".join((
    "numero_dpe", "adresse_ban", "code_insee_ban", "code_postal_ban", "surface_habitable_logement",
    "conso_5_usages_par_m2_ep", "emission_ges_5_usages_par_m2", "etiquette_dpe", "etiquette_ges",
    "cout_total_5_usages", "date_etablissement_dpe", "periode_construction", "nombre_niveau_logement", "_geopoint",
))

# Tolerances of methode-adresse.md, step 2.
JOURS_DATE = 3
ECART_KWH = 3
ECART_CO2 = 2
ECART_DEPENSE = 0.02
ECART_SURFACE = 0.08
# Beyond this gap, the listing consumption contradicts the DPE (252 announced for 242.7 is still a match).
ECART_KWH_CONTRADICTION = 0.20
ECART_SURFACE_CONTRADICTION = 0.30
# Step 3: the land area must equal one parcel within 3 m². Sums of two to four nearby parcels
# matched wrong houses in other communes on four test houses: a housing estate has many small parcels.
ECART_PARCELLE_M2 = 3
RAYON_ANCRE_M = 60
RAYON_DPE_PARCELLE_M = 80
# Each confirmation loads the cadastre of one commune (a few MB): check the closest DPE only.
MAX_CONFIRMATIONS = 5

MOIS = {m: i + 1 for i, m in enumerate((
    "janvier", "fevrier", "mars", "avril", "mai", "juin", "juillet", "aout", "septembre", "octobre", "novembre", "decembre"))}


class ErreurSource(Exception):
    """An open data service did not answer or answered something unreadable."""


# ---------------------------------------------------------------- clues

def sans_accents(s):
    return "".join(c for c in unicodedata.normalize("NFD", s) if unicodedata.category(c) != "Mn")


def nombre(v):
    """Returns the number in '205,9', '6*', '1 403 m²' or 229, or None."""
    if v is None or isinstance(v, bool):
        return None
    if isinstance(v, (int, float)):
        return float(v)
    m = re.search(r"\d[\d\s  ]*(?:[.,]\d+)?", str(v))
    return float(re.sub(r"[\s  ]", "", m.group(0)).replace(",", ".")) if m else None


def fourchette(v):
    """Returns (min, max) from 'entre 1 960 € et 2 710 €', or None."""
    if not v:
        return None
    m = re.search(r"([\d\s  .]+)\s*€?\s*et\s*([\d\s  .]+)\s*€", str(v), re.I)
    if not m:
        return None
    # "2400.00 €" is 2400, not 240000.
    bas, haut = (float(re.sub(r"\D", "", re.sub(r"[.,]\d{1,2}\s*$", "", x.strip()))) for x in m.groups())
    return (bas, haut) if 0 < bas <= haut else None


def date_fr(v):
    """Returns the date in '18/01/2022', '2022-01-18' or '1er mars 2021', or None."""
    if not v:
        return None
    s = sans_accents(str(v)).lower()
    m = re.search(r"(\d{4})-(\d{2})-(\d{2})", s)
    if m:
        return date(int(m.group(1)), int(m.group(2)), int(m.group(3)))
    m = re.search(r"(\d{1,2})/(\d{1,2})/(\d{4})", s)
    if m:
        return date(int(m.group(3)), int(m.group(2)), int(m.group(1)))
    m = re.search(r"(\d{1,2})(?:er)? ([a-z]+) (\d{4})", s)
    if m and m.group(2) in MOIS:
        return date(int(m.group(3)), MOIS[m.group(2)], int(m.group(1)))
    return None


def lire_indices(bien):
    """Normalises the clues of a house. Returns a dict with only the usable values."""
    brut = bien.get("indices") or {}
    ind = {
        "kwh": nombre(brut.get("kwh")), "co2": nombre(brut.get("co2")),
        "depense": fourchette(brut.get("depense")), "date": date_fr(brut.get("date_dpe")),
        "terrain": nombre(brut.get("terrain")), "surface": nombre(bien.get("surface")),
        "dpe": (brut.get("dpe") or "").strip().upper()[:1] or None,
        "ges": (brut.get("ges") or "").strip().upper()[:1] or None,
    }
    # "1 000 m²", "environ 1 400 m²" and "1 ha" are rounded: only a value off the hundred counts as exact.
    exact = brut.get("terrain_exact")
    if exact is None:
        exact = ind["terrain"] is not None and ind["terrain"] % 100 != 0 and not re.search(r"environ|ha\b", str(brut.get("terrain")), re.I)
    ind["terrain_exact"] = bool(exact) and ind["terrain"] is not None
    return {k: v for k, v in ind.items() if v is not None}


# ---------------------------------------------------------------- open data services

class Sources:
    """HTTP access to the open data services. Tests replace it with a fake that has the same methods."""

    def _json(self, url, params=None):
        if params:
            url += "?" + urllib.parse.urlencode(params)
        for essai in range(ESSAIS_RESEAU):
            try:
                with urllib.request.urlopen(urllib.request.Request(url, headers={"Accept-Encoding": "gzip"}), timeout=90) as r:
                    data = r.read()
                break
            except OSError as e:
                # The public APIs sometimes reset a connection; a 4xx answer will not change on retry.
                definitif = isinstance(e, urllib.error.HTTPError) and e.code < 500
                if definitif or essai == ESSAIS_RESEAU - 1:
                    raise ErreurSource(f"{url}: {e}") from e
                time.sleep(2 * (essai + 1))
        if data[:2] == b"\x1f\x8b":
            data = gzip.decompress(data)
        try:
            return json.loads(data)
        except ValueError as e:
            raise ErreurSource(f"{url}: invalid JSON") from e

    def dpe(self, filtres):
        """Returns up to 500 DPE of houses that match the Data Fair filters."""
        params = {"size": 500, "select": CHAMPS_DPE, "type_batiment_eq": "maison", **filtres}
        return self._json(ADEME, params).get("results", [])

    def commune(self, nom):
        """Returns {code, codesPostaux} of a commune of the department, or of the new commune of a former one."""
        res = self._json(f"{GEO}/communes", {"nom": nom, "codeDepartement": DEPARTEMENT, "fields": "nom,code,codesPostaux"})
        cle = sans_accents(nom).lower().replace("-", " ")
        for c in res:
            if sans_accents(c["nom"]).lower().replace("-", " ") == cle:
                return c
        for c in self._json(f"{GEO}/communes_associees_deleguees", {"nom": nom, "fields": "nom,chefLieu"}):
            if c.get("chefLieu", "").startswith(DEPARTEMENT):
                return self._json(f"{GEO}/communes/{c['chefLieu']}", {"fields": "nom,code,codesPostaux"})
        return res[0] if res else None

    def communes_du_code_postal(self, cp):
        return [c["code"] for c in self._json(f"{GEO}/communes", {"codePostal": cp, "fields": "code"})]

    def parcelles(self, insee):
        """Returns the parcels of a commune as [(id, area m², polygon ring [(lon, lat)])]. Cached on disk."""
        CACHE.mkdir(exist_ok=True)
        fichier = CACHE / f"{insee}.json"
        if fichier.exists():
            return [tuple(p) for p in json.loads(fichier.read_text())]
        out = lire_parcelles(self._json(CADASTRE.format(insee=insee)))
        fichier.write_text(json.dumps(out))
        return out


def lire_parcelles(geojson):
    """Returns [(id, area m², outer ring)] from an Etalab parcel file. Parcels without an area are left out."""
    out = []
    for f in geojson["features"]:
        g, contenance = f.get("geometry"), f["properties"].get("contenance")
        if not g or not contenance:
            continue
        anneau = g["coordinates"][0] if g["type"] == "Polygon" else max((p[0] for p in g["coordinates"]), key=len)
        out.append((f["properties"]["id"], contenance, anneau))
    return out


# ---------------------------------------------------------------- geometry

def distance_m(a, b):
    """Distance in metres between two (lon, lat) points; precise enough at the scale of a commune."""
    dx = (a[0] - b[0]) * 111320 * math.cos(math.radians((a[1] + b[1]) / 2))
    dy = (a[1] - b[1]) * 110540
    return math.hypot(dx, dy)


def centre(anneau):
    return (sum(p[0] for p in anneau) / len(anneau), sum(p[1] for p in anneau) / len(anneau))


def contient(anneau, pt):
    dedans = False
    for (x1, y1), (x2, y2) in zip(anneau, anneau[1:] + anneau[:1]):
        if (y1 > pt[1]) != (y2 > pt[1]) and pt[0] < x1 + (pt[1] - y1) * (x2 - x1) / (y2 - y1):
            dedans = not dedans
    return dedans


def point_dpe(d):
    lat, lon = (float(x) for x in d["_geopoint"].split(","))
    return (lon, lat)


# ---------------------------------------------------------------- search

class Recherche:
    def __init__(self, sources):
        self.s = sources

    def zones(self, commune):
        """Returns the search scopes, from narrow to wide: the commune, its postal codes, the department."""
        c = self.s.commune(commune) if commune else None
        if not c:
            return [(ZONE_DEPARTEMENT, {"code_departement_ban_eq": DEPARTEMENT})]
        codes = sorted({x for cp in c.get("codesPostaux", []) for x in self.s.communes_du_code_postal(cp)} - {c["code"]})
        zones = [(c["nom"], {"code_insee_ban_eq": c["code"]})]
        if codes:
            zones.append((ZONE_CODE_POSTAL, {"code_insee_ban_in": ",".join(codes)}))
        zones.append((ZONE_DEPARTEMENT, {"code_departement_ban_eq": DEPARTEMENT}))
        return zones

    @staticmethod
    def requetes(ind, large):
        """Returns the DPE filters of step 2 that the clues allow. `large` is the department scope."""
        q = []
        if "date" in ind:
            d = ind["date"]
            f = {"date_etablissement_dpe_gte": (d - timedelta(days=JOURS_DATE)).isoformat(),
                 "date_etablissement_dpe_lte": (d + timedelta(days=JOURS_DATE)).isoformat()}
            if large and "kwh" in ind:
                f.update(conso_5_usages_par_m2_ep_gte=ind["kwh"] - 10, conso_5_usages_par_m2_ep_lte=ind["kwh"] + 10)
            q.append(f)
        if "kwh" in ind:
            f = {"conso_5_usages_par_m2_ep_gte": ind["kwh"] - ECART_KWH, "conso_5_usages_par_m2_ep_lte": ind["kwh"] + ECART_KWH}
            if large and "co2" in ind:
                f.update(emission_ges_5_usages_par_m2_gte=ind["co2"] - ECART_CO2, emission_ges_5_usages_par_m2_lte=ind["co2"] + ECART_CO2)
            q.append(f)
        if "depense" in ind:
            milieu = sum(ind["depense"]) / 2
            f = {"cout_total_5_usages_gte": round(milieu * (1 - ECART_DEPENSE), 1), "cout_total_5_usages_lte": round(milieu * (1 + ECART_DEPENSE), 1)}
            if large and "co2" in ind:
                f.update(emission_ges_5_usages_par_m2_gte=ind["co2"] - ECART_CO2, emission_ges_5_usages_par_m2_lte=ind["co2"] + ECART_CO2)
            q.append(f)
        if not q and not large and "dpe" in ind and "ges" in ind and "surface" in ind:
            s = ind["surface"]
            q.append({"etiquette_dpe_eq": ind["dpe"], "etiquette_ges_eq": ind["ges"],
                      "surface_habitable_logement_gte": s * (1 - ECART_SURFACE), "surface_habitable_logement_lte": s * (1 + ECART_SURFACE)})
        # Without this filter, a department query passes the 500-result limit and loses the right DPE.
        if large and "surface" in ind:
            s = ind["surface"]
            q = [{**f, "surface_habitable_logement_gte": round(s * (1 - ECART_SURFACE_CONTRADICTION), 1),
                  "surface_habitable_logement_lte": round(s * (1 + ECART_SURFACE_CONTRADICTION), 1)} for f in q]
        return q

    def candidats(self, ind, commune):
        """Runs step 2 from the narrowest scope.

        Stops at the first scope where a DPE is solid. Otherwise returns the DPE with a clue from every
        scope, so that step 3 can add the land area as a second clue.
        """
        toutes = []
        for nom, zone in self.zones(commune):
            large = "code_departement_ban_eq" in zone
            vus = {}
            for f in self.requetes(ind, large):
                for d in self.s.dpe({**zone, **f}):
                    vus.setdefault(d["numero_dpe"], d)
            notes = [n for n in (noter(d, ind, nom) for d in dedoublonner(vus.values())) if n["indices"]]
            # In the whole department, values or the date alone matched houses 30 and 60 km away.
            # Keep only a DPE with both, and drop any contradiction: it means another house.
            if large:
                notes = [n for n in notes if not n["contradictions"]
                         and "date" in (g := {k for k, _ in n["indices"]}) and g & {"valeurs", "depense"}]
            if any(solide(n) for n in notes):
                return notes
            toutes += [n for n in notes if n["dpe"]["numero_dpe"] not in {t["dpe"]["numero_dpe"] for t in toutes}]
        return toutes

    def confirmer_terrain(self, cand, ind):
        """Step 3, point 2: a parcel at the DPE address whose area equals the exact land area, or None."""
        pt = point_dpe(cand["dpe"])
        # The address point often sits on the road, a few tens of metres from the house parcel.
        for p in self.s.parcelles(cand["dpe"]["code_insee_ban"]):
            if (contient(p[2], pt) or distance_m(centre(p[2]), pt) <= RAYON_ANCRE_M) and abs(p[1] - ind["terrain"]) <= ECART_PARCELLE_M2:
                return p
        return None

    def parcelle_exacte(self, ind, commune):
        """Step 3, point 1: parcels whose area equals the exact land area, in the commune and its postal codes."""
        c = self.s.commune(commune) if commune else None
        if not c:
            return []
        codes = {c["code"]} | {x for cp in c.get("codesPostaux", []) for x in self.s.communes_du_code_postal(cp)}
        trouvees = []
        for insee in sorted(codes):
            trouvees += [p for p in self.s.parcelles(insee) if abs(p[1] - ind["terrain"]) <= ECART_PARCELLE_M2]
        return trouvees

    def dpe_pres_de(self, parcelle):
        lon, lat = centre(parcelle[2])
        return self.s.dpe({"geo_distance": f"{lon},{lat},{RAYON_DPE_PARCELLE_M}m"})

    def chercher(self, bien):
        """Returns ("forte" | "probable", address fields) or (None, reason to escalate)."""
        ind = lire_indices(bien)
        if not ({"kwh", "co2", "depense", "date"} & ind.keys() or ind.get("terrain_exact") or {"dpe", "ges"} <= ind.keys()):
            return None, "pas de DPE chiffré ni de terrain exact dans les indices"
        notes = self.candidats(ind, bien.get("commune"))
        if ind.get("terrain_exact") and not any(solide(n) for n in notes):
            for n in sorted(notes, key=lambda n: (bool(n["contradictions"]), -poids(n), ecart_surface(n["dpe"], ind)))[:MAX_CONFIRMATIONS]:
                t = self.confirmer_terrain(n, ind)
                if t:
                    n["indices"].append(("terrain", f"parcelle {t[0]} de {t[1]:g} m² à l'adresse"))
            if not any(solide(n) for n in notes):
                exactes = self.parcelle_exacte(ind, bien.get("commune"))
                if len(exactes) == 1:
                    notes = []
                    for d in dedoublonner(self.dpe_pres_de(exactes[0])):
                        n = noter(d, ind, ZONE_PARCELLE)
                        n["indices"].append(("terrain", f"parcelle {exactes[0][0]} de {exactes[0][1]:g} m², seule de cette surface"))
                        notes.append(n)
        return conclure(notes, ind)


def poids(note):
    """Number of independent clues: two values of the same kind count once.

    The yearly cost follows from the consumption. In one commune, consumption and cost that both match
    are still a strong sign. In the whole department, they often match a wrong house: they count once.
    """
    genres = {k for k, _ in note["indices"]}
    if note["zone"] == ZONE_DEPARTEMENT and {"valeurs", "depense"} <= genres:
        genres.discard("depense")
    return len(genres)


def solide(note):
    """Two independent clues and no contradiction: enough to stop the search."""
    return poids(note) >= 2 and not note["contradictions"]


def ecart_surface(d, ind):
    s = d.get("surface_habitable_logement")
    return abs(s - ind["surface"]) if s and "surface" in ind else math.inf


def dedoublonner(dpes):
    """The same building sometimes has two identical DPE: keep one."""
    vus = {}
    for d in dpes:
        cle = (d.get("adresse_ban"), d.get("conso_5_usages_par_m2_ep"), d.get("emission_ges_5_usages_par_m2"), d.get("surface_habitable_logement"))
        vus.setdefault(cle, d)
    return list(vus.values())


def noter(d, ind, zone):
    """Lists the independent clues that a DPE matches, and the contradictions. `zone` names the scope."""
    indices, contradictions = [], []
    s = d.get("surface_habitable_logement")
    # A DPE can count an outbuilding (183.6 m² for 110 m² announced): a large gap only prevents "forte".
    if s and "surface" in ind and abs(s - ind["surface"]) > ind["surface"] * ECART_SURFACE_CONTRADICTION:
        contradictions.append(f"{s:g} m² au DPE contre {ind['surface']:g} m² dans l'annonce")
    conso, co2 = d.get("conso_5_usages_par_m2_ep"), d.get("emission_ges_5_usages_par_m2")
    if "date" in ind and d.get("date_etablissement_dpe"):
        if abs((date_fr(d["date_etablissement_dpe"]) - ind["date"]).days) <= JOURS_DATE:
            indices.append(("date", f"DPE du {d['date_etablissement_dpe']}"))
        else:
            # methode-adresse.md: a DPE that matches everything but the date is a false positive.
            contradictions.append(f"DPE du {d['date_etablissement_dpe']} contre le {ind['date'].isoformat()} dans l'annonce")
    if "kwh" in ind and conso is not None:
        co2_ok = "co2" not in ind or (co2 is not None and abs(co2 - ind["co2"]) <= ECART_CO2)
        if abs(conso - ind["kwh"]) <= ECART_KWH and co2_ok:
            indices.append(("valeurs", f"{conso:g} kWh" + (f", {co2:g} kg CO2" if co2 is not None else "")))
        elif abs(conso - ind["kwh"]) > ind["kwh"] * ECART_KWH_CONTRADICTION:
            contradictions.append(f"{conso:g} kWh au DPE contre {ind['kwh']:g} dans l'annonce")
    elif "co2" in ind and co2 is not None and abs(co2 - ind["co2"]) > ECART_CO2 * 2:
        contradictions.append(f"{co2:g} kg CO2 au DPE contre {ind['co2']:g} dans l'annonce")
    if "depense" in ind and d.get("cout_total_5_usages") is not None:
        milieu = sum(ind["depense"]) / 2
        if abs(d["cout_total_5_usages"] - milieu) <= milieu * ECART_DEPENSE:
            indices.append(("depense", f"dépense {d['cout_total_5_usages']:g} € pour une fourchette de {ind['depense'][0]:g} à {ind['depense'][1]:g} €"))
    for cle, champ in (("dpe", "etiquette_dpe"), ("ges", "etiquette_ges")):
        if cle in ind and d.get(champ) and d[champ] != ind[cle]:
            contradictions.append(f"étiquette {d[champ]} au DPE contre {ind[cle]} dans l'annonce")
    if not indices and {"dpe", "ges", "surface"} <= ind.keys() and not contradictions:
        if s and abs(s - ind["surface"]) <= ind["surface"] * ECART_SURFACE:
            indices.append(("lettres", f"{d['etiquette_dpe']}/{d['etiquette_ges']}, {s:g} m²"))
    return {"dpe": d, "indices": indices, "contradictions": contradictions, "zone": zone}


def conclure(notes, ind):
    """Step 5. Strong: two independent clues, no contradiction, and no other DPE as good. Probable: one solid clue."""
    if not notes:
        return None, "aucun DPE concordant (commune, code postal, département)" + ("" if ind.get("terrain_exact") else ", terrain arrondi")
    notes.sort(key=lambda n: (bool(n["contradictions"]), -poids(n)))
    meilleur = notes[0]
    p = poids(meilleur)
    egaux = [n for n in notes[1:] if poids(n) == p and not n["contradictions"]]
    # Letters and surface alone match many houses: they are never enough.
    if solide(meilleur) and not egaux:
        confiance = "forte"
    # One clue against a contradiction, or two contradictions, point to another dwelling:
    # tests found a DPE of the same day with three gaps, and a 53 m² flat for a 117 m² house.
    # Another commune than the announced one is already a weakness: one clue is not enough there.
    elif len(meilleur["contradictions"]) <= 1 and not egaux and "lettres" not in {k for k, _ in meilleur["indices"]} \
            and p >= (2 if meilleur["contradictions"] or meilleur["zone"] in AUTRES_COMMUNES else 1):
        confiance = "probable"
    elif egaux:
        return None, f"{len(egaux) + 1} DPE aussi plausibles, aucun ne se détache"
    elif meilleur["contradictions"]:
        return None, f"meilleur DPE ({meilleur['dpe']['numero_dpe']}) contredit l'annonce : " + " ; ".join(meilleur["contradictions"])
    else:
        return None, f"un seul indice, pour un DPE ({meilleur['dpe']['numero_dpe']}) dans une autre commune que celle de l'annonce"
    d = meilleur["dpe"]
    morceaux = [t for _, t in meilleur["indices"]]
    morceaux.append(f"DPE {d['numero_dpe']}, {d.get('surface_habitable_logement', '?')} m², {d.get('etiquette_dpe', '?')}/{d.get('etiquette_ges', '?')}, trouvé dans {meilleur['zone']}")
    if meilleur["contradictions"]:
        morceaux.append("écarts : " + " ; ".join(meilleur["contradictions"]))
    lon, lat = point_dpe(d)
    return confiance, {
        "texte": d["adresse_ban"], "lat": lat, "lon": lon, "confiance": confiance,
        "preuves": " ; ".join(morceaux) + " (recherche automatique, scripts/adresse.py)",
        "lien": ADEME_VUE + urllib.parse.quote(d["adresse_ban"]),
    }


# ---------------------------------------------------------------- command line

def traiter(biens, recherche, aujourdhui):
    ops, escalade = [], []
    for b in biens:
        essais = int((b.get("adresse") or {}).get("essais") or 0) + 1
        try:
            confiance, res = recherche.chercher(b)
        except ErreurSource as e:
            escalade.append({"id": b["id"], "raison": f"service indisponible : {e}"})
            continue
        if confiance:
            res.update(le=aujourdhui, essais=essais)
            ops.append({"op": "maj", "id": b["id"], "champs": {"adresse": res}})
        else:
            escalade.append({"id": b["id"], "raison": res})
    return {"ops": ops, "a_escalader": escalade}


class Site:
    """Reads and writes the tracking site with the script token (header X-Maison-Jeton, see auth-lib.php)."""

    def __init__(self, jeton):
        self.jeton = jeton

    def _appel(self, page, corps=None):
        req = urllib.request.Request(SITE + page, data=None if corps is None else json.dumps(corps).encode(),
                                     headers={"X-Maison-Jeton": self.jeton, "Content-Type": "application/json"})
        try:
            with urllib.request.urlopen(req, timeout=60) as r:
                return json.loads(r.read())
        except OSError as e:
            raise ErreurSource(f"{SITE}{page}: {e}") from e

    def biens_a_traiter(self):
        """Returns the houses of the `adresse` list of suivi.php, in its order."""
        ordre = [x["id"] for x in self._appel("suivi.php")["adresse"]]
        biens = {b["id"]: b for b in self._appel("data.php?leger=1")["biens"]}
        return [biens[i] for i in ordre if i in biens]

    def biens_actifs(self):
        """Returns the houses of data.php whose state is `active`."""
        return [b for b in self._appel("data.php?leger=1")["biens"] if b.get("etat", "active") == "active"]

    def envoyer(self, ops):
        """Sends the operations to patch.php in packets. Returns the refusals, with their reason."""
        refus = []
        for i in range(0, len(ops), TAILLE_PAQUET):
            res = self._appel("patch.php", {"ops": ops[i:i + TAILLE_PAQUET]})
            refus += [r for r in res.get("resultats", []) if not r.get("ok", True)]
        return refus


def main(argv=None):
    p = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    p.add_argument("--entree", help='JSON file {"biens": [...]}; by default, the `adresse` list of the site')
    p.add_argument("--ids", help="comma-separated house ids to process (default: all)")
    p.add_argument("--envoyer", action="store_true", help="send the operations to patch.php")
    a = p.parse_args(argv)
    site = None
    if not a.entree or a.envoyer:
        if not JETON.exists():
            p.error(f"{JETON} is missing: the site cannot be read or written without the script token")
        site = Site(JETON.read_text().strip())
    try:
        biens = json.loads(pathlib.Path(a.entree).read_text())["biens"] if a.entree else site.biens_a_traiter()
        if a.ids:
            voulus = set(a.ids.split(","))
            biens = [b for b in biens if b["id"] in voulus]
        out = traiter(biens, Recherche(Sources()), date.today().isoformat())
        if a.envoyer:
            out["refus"] = site.envoyer(out["ops"])
    except ErreurSource as e:
        p.exit(2, f"adresse.py: {e}\n")
    json.dump(out, sys.stdout, ensure_ascii=False, indent=1)
    print()
    return 1 if out.get("refus") else 0


if __name__ == "__main__":
    sys.exit(main())
