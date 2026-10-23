"""Sends to the tracking site the files that the subagents of a scheduled run write, with the script token.

A subagent writes its result to files. The main session sends them with this script, instead of copying
hundreds of operations into a javascript_tool call.

Usage:
    python3 scripts/envoyer.py ops <ops.json>              # [operation, ...] -> patch.php, packets of 40
    python3 scripts/envoyer.py nouveautes <dossier>        # <dossier>/annonces_nouveautes.json -> nouveautes.php
    python3 scripts/envoyer.py photos <galeries.json>      # [{"id", "photos"}, ...] -> photos.php

`nouveautes` reads [{url, prix}, ...] (or {"annonces": [...]}) and writes the merged answer of the server
to <dossier>/reponse_nouveautes.json, so that the subagent can go on without calling the site itself.

Reading and writing the site needs the script token in ~/.config/maison/token (see auth-lib.php).

Output (stdout, JSON):
    ops        {"nums": {id: num}, "refus": [{n, op, erreur, op_detail}]}
    nouveautes {"annonces", "nouvelles", "a_rouvrir", "suivies", "ecartees"} (counts)
    photos     {"envoyees": {id: number of photos}, "refus": [{id, erreur}]}
Exit status 1 when the site refuses an operation or a gallery.
"""
import argparse
import json
import pathlib
import sys

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))
from adresse import JETON, TAILLE_PAQUET, ErreurSource, Site  # noqa: E402

# nouveautes.php answers slowly above a few hundred listings.
TAILLE_NOUVEAUTES = 200


def envoyer_ops(site, ops):
    """Sends the operations to patch.php in packets. Returns the numbers given to new houses and the refusals."""
    nums, refus = {}, []
    for i in range(0, len(ops), TAILLE_PAQUET):
        res = site._appel("patch.php", {"ops": ops[i:i + TAILLE_PAQUET]})
        nums.update(res.get("nums") or {})
        for r in res.get("resultats", []):
            if not r.get("ok", True):
                n = i + r.get("n", 0)
                refus.append({**r, "n": n, "op_detail": ops[n] if n < len(ops) else None})
    return {"nums": nums, "refus": refus}


def envoyer_nouveautes(site, annonces):
    """Sends the listings to nouveautes.php in packets. Returns the answers merged into one."""
    out = {"nouvelles": [], "a_rouvrir": [], "suivies": [], "ecartees": 0}
    for i in range(0, len(annonces), TAILLE_NOUVEAUTES):
        res = site._appel("nouveautes.php", {"annonces": annonces[i:i + TAILLE_NOUVEAUTES]})
        for cle in ("nouvelles", "a_rouvrir", "suivies"):
            out[cle] += res.get(cle) or []
        e = res.get("ecartees", 0)
        out["ecartees"] += e if isinstance(e, int) else len(e)
    return out


def envoyer_photos(site, galeries):
    """Sends each gallery to photos.php. Returns the number of photos kept per house and the refusals."""
    envoyees, refus = {}, []
    for g in galeries:
        try:
            res = site._appel("photos.php", {"id": g["id"], "photos": g["photos"]})
        except ErreurSource as e:
            refus.append({"id": g["id"], "erreur": str(e)})
            continue
        if res.get("ok"):
            envoyees[g["id"]] = res.get("photos")
        else:
            refus.append({"id": g["id"], "erreur": res.get("erreur") or json.dumps(res, ensure_ascii=False)})
    return {"envoyees": envoyees, "refus": refus}


def lire_annonces(fichier):
    """Reads [{url, prix}, ...] or {"annonces": [...]}."""
    data = json.loads(pathlib.Path(fichier).read_text())
    return data["annonces"] if isinstance(data, dict) else data


def main(argv=None, site=None):
    p = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    p.add_argument("action", choices=["ops", "nouveautes", "photos"])
    p.add_argument("chemin", help="ops and photos: JSON file; nouveautes: folder holding annonces_nouveautes.json")
    a = p.parse_args(argv)
    if site is None:
        if not JETON.exists():
            p.error(f"{JETON} is missing: the site cannot be read or written without the script token")
        site = Site(JETON.read_text().strip())
    try:
        if a.action == "ops":
            out = envoyer_ops(site, json.loads(pathlib.Path(a.chemin).read_text()))
        elif a.action == "photos":
            out = envoyer_photos(site, json.loads(pathlib.Path(a.chemin).read_text()))
        else:
            dossier = pathlib.Path(a.chemin)
            annonces = lire_annonces(dossier / "annonces_nouveautes.json")
            rep = envoyer_nouveautes(site, annonces)
            (dossier / "reponse_nouveautes.json").write_text(json.dumps(rep, ensure_ascii=False))
            out = {"annonces": len(annonces), **{k: (len(v) if isinstance(v, list) else v) for k, v in rep.items()}}
    except ErreurSource as e:
        p.exit(2, f"envoyer.py: {e}\n")
    json.dump(out, sys.stdout, ensure_ascii=False, indent=1)
    print()
    return 1 if out.get("refus") else 0


if __name__ == "__main__":
    sys.exit(main())
