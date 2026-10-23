"""Adds `lat` and `lon` to the stored addresses that have none.

The site opens Google Maps on these coordinates. A search on the address text can land in another street.
Each coordinate comes from the Base Adresse Nationale (BAN), the same source as the ADEME address.

Usage:
    python3 scripts/coordonnees.py             # prints the operations, sends nothing
    python3 scripts/coordonnees.py --envoyer   # sends them to patch.php

Output (stdout, JSON): {"ops": [maj operations], "sans_point": [{id, texte}], "refus"?: [...]}.
Houses in sans_point have no BAN answer with a score above SCORE_MIN: give them a point by hand.
Exit status 1 when patch.php refuses an operation.
"""
import argparse
import json
import pathlib
import sys

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))
import adresse as A  # noqa: E402

BAN = "https://api-adresse.data.gouv.fr/search/"
SCORE_MIN = 0.5


def point_ban(sources, texte):
    """Returns (lon, lat) of the best BAN answer for the address text, or None when the score is too low."""
    reponse = sources._json(BAN, {"q": texte, "limit": 1})
    if not reponse["features"] or reponse["features"][0]["properties"]["score"] < SCORE_MIN:
        return None
    lon, lat = reponse["features"][0]["geometry"]["coordinates"]
    return lon, lat


def traiter(biens, sources):
    ops, sans_point = [], []
    for b in biens:
        adr = b.get("adresse") or {}
        if not adr.get("texte") or adr.get("lat") is not None:
            continue
        point = point_ban(sources, adr["texte"])
        if point is None:
            sans_point.append({"id": b["id"], "texte": adr["texte"]})
            continue
        # The maj operation replaces the whole address, so the other fields are copied.
        ops.append({"op": "maj", "id": b["id"], "champs": {"adresse": {**adr, "lon": point[0], "lat": point[1]}}})
    return {"ops": ops, "sans_point": sans_point}


def main(argv=None):
    p = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    p.add_argument("--envoyer", action="store_true", help="send the operations to patch.php")
    a = p.parse_args(argv)
    if not A.JETON.exists():
        p.error(f"{A.JETON} is missing: the site cannot be read or written without the script token")
    site = A.Site(A.JETON.read_text().strip())
    try:
        out = traiter(site.biens_actifs(), A.Sources())
        if a.envoyer:
            out["refus"] = site.envoyer(out["ops"])
    except A.ErreurSource as e:
        p.exit(2, f"coordonnees.py: {e}\n")
    json.dump(out, sys.stdout, ensure_ascii=False, indent=1)
    print()
    return 1 if out.get("refus") else 0


if __name__ == "__main__":
    sys.exit(main())
