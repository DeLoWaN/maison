"""Tests of scripts/indices_bienici.py, with a fake of Bien'ici (no network).

Run: python3 -m unittest scripts/test_indices_bienici.py -v
The listings below are invented.
"""
import pathlib
import sys
import unittest

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))
import adresse as A  # noqa: E402
import indices_bienici as I  # noqa: E402

URL = "https://www.bienici.com/annonce/vente/alpha/maison/5pieces/"


def annonce(**champs):
    ad = {"energyValue": 324, "greenhouseGazValue": 10, "minEnergyConsumption": 2970, "maxEnergyConsumption": 4070,
          "energyPerformanceDiagnosticDate": "2025-11-04", "landSurfaceArea": 1300,
          "energyClassification": "E", "greenhouseGazClassification": "B", "city": "Alpha", "postalCode": "14999"}
    ad.update(champs)
    return ad


class FauxBienIci:
    """Answers from an in-memory dict {listing id: JSON}; an unknown id answers like a removed listing."""

    def __init__(self, annonces):
        self.annonces, self.appels = annonces, []

    def annonce(self, ident):
        self.appels.append(ident)
        if ident not in self.annonces:
            raise A.ErreurSource(f"{I.API}{ident}: HTTP Error 404: Not Found")
        return self.annonces[ident]


def bien(ident="alpha-300", commune="Alpha", indices=None, liens=("ag1-100",)):
    return {"id": ident, "commune": commune, "indices": indices,
            "liens": [{"site": "x", "url": u if u.startswith("http") else URL + u, "ok": True} for u in liens]}


def traiter(biens, annonces):
    faux = FauxBienIci(annonces)
    out = I.traiter(biens, faux, pause=0)
    print("\n", out, faux.appels)
    return out, faux


class Lecture(unittest.TestCase):
    def test_identifiant(self):
        self.assertEqual(I.identifiant(URL + "ag340369-509213609"), "ag340369-509213609")
        self.assertEqual(I.identifiant(URL + "ag1-100/?q=1#photos"), "ag1-100")
        self.assertIsNone(I.identifiant("https://www.seloger.com/annonces/achat/maison/alpha/123.htm"))

    def test_indices_complets_lus_par_adresse(self):
        ind = I.indices_annonce(annonce())
        self.assertEqual(ind, {"kwh": "324", "co2": "10", "depense": "entre 2 970 € et 4 070 €",
                               "date_dpe": "04/11/2025", "terrain": "1 300", "dpe": "E", "ges": "B"})
        lu = A.lire_indices({"indices": ind})
        self.assertEqual((lu["kwh"], lu["co2"], lu["depense"], lu["terrain"]), (324, 10, (2970, 4070), 1300))
        self.assertEqual(lu["date"].isoformat(), "2025-11-04")
        self.assertFalse(lu["terrain_exact"])

    def test_decimales_et_terrain_exact(self):
        ind = I.indices_annonce(annonce(energyValue=205.9, greenhouseGazValue=6.5, landSurfaceArea=1403))
        self.assertEqual((ind["kwh"], ind["co2"], ind["terrain"]), ("205,9", "6,5", "1 403"))
        lu = A.lire_indices({"indices": ind})
        self.assertEqual((lu["kwh"], lu["co2"]), (205.9, 6.5))
        self.assertTrue(lu["terrain_exact"])

    def test_valeurs_absentes_ou_nulles_ignorees(self):
        ind = I.indices_annonce(annonce(energyValue=0, greenhouseGazValue=0, minEnergyConsumption=None,
                                        energyPerformanceDiagnosticDate=None, landSurfaceArea=None,
                                        energyClassification="NS", greenhouseGazClassification="VI"))
        self.assertEqual(ind, {})

    def test_co2_nul_garde_avec_la_consommation(self):
        self.assertEqual(I.indices_annonce(annonce(greenhouseGazValue=0))["co2"], "0")

    def test_date_avec_heure(self):
        self.assertEqual(I.indices_annonce(annonce(energyPerformanceDiagnosticDate="2026-08-04T00:00:00.000Z"))["date_dpe"], "04/08/2026")

    def test_fourchette_inversee_ignoree(self):
        self.assertNotIn("depense", I.indices_annonce(annonce(minEnergyConsumption=4000, maxEnergyConsumption=3000)))

    def test_meme_commune(self):
        self.assertTrue(I.meme_commune("Bayeux (14400, commune exacte à confirmer)", "Bayeux"))
        self.assertTrue(I.meme_commune("Épinay-sur-Odon", "Epinay sur Odon"))
        self.assertTrue(I.meme_commune("Thue et Mue", "Thue-et-Mue"))
        self.assertFalse(I.meme_commune("Villers-Bocage", "Aurseulles"))


class Traitement(unittest.TestCase):
    def test_complete_sans_ecraser(self):
        b = bien(indices={"kwh": "330", "terrain": "1 000", "terrain_exact": False})
        out, _ = traiter([b], {"ag1-100": annonce()})
        self.assertEqual(out["ops"], [{"op": "maj", "id": "alpha-300", "champs": {"indices": {
            "kwh": "330", "terrain": "1 000", "terrain_exact": False, "co2": "10",
            "depense": "entre 2 970 € et 4 070 €", "date_dpe": "04/11/2025", "dpe": "E", "ges": "B"}}}])

    def test_adresse_trouvee_et_indices_complets_non_appeles(self):
        b = bien(indices=I.indices_annonce(annonce()))
        b["adresse"] = {"confiance": "probable", "texte": "1 rue X 14999 Alpha"}
        out, faux = traiter([b], {"ag1-100": annonce()})
        self.assertEqual((out["ops"], faux.appels), ([], []))

    def test_sans_adresse_indices_complets_lus_pour_la_commune(self):
        b = bien("villers-153", "Villers-Bocage", indices=I.indices_annonce(annonce()), liens=("ag1-100", "ag2-200"))
        b["adresse"] = {"confiance": "non_trouvee"}
        out, faux = traiter([b], {"ag1-100": annonce(), "ag2-200": annonce(city="Aurseulles")})
        self.assertEqual(out["ops"], [])
        self.assertEqual(faux.appels, ["ag1-100", "ag2-200"])
        self.assertEqual(len(out["communes_differentes"]), 2)

    def test_bien_sans_lien_bienici_ignore(self):
        out, faux = traiter([bien(liens=("https://www.seloger.com/annonces/123.htm",))], {})
        self.assertEqual((out["ops"], out["erreurs"], faux.appels), ([], [], []))

    def test_rien_de_nouveau_pas_d_operation(self):
        out, _ = traiter([bien(indices={"kwh": "150"})], {"ag1-100": annonce(
            energyValue=None, greenhouseGazValue=None, minEnergyConsumption=None, maxEnergyConsumption=None,
            energyPerformanceDiagnosticDate=None, landSurfaceArea=None, energyClassification=None, greenhouseGazClassification=None)})
        self.assertEqual(out["ops"], [])

    def test_deuxieme_lien_complete_le_premier(self):
        b = bien(liens=("ag1-100", "ag2-200"))
        out, faux = traiter([b], {"ag1-100": annonce(minEnergyConsumption=None, landSurfaceArea=None),
                                  "ag2-200": annonce(energyValue=999, landSurfaceArea=1250)})
        ind = out["ops"][0]["champs"]["indices"]
        self.assertEqual((ind["kwh"], ind["terrain"], ind["depense"]), ("324", "1 250", "entre 2 970 € et 4 070 €"))
        self.assertEqual(faux.appels, ["ag1-100", "ag2-200"])

    def test_adresse_trouvee_premier_lien_complet_arrete(self):
        b = bien(liens=("ag1-100", "ag2-200"))
        b["adresse"] = {"confiance": "forte", "texte": "1 rue X 14999 Alpha"}
        _, faux = traiter([b], {"ag1-100": annonce(), "ag2-200": annonce()})
        self.assertEqual(faux.appels, ["ag1-100"])

    def test_annonce_retiree_signalee_sans_planter(self):
        out, _ = traiter([bien(liens=("parti-1", "ag1-100"))], {"ag1-100": annonce()})
        self.assertEqual([e["url"] for e in out["erreurs"]], [URL + "parti-1"])
        self.assertEqual(len(out["ops"]), 1)

    def test_commune_differente_signalee(self):
        b = bien("villers-153", "Villers-Bocage")
        out, _ = traiter([b], {"ag1-100": annonce(city="Aurseulles", postalCode="14240")})
        self.assertEqual(out["communes_differentes"], [{"id": "villers-153", "commune": "Villers-Bocage",
                                                         "bienici": "Aurseulles (14240)", "url": URL + "ag1-100"}])
        self.assertEqual(len(out["ops"]), 1)


if __name__ == "__main__":
    unittest.main()
