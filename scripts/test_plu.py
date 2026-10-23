"""Tests of scripts/plu.py, with a fake of the IGN apicarto services (no network).

Run: python3 -m unittest scripts/test_plu.py -v
The parcels, zones and documents below are invented.
"""
import json
import pathlib
import sys
import tempfile
import unittest
import unittest.mock

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))
import adresse as A  # noqa: E402
import plu as P  # noqa: E402

POLYGONE = {"type": "Polygon", "coordinates": [[[0, 0], [0, 1], [1, 1], [0, 0]]]}


def zone(libelle="UA", typezone="U", partition="DU_1", doc="abc", nomfic="reglement.pdf"):
    return {"properties": {"libelle": libelle, "libelong": "centre ancien", "typezone": typezone, "idurba": "PLUi_1",
                           "partition": partition, "gpu_doc_id": doc, "nomfic": nomfic}}


class FausseApi:
    """Answers with the lists given at creation and records the geometries it receives."""

    def __init__(self, parcelles=None, zones=None, servitudes=None):
        self.liste_parcelles = [{"geometry": POLYGONE, "properties": {"contenance": 770, "idu": "SECRET"}}] if parcelles is None else parcelles
        self.liste_zones, self.liste_servitudes = zones or [], servitudes or []
        self.recus = []

    def parcelle(self, geom):
        self.recus.append(("parcelle", geom))
        return self.liste_parcelles

    def zones(self, geom):
        self.recus.append(("zones", geom))
        return self.liste_zones

    def servitudes(self, geom):
        self.recus.append(("servitudes", geom))
        return self.liste_servitudes


def bien(id="a", lon=-0.6, lat=49.1):
    return {"id": id, "adresse": {"confiance": "forte", "lon": lon, "lat": lat}}


class Analyser(unittest.TestCase):
    def test_zone_urbaine_sans_abf(self):
        api = FausseApi(zones=[zone()])
        out = P.analyser(bien(), api)
        print("\n", out)
        self.assertEqual((out["regime"], out["zone"]["libelle"], out["zone"]["type"], out["abf"], out["parcelle_m2"]),
                         ("plu", "UA", "U", False, 770))
        self.assertEqual(out["reglement_url"], "https://data.geopf.fr/annexes/gpu/documents/DU_1/abc/reglement.pdf")

    def test_le_point_de_l_adresse_puis_la_parcelle_sont_interroges(self):
        api = FausseApi(zones=[zone()])
        P.analyser(bien(lon=-0.5, lat=49.2), api)
        self.assertEqual(api.recus, [("parcelle", {"type": "Point", "coordinates": [-0.5, 49.2]}),
                                     ("zones", POLYGONE), ("servitudes", POLYGONE)])

    def test_abords_de_monument_detectes(self):
        api = FausseApi(zones=[zone()], servitudes=[{"properties": {"suptype": "ac1"}}])
        self.assertTrue(P.analyser(bien(), api)["abf"])

    def test_autre_servitude_ne_donne_pas_d_abf(self):
        api = FausseApi(zones=[zone()], servitudes=[{"properties": {"suptype": "i4"}}, {"properties": {}}])
        self.assertFalse(P.analyser(bien(), api)["abf"])

    def test_zone_agricole(self):
        out = P.analyser(bien(), FausseApi(zones=[zone("A", "A")]))
        self.assertEqual((out["zone"]["libelle"], out["zone"]["type"]), ("A", "A"))

    def test_sans_plu(self):
        out = P.analyser(bien(), FausseApi(zones=[]))
        self.assertEqual((out["regime"], out["zone"], out["reglement_url"]), ("sans_plu", None, None))

    def test_plusieurs_zones_sur_la_parcelle(self):
        out = P.analyser(bien(), FausseApi(zones=[zone("UA"), zone("N", "N"), zone("UA")]))
        self.assertEqual((out["zone"]["libelle"], out["autres_zones"]), ("UA", ["N"]))

    def test_numero_de_parcelle_absent_de_la_sortie(self):
        out = P.analyser(bien(), FausseApi(zones=[zone()]))
        self.assertNotIn("SECRET", json.dumps(out))

    def test_aucune_parcelle(self):
        with self.assertRaises(A.ErreurSource):
            P.analyser(bien(), FausseApi(parcelles=[]))

    def test_reglement_absent_si_le_document_n_a_pas_de_fichier(self):
        self.assertIsNone(P.url_reglement({"properties": {"partition": "DU_1", "gpu_doc_id": "abc"}}))


class Traiter(unittest.TestCase):
    def setUp(self):
        patcher = unittest.mock.patch.object(P.time, "sleep", lambda s: None)
        patcher.start()
        self.addCleanup(patcher.stop)

    def test_bien_sans_coordonnees_signale(self):
        out = P.traiter([bien("a"), {"id": "b", "adresse": {"confiance": "forte"}}, {"id": "c"}], FausseApi(zones=[zone()]))
        print("\n", out)
        self.assertEqual(([b["id"] for b in out["biens"]], out["sans_point"], out["erreurs"]), (["a"], ["b", "c"], []))

    def test_erreur_d_un_bien_n_arrete_pas_les_autres(self):
        class Capricieuse(FausseApi):
            def parcelle(self, geom):
                if geom["coordinates"][0] == 1:
                    raise A.ErreurSource("service en panne")
                return super().parcelle(geom)

        out = P.traiter([bien("a", lon=1), bien("b", lon=2)], Capricieuse(zones=[zone()]))
        self.assertEqual(([b["id"] for b in out["biens"]], out["erreurs"]), (["b"], [{"id": "a", "raison": "service en panne"}]))


class Main(unittest.TestCase):
    def test_lecture_d_un_fichier(self):
        with tempfile.TemporaryDirectory() as d:
            f = pathlib.Path(d) / "biens.json"
            f.write_text(json.dumps({"biens": [bien("a"), bien("b")]}))
            with unittest.mock.patch.object(P.time, "sleep", lambda s: None), \
                    unittest.mock.patch("sys.stdout.write"):
                code = P.main(["--entree", str(f), "--ids", "b"], api=FausseApi(zones=[zone()]))
        self.assertEqual(code, 0)

    def test_ids_limitent_les_biens(self):
        with tempfile.TemporaryDirectory() as d:
            f = pathlib.Path(d) / "biens.json"
            f.write_text(json.dumps({"biens": [bien("a"), bien("b")]}))
            sortie = []
            with unittest.mock.patch.object(P.time, "sleep", lambda s: None), \
                    unittest.mock.patch.object(P.json, "dump", lambda o, *a, **k: sortie.append(o)):
                P.main(["--entree", str(f), "--ids", "b"], api=FausseApi(zones=[zone()]))
        self.assertEqual([b["id"] for b in sortie[0]["biens"]], ["b"])


if __name__ == "__main__":
    unittest.main()
