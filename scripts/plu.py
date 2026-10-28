"""Reads the urban planning facts of tracked houses from the coordinates of their stored address.

For each house, the script asks the public APIs of IGN (apicarto, no account) for:
- the cadastral parcel under the address point, with its area in m²;
- the zone of the local urban plan (PLU or PLUi) on that parcel: type U, AU, A or N, its label, and the link to
  the written regulation (PDF);
- the protection AC1 (abords of a historic monument): when it covers the parcel, the architect of the
  Bâtiments de France (ABF) must agree to the works.

The script judges nothing. A model reads the regulation of zones A and N for the limit on house extensions.
The parcel number is not in the output: it points to the address, which is private.

Houses read: houses of the search whose `adresse` has `lon` and `lat` (scripts/coordonnees.py adds them).

Usage:
    python3 scripts/plu.py --recherche extensible              # active houses of the search
    python3 scripts/plu.py --recherche extensible --ids a,b    # only these houses
    python3 scripts/plu.py --entree biens.json                 # reads {"biens": [...]} from a file instead of the site

Reading the site needs the script token in ~/.config/maison/token (see auth-lib.php).

Output (stdout, JSON): {"biens": [{id, parcelle_m2, parcelle_proche, regime, zone, abf, reglement_url}],
                        "sans_point": [id], "erreurs": [{id, raison}]}
regime is "plu" when a zone covers the parcel, "sans_plu" otherwise (national urban planning rules or
communal map: an existing house can be extended outside the built-up area).
zone is {libelle, libelong, type, document}, or null without a PLU.
parcelle_proche is true when the address point is outside every parcel: the facts then come from the nearest
parcel within RAYON_PROCHE_M, which may be a neighbour of the house.
"""
import argparse
import json
import math
import pathlib
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))
from adresse import ESSAIS_RESEAU, JETON, ErreurSource, Site, centre, distance_m, option_recherche  # noqa: E402

APICARTO = "https://apicarto.ign.fr/api"
# Search distance for the nearest parcel when the address point is outside every parcel.
RAYON_PROCHE_M = 30
GPU_ANNEXES = "https://data.geopf.fr/annexes/gpu/documents"
# Protection type of the abords of a historic monument.
SUP_ABORDS = "ac1"


class ApiCarto:
    """Queries apicarto with a GeoJSON geometry. A plain object so that tests can replace it."""

    def _get(self, chemin, geom):
        url = f"{APICARTO}/{chemin}?" + urllib.parse.urlencode({"geom": json.dumps(geom, separators=(",", ":"))})
        for essai in range(ESSAIS_RESEAU):
            try:
                req = urllib.request.Request(url, headers={"User-Agent": "maison-plu/1.0"})
                with urllib.request.urlopen(req, timeout=60) as r:
                    return json.loads(r.read()).get("features", [])
            except (OSError, ValueError) as e:
                if essai == ESSAIS_RESEAU - 1:
                    raise ErreurSource(f"{url[:90]}: {e}") from e
                time.sleep(2 * (essai + 1))

    def parcelle(self, geom):
        return self._get("cadastre/parcelle", geom)

    def zones(self, geom):
        return self._get("gpu/zone-urba", geom)

    def servitudes(self, geom):
        return self._get("gpu/assiette-sup-s", geom)


def point(lon, lat):
    return {"type": "Point", "coordinates": [lon, lat]}


def carre(lon, lat, cote_m):
    """Square polygon of side 2 × cote_m metres centred on the point."""
    dx = cote_m / (111320 * math.cos(math.radians(lat)))
    dy = cote_m / 110540
    anneau = [[lon - dx, lat - dy], [lon + dx, lat - dy], [lon + dx, lat + dy], [lon - dx, lat + dy], [lon - dx, lat - dy]]
    return {"type": "Polygon", "coordinates": [anneau]}


def anneau_exterieur(geom):
    return geom["coordinates"][0] if geom["type"] == "Polygon" else max((p[0] for p in geom["coordinates"]), key=len)


def parcelle_de(api, lon, lat):
    """Returns (parcel feature, True when it is only the nearest parcel) for the address point.

    The address point of the national address base often sits on the road, outside every parcel.
    """
    parcelles = api.parcelle(point(lon, lat))
    if parcelles:
        return parcelles[0], False
    voisines = api.parcelle(carre(lon, lat, RAYON_PROCHE_M))
    if not voisines:
        raise ErreurSource(f"aucune parcelle cadastrale à moins de {RAYON_PROCHE_M} m du point de l'adresse")
    return min(voisines, key=lambda f: distance_m(centre(anneau_exterieur(f["geometry"])), (lon, lat))), True


def url_reglement(zone):
    """Link to the written regulation of the plan that holds `zone` (a zone-urba feature), or None."""
    p = zone["properties"]
    if not (p.get("partition") and p.get("gpu_doc_id") and p.get("nomfic")):
        return None
    return f"{GPU_ANNEXES}/{p['partition']}/{p['gpu_doc_id']}/{p['nomfic']}"


def analyser(bien, api):
    """Returns the planning facts of one house whose address has coordinates."""
    adr = bien["adresse"]
    parcelle, proche = parcelle_de(api, adr["lon"], adr["lat"])
    geom = parcelle["geometry"]
    zones = api.zones(geom)
    # A parcel can cover several zones: the first zone of the answer is reported, the labels of the others are listed.
    zone = zones[0] if zones else None
    abf = any(str(s["properties"].get("suptype", "")).lower() == SUP_ABORDS for s in api.servitudes(geom))
    out = {
        "id": bien["id"],
        "parcelle_m2": parcelle["properties"].get("contenance"),
        "parcelle_proche": proche,
        "regime": "plu" if zone else "sans_plu",
        "zone": None,
        "abf": abf,
        "reglement_url": None,
    }
    if zone:
        p = zone["properties"]
        out["zone"] = {"libelle": p.get("libelle"), "libelong": p.get("libelong"), "type": p.get("typezone"),
                       "document": p.get("idurba")}
        out["reglement_url"] = url_reglement(zone)
        if len(zones) > 1:
            out["autres_zones"] = sorted({z["properties"].get("libelle") for z in zones[1:]} - {p.get("libelle")})
    return out


def traiter(biens, api):
    """Returns the planning facts of the houses; a house without coordinates goes to sans_point."""
    out = {"biens": [], "sans_point": [], "erreurs": []}
    for b in biens:
        adr = b.get("adresse") or {}
        if "lon" not in adr or "lat" not in adr:
            out["sans_point"].append(b["id"])
            continue
        try:
            out["biens"].append(analyser(b, api))
        except ErreurSource as e:
            out["erreurs"].append({"id": b["id"], "raison": str(e)})
        time.sleep(0.2)
    return out


def main(argv=None, api=None):
    p = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    option_recherche(p)
    p.add_argument("--entree", help='JSON file {"biens": [...]}; by default, the active houses of the site')
    p.add_argument("--ids", help="comma-separated house ids to process (default: all)")
    a = p.parse_args(argv)
    try:
        if a.entree:
            biens = json.loads(pathlib.Path(a.entree).read_text())["biens"]
        else:
            if not JETON.exists():
                p.error(f"{JETON} is missing: the site cannot be read without the script token")
            biens = Site(JETON.read_text().strip(), a.recherche).biens_actifs()
        if a.ids:
            voulus = set(a.ids.split(","))
            biens = [b for b in biens if b["id"] in voulus]
        out = traiter(biens, api or ApiCarto())
    except ErreurSource as e:
        p.exit(2, f"plu.py: {e}\n")
    json.dump(out, sys.stdout, ensure_ascii=False, indent=1)
    print()
    return 0


if __name__ == "__main__":
    sys.exit(main())
