"""Builds maison/recherches/maison/zone.geojson from zone.json and the commune outlines of geo.api.gouv.fr.

Output: one feature for the zone and one for the border communes, each the union of the commune outlines.
Usage: python3 scripts/zone-geojson.py
"""
import json
import pathlib
import unicodedata
import urllib.request

from shapely.geometry import mapping, shape
from shapely.ops import unary_union

RACINE = pathlib.Path(__file__).resolve().parent.parent / "maison" / "recherches" / "maison"
URL = "https://geo.api.gouv.fr/departements/14/communes?fields=nom,code&format=geojson&geometry=contour"
# About 30 m: keeps the outline readable at zoom 13 and the file small.
TOLERANCE = 0.0003


def slug(nom):
    s = unicodedata.normalize("NFD", nom.lower())
    s = "".join(c for c in s if unicodedata.category(c) != "Mn")
    return "-".join("".join(c if c.isalnum() else " " for c in s).split())


def main():
    zone = json.loads((RACINE / "zone.json").read_text())
    with urllib.request.urlopen(URL, timeout=60) as r:
        communes = json.load(r)["features"]
    par_slug = {slug(f["properties"]["nom"]): f for f in communes}
    sorties = []
    for nom, liste in (("zone", zone["communes"]), ("bordure", zone["bordure"])):
        # zone.json also lists merged former communes, which have no outline of their own.
        trouvees = [par_slug[s] for s in liste if s in par_slug]
        forme = unary_union([shape(f["geometry"]) for f in trouvees]).simplify(TOLERANCE)
        sorties.append({"type": "Feature", "properties": {"nom": nom, "communes": len(trouvees)}, "geometry": mapping(forme)})
        print(nom, len(trouvees), "communes")
    (RACINE / "zone.geojson").write_text(json.dumps({"type": "FeatureCollection", "features": sorties}, separators=(",", ":")))


if __name__ == "__main__":
    main()
