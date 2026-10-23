"""Tests of scripts/envoyer.py, with a fake of the tracking site (no network).

Run: python3 -m unittest scripts/test_envoyer.py -v
"""
import json
import pathlib
import sys
import tempfile
import unittest

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))
import adresse as A  # noqa: E402
import envoyer as E  # noqa: E402


class FauxSite:
    """Records each call and answers with the function `repondre(page, corps)`."""

    def __init__(self, repondre):
        self.repondre, self.appels = repondre, []

    def _appel(self, page, corps=None):
        self.appels.append((page, corps))
        return self.repondre(page, corps)


def vu(i):
    return {"op": "vu", "url": f"https://exemple.test/{i}", "motif": "Hors zone : Alpha", "prix": 100000 + i}


class EnvoyerOps(unittest.TestCase):
    def test_paquets_de_40_et_numeros_fusionnes(self):
        def rep(page, corps):
            n = len(corps["ops"])
            return {"ok": True, "resultats": [{"n": k, "ok": True} for k in range(n)],
                    "nums": {f"bien-{len(site.appels)}": len(site.appels)}}
        site = FauxSite(rep)
        out = E.envoyer_ops(site, [vu(i) for i in range(85)])
        tailles = [len(c["ops"]) for _, c in site.appels]
        print("packet sizes:", tailles, "nums:", out["nums"])
        self.assertEqual(tailles, [40, 40, 5])
        self.assertEqual(out["nums"], {"bien-1": 1, "bien-2": 2, "bien-3": 3})
        self.assertEqual(out["refus"], [])

    def test_refus_porte_le_rang_global_et_l_operation(self):
        def rep(page, corps):
            premier = len(site.appels) == 1
            return {"ok": True, "resultats": [{"n": 2, "ok": premier}, {"n": 0, "ok": True}]}
        site = FauxSite(rep)
        ops = [vu(i) for i in range(45)]
        out = E.envoyer_ops(site, ops)
        print("refusals:", out["refus"])
        self.assertEqual(len(out["refus"]), 1)
        self.assertEqual(out["refus"][0]["n"], 42)
        self.assertEqual(out["refus"][0]["op_detail"], ops[42])


class EnvoyerNouveautes(unittest.TestCase):
    def test_reponses_fusionnees(self):
        def rep(page, corps):
            self.assertEqual(page, "nouveautes.php")
            k = len(site.appels)
            return {"nouvelles": [{"url": f"n{k}"}], "a_rouvrir": [], "suivies": [{"id": f"s{k}"}],
                    "ecartees": 3 if k == 1 else [{"url": "e"}]}
        site = FauxSite(rep)
        annonces = [{"url": f"u{i}", "prix": i} for i in range(250)]
        out = E.envoyer_nouveautes(site, annonces)
        print("merged answer:", out)
        self.assertEqual([len(c["annonces"]) for _, c in site.appels], [200, 50])
        self.assertEqual(out["nouvelles"], [{"url": "n1"}, {"url": "n2"}])
        self.assertEqual(out["ecartees"], 4)

    def test_main_ecrit_la_reponse_dans_le_dossier(self):
        site = FauxSite(lambda page, corps: {"nouvelles": [], "a_rouvrir": [], "suivies": [], "ecartees": 1})
        with tempfile.TemporaryDirectory() as d:
            (pathlib.Path(d) / "annonces_nouveautes.json").write_text(json.dumps({"annonces": [{"url": "a", "prix": 1}]}))
            code = E.main(["nouveautes", d], site=site)
            rep = json.loads((pathlib.Path(d) / "reponse_nouveautes.json").read_text())
        print("exit code:", code, "answer file:", rep)
        self.assertEqual(code, 0)
        self.assertEqual(rep["ecartees"], 1)


class EnvoyerPhotos(unittest.TestCase):
    def test_refus_et_erreur_reseau_sont_rapportes(self):
        def rep(page, corps):
            if corps["id"] == "panne":
                raise A.ErreurSource("photos.php: HTTP Error 500")
            if corps["id"] == "refuse":
                return {"ok": False, "erreur": "id inconnu"}
            return {"ok": True, "photos": len(corps["photos"])}
        site = FauxSite(rep)
        out = E.envoyer_photos(site, [{"id": "bon", "photos": ["a", "b"]}, {"id": "refuse", "photos": ["a"]},
                                      {"id": "panne", "photos": ["a"]}])
        print("photos:", out)
        self.assertEqual(out["envoyees"], {"bon": 2})
        self.assertEqual([r["id"] for r in out["refus"]], ["refuse", "panne"])

    def test_main_rend_1_sur_refus(self):
        site = FauxSite(lambda page, corps: {"ok": False, "erreur": "x"})
        with tempfile.TemporaryDirectory() as d:
            f = pathlib.Path(d) / "g.json"
            f.write_text(json.dumps([{"id": "a", "photos": ["p"]}]))
            self.assertEqual(E.main(["photos", str(f)], site=site), 1)


if __name__ == "__main__":
    unittest.main()
