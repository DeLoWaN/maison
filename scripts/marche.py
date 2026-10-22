"""Builds maison/recherches/maison/marche.json: house sale prices per m², from the open DVF files.

DVF (Demandes de valeurs foncières, géolocalisé, Etalab) lists every property sale. For each Calvados
commune with house sales, the output gives the quartiles of the price per m² of the houses sold within
a few kilometres, by band of living area and of land area. The page compares each house with the band
that matches it. No individual sale is published: each band holds at least MIN_VENTES sales.

Usage: python3 scripts/marche.py   (downloads about 4 MB; uses the 3 most recent years published)
"""
import collections
import csv
import gzip
import io
import json
import math
import pathlib
import statistics
import urllib.error
import urllib.request
from datetime import date

# The three most recent years that DVF has published.
NB_ANNEES = 3
URL = "https://files.data.gouv.fr/geo-dvf/latest/csv/{annee}/departements/14.csv.gz"
SORTIE = pathlib.Path(__file__).resolve().parent.parent / "maison" / "recherches" / "maison" / "marche.json"
SURFACES = (0, 80, 110, 140, 180, 250, 100000)
GRAND_TERRAIN = 1000
RAYONS_KM = (6, 10, 15)
MIN_VENTES = 8
PRIX_M2_MIN, PRIX_M2_MAX = 500, 8000


def annees_publiees():
    annees = []
    for annee in range(date.today().year, date.today().year - 8, -1):
        try:
            with urllib.request.urlopen(urllib.request.Request(URL.format(annee=annee), method="HEAD"), timeout=30):
                annees.append(annee)
        except urllib.error.HTTPError:
            continue
        if len(annees) == NB_ANNEES:
            break
    return sorted(annees)


def lire_ventes(annees):
    lignes = collections.defaultdict(list)
    for annee in annees:
        with urllib.request.urlopen(URL.format(annee=annee), timeout=120) as r:
            texte = gzip.decompress(r.read()).decode("utf-8")
        for ligne in csv.DictReader(io.StringIO(texte)):
            lignes[ligne["id_mutation"]].append(ligne)
    ventes = []
    for rows in lignes.values():
        tete = rows[0]
        if tete["nature_mutation"] != "Vente" or not tete["valeur_fonciere"]:
            continue
        types = {r["type_local"] for r in rows if r["type_local"]}
        maisons = {(r["id_parcelle"], r["surface_reelle_bati"], r["nombre_pieces_principales"]) for r in rows if r["code_type_local"] == "1"}
        # One house and nothing else built, in one commune: otherwise the price covers several properties.
        if len(maisons) != 1 or types - {"Maison", "Dépendance"} or len({r["code_commune"] for r in rows}) != 1:
            continue
        _, surf, _ = next(iter(maisons))
        surf = float(surf or 0)
        coords = [(float(r["latitude"]), float(r["longitude"])) for r in rows if r["latitude"] and r["longitude"]]
        if surf < 30 or not coords:
            continue
        prix = float(tete["valeur_fonciere"])
        pm2 = prix / surf
        if not PRIX_M2_MIN <= pm2 <= PRIX_M2_MAX:
            continue
        terrain = {(r["id_parcelle"], r["code_nature_culture"]): float(r["surface_terrain"]) for r in rows if r["surface_terrain"]}
        ventes.append({
            "commune": tete["code_commune"], "nom": tete["nom_commune"],
            "lat": statistics.mean(c[0] for c in coords), "lon": statistics.mean(c[1] for c in coords),
            "surf": surf, "terrain": sum(terrain.values()), "pm2": pm2,
        })
    return ventes


def km(a, b):
    r = math.pi / 180
    x = math.sin((b[0] - a[0]) * r / 2) ** 2 + math.cos(a[0] * r) * math.cos(b[0] * r) * math.sin((b[1] - a[1]) * r / 2) ** 2
    return 2 * 6371 * math.asin(math.sqrt(x))


def quartiles(valeurs):
    q = statistics.quantiles(valeurs, n=4, method="inclusive")
    return [round(q[0]), round(q[1]), round(q[2])]


def bande(surf):
    for i in range(len(SURFACES) - 1):
        if SURFACES[i] <= surf < SURFACES[i + 1]:
            return i
    return len(SURFACES) - 2


def main():
    annees = annees_publiees()
    ventes = lire_ventes(annees)
    par_commune = collections.defaultdict(list)
    for v in ventes:
        par_commune[v["commune"]].append(v)
    communes = {}
    for code, vs in par_commune.items():
        centre = (statistics.median(v["lat"] for v in vs), statistics.median(v["lon"] for v in vs))
        communes[code] = {"nom": vs[0]["nom"], "c": [round(centre[0], 4), round(centre[1], 4)], "b": {}}
    for info in communes.values():
        proches = sorted(((km(info["c"], (v["lat"], v["lon"])), v) for v in ventes), key=lambda x: x[0])
        for i in range(len(SURFACES) - 1):
            for grand in (0, 1):
                # Smallest radius that gives enough comparable sales.
                for rayon in RAYONS_KM:
                    sel = [v["pm2"] for d, v in proches if d <= rayon and bande(v["surf"]) == i and (v["terrain"] >= GRAND_TERRAIN) == bool(grand)]
                    if len(sel) >= MIN_VENTES:
                        info["b"][f"{i}{grand}"] = quartiles(sel) + [len(sel), rayon]
                        break
    sortie = {
        "source": "DVF géolocalisé (Etalab), ventes de maisons du Calvados",
        "periode": f"{annees[0]}-{annees[-1]}", "calcule_le": date.today().isoformat(),
        "surfaces": list(SURFACES[:-1]) + [None], "grand_terrain": GRAND_TERRAIN,
        "format": "b[<bande de surface><1 si terrain >= grand_terrain>] = [Q1, médiane, Q3, ventes, rayon km] en €/m²",
        "communes": communes,
    }
    SORTIE.write_text(json.dumps(sortie, ensure_ascii=False, separators=(",", ":")))
    print(len(ventes), "ventes,", len(communes), "communes,", SORTIE.stat().st_size, "octets")


if __name__ == "__main__":
    main()
