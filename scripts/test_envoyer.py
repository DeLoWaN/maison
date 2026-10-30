"""Tests of scripts/envoyer.py, with a fake of the tracking site (no network).

Run: python3 -m unittest scripts/test_envoyer.py -v
"""
import io
import json
import pathlib
import sys
import tempfile
import unittest
import unittest.mock
import urllib.error

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


class EnvoyerRecherche(unittest.TestCase):
    def lancer(self, extra):
        crees = []

        class SiteCree(FauxSite):
            def __init__(self, jeton, recherche=None):
                super().__init__(lambda page, corps: {"resultats": [{"n": 0, "ok": True}], "nums": {}})
                crees.append((jeton, recherche))

        with tempfile.TemporaryDirectory() as d:
            jeton = pathlib.Path(d) / "token"
            jeton.write_text("secret\n")
            ops = pathlib.Path(d) / "ops.json"
            ops.write_text(json.dumps([vu(1)]))
            with unittest.mock.patch.object(E, "JETON", jeton), unittest.mock.patch.object(E, "Site", SiteCree):
                E.main(extra + ["ops", str(ops)])
        print("\n", crees)
        return crees

    def test_recherche_donnee_au_site(self):
        self.assertEqual(self.lancer(["--recherche", "extensible"]), [("secret", "extensible")])

    def test_sans_recherche_le_site_choisit(self):
        self.assertEqual(self.lancer([]), [("secret", None)])


class Reessai400(unittest.TestCase):
    """Site._appel retries once a request that the site answered with HTTP 400."""

    def reponses(self, *codes):
        suite = list(codes)

        def ouvrir(req, timeout):
            code = suite.pop(0)
            if code != 200:
                raise urllib.error.HTTPError(req.full_url, code, "Bad Request", {}, io.BytesIO(b""))
            return io.BytesIO(b'{"ok": true}')
        return ouvrir

    def appeler(self, *codes):
        ouvrir = self.reponses(*codes)
        with unittest.mock.patch.object(A.urllib.request, "urlopen", ouvrir), \
                unittest.mock.patch.object(A, "ATTENTE_REESSAI", 0):
            return A.Site("jeton", "maison")._appel("patch.php", {"ops": []})

    def test_un_400_puis_succes(self):
        self.assertEqual(self.appeler(400, 200), {"ok": True})

    def test_deux_400_levent_erreur(self):
        with self.assertRaises(A.ErreurSource):
            self.appeler(400, 400)

    def test_autre_code_sans_reessai(self):
        with self.assertRaises(A.ErreurSource):
            self.appeler(500, 200)


if __name__ == "__main__":
    unittest.main()
