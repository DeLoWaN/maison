"""Fills the `indices` field of tracked houses from their Bien'ici listings, without a browser or a model.

Bien'ici publishes each listing as JSON at realEstateAd.json?id=<id>, where <id> is the last segment of
the listing URL. That JSON holds every value of the energy diagnosis (DPE) that scripts/adresse.py reads.
The script adds the missing keys of `indices` and never replaces a key that is already present.
It also reports the houses whose Bien'ici city differs from their commune.

Houses read: active houses with a bienici.com link, except those that already have a "forte" or
"probable" address and every key of `indices`.

Usage:
    python3 scripts/indices_bienici.py                     # active houses of the site, nothing sent
    python3 scripts/indices_bienici.py --envoyer           # same, and sends the operations to patch.php
    python3 scripts/indices_bienici.py --ids a,b           # only these houses
    python3 scripts/indices_bienici.py --entree biens.json # reads {"biens": [...]} from a file instead of the site

Reading and writing the site needs the script token in ~/.config/maison/token (see auth-lib.php).

Output (stdout, JSON):
    {"ops": [maj operations for patch.php],
     "communes_differentes": [{id, commune, bienici, url}],
     "erreurs": [{id, url, raison}], "refus"?: [...]}
Exit status 1 when patch.php refuses an operation.
"""
import argparse
import json
import pathlib
import re
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

# `python3 -I` does not put the script directory on the path.
sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))
from adresse import JETON, ErreurSource, Site, date_fr, sans_accents  # noqa: E402

API = "https://www.bienici.com/realEstateAd.json?id="
# Bien'ici answers 403 to the default urllib User-Agent.
AGENT = "Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36"
ESSAIS_RESEAU = 3
PAUSE_S = 1
# The keys that this script can fill. `terrain_exact` stays out: adresse.py infers it from the value.
CLES = ("kwh", "co2", "depense", "date_dpe", "terrain", "dpe", "ges")
LETTRES = set("ABCDEFG")
ADRESSE_TROUVEE = ("forte", "probable")


class BienIci:
    """HTTP access to Bien'ici. Tests replace it with a fake that has the same method."""

    def annonce(self, ident):
        """Returns the listing JSON. Raises ErreurSource when the listing is gone or unreadable."""
        url = API + urllib.parse.quote(ident)
        for essai in range(ESSAIS_RESEAU):
            try:
                with urllib.request.urlopen(urllib.request.Request(url, headers={"User-Agent": AGENT}), timeout=30) as r:
                    data = r.read()
                break
            except OSError as e:
                # 404 means the listing was removed: a retry will not bring it back.
                definitif = isinstance(e, urllib.error.HTTPError) and e.code < 500
                if definitif or essai == ESSAIS_RESEAU - 1:
                    raise ErreurSource(f"{url}: {e}") from e
                time.sleep(2 * (essai + 1))
        try:
            return json.loads(data)
        except ValueError as e:
            raise ErreurSource(f"{url}: invalid JSON") from e


def identifiant(url):
    """Returns the listing id of a bienici.com listing URL, or None for another site."""
    u = urllib.parse.urlsplit(url)
    if not u.netloc.endswith("bienici.com"):
        return None
    dernier = u.path.rstrip("/").rsplit("/", 1)[-1]
    return dernier or None


def entier(v):
    """Formats 1300 as '1 300', like the listing pages."""
    return f"{round(v):,}".replace(",", " ")


def chiffre(v):
    """Formats 205.9 as '205,9' and 324.0 as '324'."""
    return f"{v:g}".replace(".", ",")


def positif(v):
    """Bien'ici writes 0 or null for a value that the listing does not give."""
    return isinstance(v, (int, float)) and not isinstance(v, bool) and v > 0


def indices_annonce(ad):
    """Returns the clues of a listing in the format of lire_indices() in adresse.py. Missing values are left out."""
    ind = {}
    if positif(ad.get("energyValue")):
        ind["kwh"] = chiffre(ad["energyValue"])
        # Without a consumption, a CO2 of 0 is more likely a missing value than a real one.
        if isinstance(ad.get("greenhouseGazValue"), (int, float)) and ad["greenhouseGazValue"] >= 0:
            ind["co2"] = chiffre(ad["greenhouseGazValue"])
    elif positif(ad.get("greenhouseGazValue")):
        ind["co2"] = chiffre(ad["greenhouseGazValue"])
    bas, haut = ad.get("minEnergyConsumption"), ad.get("maxEnergyConsumption")
    if positif(bas) and positif(haut) and bas <= haut:
        ind["depense"] = f"entre {entier(bas)} € et {entier(haut)} €"
    jour = date_fr(str(ad.get("energyPerformanceDiagnosticDate") or "")[:10])
    if jour:
        ind["date_dpe"] = jour.strftime("%d/%m/%Y")
    if positif(ad.get("landSurfaceArea")):
        ind["terrain"] = entier(ad["landSurfaceArea"])
    # Bien'ici also writes "NS" (not subject to a DPE) or "VI" (empty DPE).
    for cle, champ in (("dpe", "energyClassification"), ("ges", "greenhouseGazClassification")):
        lettre = str(ad.get(champ) or "").strip().upper()
        if lettre in LETTRES:
            ind[cle] = lettre
    return ind


def meme_commune(commune, ville):
    """True when the Bien'ici city appears in the commune of the house ('Bayeux (14400, à confirmer)' holds 'Bayeux')."""
    def norme(s):
        return " ".join(re.sub(r"[^a-z0-9]+", " ", sans_accents(s or "").lower()).split())
    return not ville or norme(ville) in norme(commune)


def traiter(biens, bienici, pause=PAUSE_S):
    ops, communes, erreurs = [], [], []
    for b in biens:
        actuels = dict(b.get("indices") or {})
        urls = [l["url"] for l in b.get("liens") or [] if identifiant(l.get("url", ""))]
        trouvee = (b.get("adresse") or {}).get("confiance") in ADRESSE_TROUVEE
        # A house without an address is read even with full clues, for the commune check.
        if not urls or (trouvee and all(k in actuels for k in CLES)):
            continue
        complets = dict(actuels)
        for url in urls:
            try:
                ad = bienici.annonce(identifiant(url))
            except ErreurSource as e:
                erreurs.append({"id": b["id"], "url": url, "raison": str(e)})
                continue
            finally:
                time.sleep(pause)
            if not meme_commune(b.get("commune"), ad.get("city")):
                communes.append({"id": b["id"], "commune": b.get("commune"),
                                 "bienici": f"{ad.get('city')} ({ad.get('postalCode')})", "url": url})
            for k, v in indices_annonce(ad).items():
                complets.setdefault(k, v)
            if trouvee and all(k in complets for k in CLES):
                break
        # patch.php replaces the whole field: the operation carries the keys already present too.
        if complets != actuels:
            ops.append({"op": "maj", "id": b["id"], "champs": {"indices": complets}})
    return {"ops": ops, "communes_differentes": communes, "erreurs": erreurs}


def main(argv=None):
    p = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    p.add_argument("--entree", help='JSON file {"biens": [...]}; by default, the active houses of the site')
    p.add_argument("--ids", help="comma-separated house ids to process (default: all)")
    p.add_argument("--envoyer", action="store_true", help="send the operations to patch.php")
    a = p.parse_args(argv)
    site = None
    if not a.entree or a.envoyer:
        if not JETON.exists():
            p.error(f"{JETON} is missing: the site cannot be read or written without the script token")
        site = Site(JETON.read_text().strip())
    try:
        biens = json.loads(pathlib.Path(a.entree).read_text())["biens"] if a.entree else site.biens_actifs()
        if a.ids:
            voulus = set(a.ids.split(","))
            biens = [b for b in biens if b["id"] in voulus]
        out = traiter(biens, BienIci())
        if a.envoyer:
            out["refus"] = site.envoyer(out["ops"])
    except ErreurSource as e:
        p.exit(2, f"indices_bienici.py: {e}\n")
    json.dump(out, sys.stdout, ensure_ascii=False, indent=1)
    print()
    return 1 if out.get("refus") else 0


if __name__ == "__main__":
    sys.exit(main())
