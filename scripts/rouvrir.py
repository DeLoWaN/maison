"""Removes listings from the discarded registry of a search, so that the next run reads them again.

The registry (`vus` of data.php) holds every listing that a run opened and discarded. nouveautes.php skips
these listings. When a rule changes, the listings discarded under the old rule must leave the registry.

A listing is removed when its reason (`motif`) matches the regular expression given with --motif, ignoring
case and accents. Only the registry changes: a tracked house is never touched.

Usage:
    python3 scripts/rouvrir.py --recherche maison --motif 'rdc|rez-de-chauss'     # prints, sends nothing
    python3 scripts/rouvrir.py --recherche maison --motif 'hors zone : (cahagnes|seulline)' --envoyer

Reading and writing the site needs the script token in ~/.config/maison/token (see auth-lib.php).

Output (stdout, JSON): {"ops": [oublier_vu operations], "par_motif": {motif: number}, "refus"?: [...]}.
Exit status 1 when patch.php refuses an operation.
"""
import argparse
import collections
import json
import pathlib
import re
import sys

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))
from adresse import JETON, ErreurSource, Site, option_recherche, sans_accents  # noqa: E402


def traiter(vus, motif):
    """Returns the oublier_vu operations of the listings whose reason matches `motif`, and a count per reason."""
    cherche = re.compile(sans_accents(motif).lower())
    ops, par_motif = [], collections.Counter()
    for v in vus:
        raison = v.get("motif") or ""
        if v.get("url") and cherche.search(sans_accents(raison).lower()):
            ops.append({"op": "oublier_vu", "url": v["url"]})
            par_motif[raison] += 1
    return {"ops": ops, "par_motif": dict(par_motif.most_common())}


def main(argv=None):
    p = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    option_recherche(p)
    p.add_argument("--motif", required=True, help="regular expression matched against the reason of each discarded listing")
    p.add_argument("--envoyer", action="store_true", help="send the operations to patch.php")
    a = p.parse_args(argv)
    if not JETON.exists():
        p.error(f"{JETON} is missing: the site cannot be read or written without the script token")
    site = Site(JETON.read_text().strip(), a.recherche)
    try:
        out = traiter(site._appel("data.php?vus=1")["vus"], a.motif)
        if a.envoyer:
            out["refus"] = site.envoyer(out["ops"])
    except ErreurSource as e:
        p.exit(2, f"rouvrir.py: {e}\n")
    json.dump(out, sys.stdout, ensure_ascii=False, indent=1)
    print()
    return 1 if out.get("refus") else 0


if __name__ == "__main__":
    sys.exit(main())
