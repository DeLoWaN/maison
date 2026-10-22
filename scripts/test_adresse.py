"""Tests of scripts/adresse.py, with a fake of the open data services (no network).

Run: python3 -m unittest scripts/test_adresse.py -v
The DPE, communes and parcels below are invented.
"""
import pathlib
import sys
import unittest
from datetime import date

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))
import adresse as A  # noqa: E402

# Two communes share the postal code 14999; a third one is elsewhere in the department.
COMMUNES = {
    "Alpha": {"nom": "Alpha", "code": "14001", "codesPostaux": ["14999"]},
    "Beta": {"nom": "Beta", "code": "14002", "codesPostaux": ["14999"]},
}
CODES_POSTAUX = {"14999": ["14001", "14002"]}
ORIGINE = (-0.5, 49.0)


def dpe(numero, adresse, insee, conso, co2, cout, surface, jour="2024-03-10", lettres=("D", "B"), pt=ORIGINE):
    return {
        "numero_dpe": numero, "adresse_ban": adresse, "code_insee_ban": insee, "code_departement_ban": "14",
        "conso_5_usages_par_m2_ep": conso, "emission_ges_5_usages_par_m2": co2, "cout_total_5_usages": cout,
        "surface_habitable_logement": surface, "date_etablissement_dpe": jour,
        "etiquette_dpe": lettres[0], "etiquette_ges": lettres[1], "_geopoint": f"{pt[1]},{pt[0]}",
    }


def carre(lon, lat, cote=0.0004):
    return [(lon, lat), (lon + cote, lat), (lon + cote, lat + cote), (lon, lat + cote)]


class FausseSource:
    """Applies the Data Fair filters of adresse.py to an in-memory list of DPE."""

    def __init__(self, dpes, parcelles=None, panne=False):
        self.dpes, self.parc, self.panne, self.requetes = dpes, parcelles or {}, panne, []

    def dpe(self, filtres):
        if self.panne:
            raise A.ErreurSource("ADEME: timeout")
        self.requetes.append(filtres)
        return [d for d in self.dpes if all(self._garde(d, k, v) for k, v in filtres.items())]

    @staticmethod
    def _garde(d, cle, v):
        if cle == "type_batiment_eq":
            return True
        if cle == "geo_distance":
            lon, lat, r = v.split(",")
            return A.distance_m(A.point_dpe(d), (float(lon), float(lat))) <= float(r.rstrip("m"))
        champ, op = cle.rsplit("_", 1)
        x = d.get(champ)
        if op == "eq":
            return str(x) == str(v)
        if op == "in":
            return str(x) in str(v).split(",")
        if x is None:
            return False
        if op == "gte":
            return x >= v if not isinstance(x, str) else x >= str(v)
        if op == "lte":
            return x <= v if not isinstance(x, str) else x <= str(v)
        raise AssertionError(f"filter not supported by the fake: {cle}")

    def commune(self, nom):
        return COMMUNES.get(nom)

    def communes_du_code_postal(self, cp):
        return CODES_POSTAUX.get(cp, [])

    def parcelles(self, insee):
        return self.parc.get(insee, [])


def chercher(bien, dpes, parcelles=None):
    return A.Recherche(FausseSource(dpes, parcelles)).chercher(bien)


class Lecture(unittest.TestCase):
    def test_nombre(self):
        self.assertEqual(A.nombre("205,9"), 205.9)
        self.assertEqual(A.nombre("6*"), 6)
        self.assertEqual(A.nombre("1 403 m²"), 1403)
        self.assertEqual(A.nombre(229), 229)
        self.assertIsNone(A.nombre(None))
        self.assertIsNone(A.nombre("néant"))

    def test_fourchette(self):
        self.assertEqual(A.fourchette("entre 1 960 € et 2 710 €"), (1960, 2710))
        self.assertEqual(A.fourchette("Entre 5 620 € et 7 660 € par an"), (5620, 7660))
        self.assertIsNone(A.fourchette("non communiqué"))

    def test_date(self):
        self.assertEqual(A.date_fr("18/01/2022"), date(2022, 1, 18))
        self.assertEqual(A.date_fr("2023-04-30"), date(2023, 4, 30))
        self.assertEqual(A.date_fr("1er février 2021"), date(2021, 2, 1))
        self.assertIsNone(A.date_fr("récent"))

    def test_terrain_exact_ou_arrondi(self):
        exact = A.lire_indices({"indices": {"terrain": "1 403"}})
        arrondi = A.lire_indices({"indices": {"terrain": "Environ 1 400 m²"}})
        force = A.lire_indices({"indices": {"terrain": "1 500", "terrain_exact": True}})
        self.assertTrue(exact["terrain_exact"], exact)
        self.assertFalse(arrondi["terrain_exact"], arrondi)
        self.assertTrue(force["terrain_exact"], force)

    def test_indices_vides_ignores(self):
        self.assertEqual(A.lire_indices({"indices": {"kwh": None, "dpe": ""}}), {"terrain_exact": False})


class Recherche(unittest.TestCase):
    BIEN = {"id": "alpha-300", "commune": "Alpha", "surface": 150,
            "indices": {"kwh": "200", "co2": "7", "depense": "entre 2 000 € et 2 600 €"}}

    def test_valeurs_et_depense_uniques_dans_la_commune_donnent_forte(self):
        dpes = [dpe("A1", "1 Rue Une 14999 Alpha", "14001", 201, 7, 2310, 150),
                dpe("A2", "2 Rue Une 14999 Alpha", "14001", 120, 3, 1500, 90)]
        confiance, res = chercher(self.BIEN, dpes)
        self.assertEqual(confiance, "forte", res)
        self.assertEqual(res["texte"], "1 Rue Une 14999 Alpha")
        self.assertIn("trouvé dans Alpha", res["preuves"])

    def test_surface_tres_differente_empeche_forte_et_poursuit_la_recherche(self):
        # Same values in Alpha but 80 m² for 150 m²: the right house is in Beta, same postal code.
        dpes = [dpe("A1", "1 Rue Une 14999 Alpha", "14001", 201, 7, 2310, 80),
                dpe("B1", "5 Rue Deux 14999 Beta", "14002", 199, 8, 2290, 152)]
        confiance, res = chercher(self.BIEN, dpes)
        self.assertEqual((confiance, res["texte"]), ("forte", "5 Rue Deux 14999 Beta"), res)

    def test_dans_le_departement_seule_la_date_garde_un_dpe(self):
        bien = dict(self.BIEN, commune="Inconnue")
        dpes = [dpe("Z1", "9 Rue Loin 14500 Gamma", "14500", 200, 7, 2300, 150, jour="2024-03-10")]
        sans_date, raison = chercher(bien, dpes)
        self.assertIsNone(sans_date, raison)
        bien["indices"] = dict(bien["indices"], date_dpe="11/03/2024")
        avec_date, res = chercher(bien, dpes)
        self.assertEqual((avec_date, res["texte"]), ("forte", "9 Rue Loin 14500 Gamma"), res)

    def test_dans_le_departement_la_date_seule_ne_suffit_pas(self):
        bien = {"id": "x", "commune": "Inconnue", "surface": 140, "indices": {"date_dpe": "25/08/2023", "kwh": "290"}}
        dpes = [dpe("Z2", "Rue du Bas 14790 Loin", "14500", 180, 9, 2000, 107, jour="2023-08-24")]
        confiance, raison = chercher(bien, dpes)
        self.assertIsNone(confiance, raison)

    def test_dans_le_departement_valeurs_et_depense_comptent_pour_un(self):
        note = {"zone": A.ZONE_DEPARTEMENT, "indices": [("valeurs", ""), ("depense", "")]}
        self.assertEqual(A.poids(note), 1)
        self.assertEqual(A.poids(dict(note, zone="Alpha")), 2)

    def test_dans_le_departement_une_contradiction_ecarte_le_dpe(self):
        bien = {"id": "x", "commune": "Inconnue", "indices": {"date_dpe": "07/09/2026", "kwh": "150", "co2": "42", "dpe": "C"}}
        dpes = [dpe("Z1", "15 Allée Loin 14970 Gamma", "14500", 151, 44, 2000, 112, jour="2026-09-07", lettres=("D", "D"))]
        confiance, raison = chercher(bien, dpes)
        self.assertIsNone(confiance)
        self.assertIn("aucun DPE concordant", raison)

    def test_deux_dpe_aussi_bons_sont_escalades(self):
        dpes = [dpe("A1", "1 Rue Une 14999 Alpha", "14001", 201, 7, 2310, 150),
                dpe("A3", "3 Rue Une 14999 Alpha", "14001", 199, 6, 2290, 148)]
        confiance, raison = chercher(self.BIEN, dpes)
        self.assertIsNone(confiance)
        self.assertIn("2 DPE aussi plausibles", raison)

    def test_doublon_du_meme_batiment_compte_une_fois(self):
        d = dpe("A1", "1 Rue Une 14999 Alpha", "14001", 201, 7, 2310, 150)
        confiance, _ = chercher(self.BIEN, [d, dict(d, numero_dpe="A1bis")])
        self.assertEqual(confiance, "forte")

    def test_date_du_dpe(self):
        bien = {"id": "x", "commune": "Alpha", "surface": 150, "indices": {"date_dpe": "12/03/2024", "kwh": "200"}}
        dpes = [dpe("A1", "1 Rue Une 14999 Alpha", "14001", 201, 7, 2310, 150, jour="2024-03-10"),
                dpe("A2", "2 Rue Une 14999 Alpha", "14001", 201, 7, 2310, 150, jour="2023-01-01")]
        confiance, res = chercher(bien, dpes)
        self.assertEqual((confiance, res["texte"]), ("forte", "1 Rue Une 14999 Alpha"), res)

    def test_date_differente_contredit(self):
        bien = {"id": "x", "commune": "Alpha", "surface": 140, "indices": {"date_dpe": "25/08/2023", "kwh": "290", "co2": "9"}}
        dpes = [dpe("A1", "1 Rue Une 14999 Alpha", "14001", 292, 10, 2000, 137, jour="2025-02-01")]
        confiance, raison = chercher(bien, dpes)
        self.assertIsNone(confiance, raison)
        self.assertIn("DPE du 2025-02-01 contre le 2023-08-25", raison)

    def test_deux_contradictions_ecartent_le_dpe(self):
        bien = dict(self.BIEN, indices=dict(self.BIEN["indices"], dpe="D", date_dpe="10/03/2024"))
        dpes = [dpe("A1", "1 Rue Une 14999 Alpha", "14001", 201, 7, 2310, 53, lettres=("F", "F"))]
        confiance, raison = chercher(bien, dpes)
        self.assertIsNone(confiance, raison)

    def test_etiquette_contraire(self):
        bien = dict(self.BIEN, indices=dict(self.BIEN["indices"], dpe="C"))
        dpes = [dpe("A1", "1 Rue Une 14999 Alpha", "14001", 201, 7, 2310, 150, lettres=("E", "B"))]
        confiance, res = chercher(bien, dpes)
        self.assertEqual(confiance, "probable", res)
        self.assertIn("étiquette E au DPE contre C", res["preuves"])

    def test_sans_indice_chiffre(self):
        confiance, raison = chercher({"id": "x", "commune": "Alpha", "indices": {"terrain": "1 000"}}, [])
        self.assertIsNone(confiance)
        self.assertIn("pas de DPE chiffré", raison)

    def test_aucun_dpe(self):
        confiance, raison = chercher(self.BIEN, [])
        self.assertIsNone(confiance)
        self.assertIn("aucun DPE concordant", raison)


class FichierCadastre(unittest.TestCase):
    def test_parcelles_sans_surface_ou_multipolygones(self):
        geo = {"features": [
            {"properties": {"id": "P1", "contenance": 500}, "geometry": {"type": "Polygon", "coordinates": [carre(0, 0)]}},
            {"properties": {"id": "P2"}, "geometry": {"type": "Polygon", "coordinates": [carre(1, 1)]}},
            {"properties": {"id": "P3", "contenance": 900}, "geometry": {"type": "MultiPolygon",
                                                                         "coordinates": [[carre(2, 2)[:3]], [carre(3, 3)]]}},
            {"properties": {"id": "P4", "contenance": 100}, "geometry": None},
        ]}
        out = A.lire_parcelles(geo)
        self.assertEqual([(p[0], p[1], len(p[2])) for p in out], [("P1", 500, 4), ("P3", 900, 4)])


class Cadastre(unittest.TestCase):
    # Only the yearly cost matches: one clue. The exact land area is the second one.
    BIEN = {"id": "beta-250", "commune": "Alpha", "surface": 184,
            "indices": {"kwh": "229", "co2": "7", "depense": "entre 1 960 € et 2 710 €", "terrain": "6 862"}}

    def dpes(self):
        return [dpe("B1", "4 Route du Pré 14999 Beta", "14002", 206, 7, 2333, 184, pt=(-0.5, 49.0)),
                dpe("B2", "8 Route du Pré 14999 Beta", "14002", 150, 7, 2340, 180, pt=(-0.49, 49.0))]

    def test_parcelle_exacte_pres_du_dpe_donne_forte(self):
        parc = {"14002": [("P1", 6862, carre(-0.5003, 49.0)), ("P2", 900, carre(-0.4902, 49.0))]}
        confiance, res = chercher(self.BIEN, self.dpes(), parc)
        self.assertEqual((confiance, res["texte"]), ("forte", "4 Route du Pré 14999 Beta"), res)
        self.assertIn("P1 = 6862 m²", res["preuves"])

    def test_somme_avec_une_parcelle_eloignee_ne_compte_pas(self):
        # P2 is about 110 m from the address point: too far to belong to the house.
        parc = {"14002": [("P1", 6000, carre(-0.5003, 49.0)), ("P2", 862, carre(-0.4985, 49.0))]}
        confiance, res = chercher(self.BIEN, self.dpes(), parc)
        self.assertEqual(confiance, "probable", res)
        self.assertNotIn("parcelles", res["preuves"])

    def test_somme_de_trois_parcelles_ne_compte_pas(self):
        parc = {"14002": [("P1", 2000, carre(-0.5003, 49.0)), ("P2", 2000, carre(-0.4998, 49.0)),
                          ("P3", 2862, carre(-0.5003, 49.0004))]}
        confiance, res = chercher(self.BIEN, self.dpes(), parc)
        self.assertEqual(confiance, "probable", res)
        self.assertNotIn("parcelles", res["preuves"])

    def test_parcelle_exacte_seule_sans_dpe_concordant(self):
        bien = dict(self.BIEN, indices={"terrain": "1 403"})
        parc = {"14001": [("Q1", 1403, carre(-0.6, 49.1)), ("Q2", 1500, carre(-0.7, 49.1))]}
        dpes = [dpe("C1", "2 Chemin Vert 14999 Alpha", "14001", 300, 50, 4000, 180, pt=(-0.5998, 49.1002))]
        confiance, res = chercher(bien, dpes, parc)
        self.assertEqual((confiance, res["texte"]), ("probable", "2 Chemin Vert 14999 Alpha"), res)
        self.assertIn("seule de cette surface", res["preuves"])

    def test_un_seul_indice_contre_une_contradiction_est_escalade(self):
        bien = dict(self.BIEN, indices={"terrain": "1 403"})
        parc = {"14001": [("Q1", 1403, carre(-0.6, 49.1))]}
        dpes = [dpe("C1", "2 Chemin Vert 14999 Alpha", "14001", 300, 50, 4000, 90, pt=(-0.5998, 49.1002))]
        confiance, raison = chercher(bien, dpes, parc)
        self.assertIsNone(confiance, raison)


class Traitement(unittest.TestCase):
    def test_operations_et_escalade(self):
        biens = [
            {"id": "ok", "commune": "Alpha", "surface": 150, "adresse": {"confiance": "non_trouvee", "essais": 1},
             "indices": {"kwh": "200", "co2": "7", "depense": "entre 2 000 € et 2 600 €"}},
            {"id": "vide", "commune": "Alpha"},
        ]
        src = FausseSource([dpe("A1", "1 Rue Une 14999 Alpha", "14001", 201, 7, 2310, 150)])
        out = A.traiter(biens, A.Recherche(src), "2026-10-22")
        print("\n", out)
        self.assertEqual(len(out["ops"]), 1)
        adr = out["ops"][0]["champs"]["adresse"]
        self.assertEqual((adr["essais"], adr["le"], adr["confiance"]), (2, "2026-10-22", "forte"))
        self.assertEqual([e["id"] for e in out["a_escalader"]], ["vide"])

    def test_service_en_panne_escalade_sans_planter(self):
        bien = {"id": "x", "commune": "Alpha", "indices": {"kwh": "200"}}
        out = A.traiter([bien], A.Recherche(FausseSource([], panne=True)), "2026-10-22")
        self.assertEqual(out["ops"], [])
        self.assertIn("service indisponible", out["a_escalader"][0]["raison"])


class FauxSite(A.Site):
    """Answers like the site, without network; keeps the calls."""

    def __init__(self):
        super().__init__("jeton")
        self.appels = []

    def _appel(self, page, corps=None):
        self.appels.append((page, corps))
        if page == "suivi.php":
            return {"adresse": [{"id": "b"}, {"id": "a"}, {"id": "parti"}]}
        if page == "data.php?leger=1":
            return {"biens": [{"id": "a"}, {"id": "b"}, {"id": "c", "etat": "ecartee"}]}
        return {"resultats": [{"n": i, "op": "maj", "ok": o["id"] != "refuse"} for i, o in enumerate(corps["ops"])]}


class Site(unittest.TestCase):
    def test_biens_dans_l_ordre_de_suivi(self):
        self.assertEqual([b["id"] for b in FauxSite().biens_a_traiter()], ["b", "a"])

    def test_biens_actifs_sans_les_ecartes(self):
        self.assertEqual([b["id"] for b in FauxSite().biens_actifs()], ["a", "b"])

    def test_envoi_par_paquets_et_refus(self):
        site = FauxSite()
        ops = [{"op": "maj", "id": f"x{i}"} for i in range(85)] + [{"op": "maj", "id": "refuse"}]
        refus = site.envoyer(ops)
        print("\n", [len(c["ops"]) for _, c in site.appels], refus)
        self.assertEqual([len(c["ops"]) for _, c in site.appels], [40, 40, 6])
        self.assertEqual(len(refus), 1)


if __name__ == "__main__":
    unittest.main()
