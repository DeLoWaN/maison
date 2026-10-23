"""Tests of scripts/rouvrir.py, with a fake of the tracking site (no network).

Run: python3 -m unittest scripts/test_rouvrir.py -v
"""
import json
import pathlib
import sys
import tempfile
import unittest
import unittest.mock

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))
import adresse as A  # noqa: E402
import rouvrir as R  # noqa: E402

VUS = [
    {"url": "https://exemple.test/1", "motif": "Pas de chambre au RDC", "prix": 200000},
    {"url": "https://exemple.test/2", "motif": "Pas de vraie chambre au RDC", "prix": 210000},
    {"url": "https://exemple.test/3", "motif": "Terrain 720 m² et aucune chambre au rez-de-chaussée", "prix": 220000},
    {"url": "https://exemple.test/4", "motif": "Hors zone : Seulline", "prix": 230000},
    {"url": "https://exemple.test/5", "motif": "Hors zone : Castillon", "prix": 240000},
    {"url": "https://exemple.test/6", "motif": "Programme neuf", "prix": 250000},
    {"url": "https://exemple.test/7", "motif": None},
    {"motif": "Pas de chambre au RDC"},
]


class Traiter(unittest.TestCase):
    def test_motif_rdc(self):
        out = R.traiter(VUS, "rdc|rez-de-chauss")
        print("\n", out)
        self.assertEqual([o["url"] for o in out["ops"]], [f"https://exemple.test/{i}" for i in (1, 2, 3)])
        self.assertTrue(all(o["op"] == "oublier_vu" for o in out["ops"]))

    def test_comptage_par_motif(self):
        out = R.traiter(VUS, "rdc")
        self.assertEqual(out["par_motif"], {"Pas de chambre au RDC": 1, "Pas de vraie chambre au RDC": 1})

    def test_communes_nommees(self):
        out = R.traiter(VUS, "hors zone : (cahagnes|seulline)")
        self.assertEqual([o["url"] for o in out["ops"]], ["https://exemple.test/4"])

    def test_accents_et_casse_ignores(self):
        self.assertEqual(len(R.traiter(VUS, "REZ-DE-CHAUSSÉE")["ops"]), 1)

    def test_annonce_sans_adresse_ou_sans_motif_ignoree(self):
        self.assertEqual(R.traiter([VUS[6], VUS[7]], ".")["ops"], [])

    def test_aucune_correspondance(self):
        self.assertEqual(R.traiter(VUS, "introuvable"), {"ops": [], "par_motif": {}})


class FauxSite:
    def __init__(self, jeton, recherche=None):
        self.recherche, self.appels = recherche, []

    def _appel(self, page, corps=None):
        self.appels.append(page)
        return {"vus": VUS}

    def envoyer(self, ops):
        self.appels.append(("envoi", len(ops)))
        return [{"n": 0, "ok": False}] if self.recherche == "refus" else []


class Main(unittest.TestCase):
    def lancer(self, args):
        crees = []

        def fabrique(jeton, recherche=None):
            crees.append(FauxSite(jeton, recherche))
            return crees[-1]

        sortie = []
        with tempfile.TemporaryDirectory() as d:
            jeton = pathlib.Path(d) / "token"
            jeton.write_text("secret\n")
            with unittest.mock.patch.object(R, "JETON", jeton), unittest.mock.patch.object(R, "Site", fabrique), \
                    unittest.mock.patch.object(R.json, "dump", lambda o, *a, **k: sortie.append(o)):
                code = R.main(args)
        return code, crees[0], sortie[0]

    def test_sans_envoyer_rien_n_est_envoye(self):
        code, site, out = self.lancer(["--recherche", "maison", "--motif", "rdc"])
        print("\n", site.appels, out)
        self.assertEqual((code, site.appels, site.recherche, len(out["ops"])), (0, ["data.php?vus=1"], "maison", 2))

    def test_envoyer_envoie_les_operations(self):
        code, site, out = self.lancer(["--recherche", "maison", "--motif", "rdc", "--envoyer"])
        self.assertEqual((code, site.appels[-1], out["refus"]), (0, ("envoi", 2), []))

    def test_refus_donne_le_code_1(self):
        code, _, out = self.lancer(["--recherche", "refus", "--motif", "rdc", "--envoyer"])
        self.assertEqual((code, len(out["refus"])), (1, 1))


if __name__ == "__main__":
    unittest.main()
