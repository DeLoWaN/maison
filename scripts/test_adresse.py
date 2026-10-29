"""Tests of scripts/adresse.py, with a fake of the open data services (no network).

Run: python3 -m unittest scripts/test_adresse.py -v
The DPE, communes and parcels below are invented.
"""
import argparse
import json
import pathlib
import sys
import unittest
import unittest.mock
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

    def __init__(self, dpes, parcelles=None, panne=False, adresses=None):
        self.dpes, self.parc, self.panne, self.requetes = dpes, parcelles or {}, panne, []
        self.adresses = adresses or {}

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

    def geocoder(self, adresse, code_postal):
        return self.adresses.get((adresse, code_postal))


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
        self.assertEqual(A.fourchette("entre 2400.00 € et 3310.00 €"), (2400, 3310))
        self.assertEqual(A.fourchette("entre 860 et 1 230 € par an"), (860, 1230))

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
        self.assertEqual((res["lon"], res["lat"]), ORIGINE)
        self.assertIn("trouvé dans Alpha", res["preuves"])

    def test_surface_tres_differente_empeche_forte_et_poursuit_la_recherche(self):
        # Same values in Alpha but 80 m² for 150 m²: the right house is in Beta, same postal code.
        dpes = [dpe("A1", "1 Rue Une 14999 Alpha", "14001", 201, 7, 2310, 80),
                dpe("B1", "5 Rue Deux 14999 Beta", "14002", 199, 8, 2290, 152)]
        confiance, res = chercher(self.BIEN, dpes)
        self.assertEqual((confiance, res["texte"]), ("forte", "5 Rue Deux 14999 Beta"), res)

    def test_un_seul_indice_dans_une_autre_commune_ne_suffit_pas(self):
        dpes = [dpe("B1", "5 Rue Deux 14999 Beta", "14002", 150, 3, 2310, 150)]
        confiance, raison = chercher(self.BIEN, dpes)
        self.assertIsNone(confiance, raison)

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


class Recalcul2026(unittest.TestCase):
    # Values of a real DPE of 2025, recalculated in 2026: 165.2 kWh in the base, 189 in the listing.
    BIEN = {"id": "alpha-273", "commune": "Alpha", "surface": 218,
            "indices": {"kwh": "189", "co2": "6", "depense": "entre 3 000 € et 4 400 €"}}

    @staticmethod
    def recalcule(jour="2025-03-19", type_n1="Électricité"):
        d = dpe("A1", "2 Chemin Un 14999 Alpha", "14001", 165.2, 6.1, 3712, 218, jour=jour)
        d.update(conso_5_usages_ef_energie_n1=12587.1, type_energie_n1=type_n1, conso_5_usages_ef_energie_n2=11253.9,
                 type_energie_n2="Bois – Granulés (pellets) ou briquettes", conso_auxiliaires_ef=454.6)
        return d

    def test_ancienne_consommation_recalculee(self):
        self.assertAlmostEqual(A.kwh_avant_2026(self.recalcule()), 189.1, places=1)

    def test_dpe_d_avant_2026_recalcule_compte(self):
        confiance, res = chercher(self.BIEN, [self.recalcule()])
        print("\n", res)
        self.assertEqual(confiance, "forte", res)
        self.assertIn("soit 189.1 avant le recalcul de 2026", res["preuves"])

    def test_dpe_de_2026_n_est_pas_recalcule(self):
        self.assertIsNone(A.kwh_avant_2026(self.recalcule(jour="2026-03-10")))

    def test_sans_electricite_pas_de_recalcul(self):
        self.assertIsNone(A.kwh_avant_2026(self.recalcule(type_n1="Gaz naturel")))

    def test_consommation_plus_basse_sans_electricite_ne_compte_pas(self):
        # 165.2 for 189 announced, but no electricity: another house, even with the same CO2.
        bien = dict(self.BIEN, indices={"kwh": "189", "co2": "6"})
        confiance, res = chercher(bien, [self.recalcule(type_n1="Gaz naturel")])
        self.assertIsNone(confiance, res)

    def test_concordance_directe_prioritaire(self):
        direct = dpe("A2", "3 Chemin Un 14999 Alpha", "14001", 189, 6, 3712, 218, jour="2025-05-02")
        confiance, res = chercher(self.BIEN, [self.recalcule(), direct])
        self.assertEqual((confiance, res["texte"]), ("forte", "3 Chemin Un 14999 Alpha"), res)

    def test_dpe_geocode_moins_prioritaire(self):
        # Same figures in Alpha and in a DPE without normalised address: the normalised one wins.
        bien = dict(self.BIEN, indices={"kwh": "117", "co2": "4"}, surface=148)
        brut = dpe("V1", None, None, 118, 4, 1, 148)
        brut.update(adresse_brut="13 Impasse Deux", code_postal_brut=14999, _geopoint=None)
        adresses = {("13 Impasse Deux", "14999"): {"label": "13 Impasse Deux 14999 Beta", "citycode": "14002", "score": 0.95, "lon": -0.4, "lat": 49.0}}
        src = FausseSource([dpe("A1", "1 Allée Une 14999 Alpha", "14001", 117, 4, 1, 148), brut], adresses=adresses)
        confiance, res = A.Recherche(src).chercher(bien)
        self.assertEqual((confiance, res["texte"]), ("probable", "1 Allée Une 14999 Alpha"), res)

    def test_meme_chiffres_dans_deux_communes_du_code_postal_escalade(self):
        bien = dict(self.BIEN, indices={"kwh": "117", "co2": "4"}, surface=148)
        dpes = [dpe("A1", "1 Allée Une 14999 Alpha", "14001", 117, 4, 1, 148),
                dpe("B1", "2 Allée Deux 14999 Beta", "14002", 118, 4, 1, 148)]
        confiance, res = chercher(bien, dpes)
        self.assertIsNone(confiance, res)

    def test_lettres_ignorees_quand_l_annonce_donne_des_chiffres(self):
        bien = {"id": "x", "commune": "Alpha", "surface": 86, "indices": {"kwh": "252", "co2": "78", "dpe": "F", "ges": "F"}}
        dpes = [dpe("A1", "13 Rue des Écoles 14999 Alpha", "14001", 252, 78, 1, 86, lettres=("F", "F")),
                dpe("B1", "1 Chemin Deux 14999 Beta", "14002", 220, 70, 1, 84, lettres=("F", "F"))]
        confiance, res = chercher(bien, dpes)
        self.assertEqual((confiance, res["texte"]), ("probable", "13 Rue des Écoles 14999 Alpha"), res)

    def test_requete_elargie_vers_le_bas(self):
        f = A.Recherche.requetes(A.lire_indices(self.BIEN), False)[0]
        self.assertEqual((f["conso_5_usages_par_m2_ep_gte"], f["conso_5_usages_par_m2_ep_lte"]), (153.1, 192))


class DpeSansAdresseNormalisee(unittest.TestCase):
    BIEN = {"id": "alpha-300", "commune": "Alpha", "surface": 92,
            "indices": {"kwh": "287", "co2": "29", "depense": "entre 2 700 € et 3 690 €"}}

    def brut(self):
        d = dpe("V1", None, None, 287, 29, 3199, 92, jour="2026-10-07")
        d.update(adresse_brut="11 rue du 13 août 1944", code_postal_brut=14999, _geopoint=None)
        return d

    def test_dpe_brut_geocode_au_plus_probable(self):
        adresses = {("11 rue du 13 août 1944", "14999"): {"label": "11 Rue du 13 Juin 1944 14999 Alpha", "citycode": "14001",
                                                          "score": 0.54, "lon": -0.43, "lat": 49.11}}
        src = FausseSource([self.brut()], adresses=adresses)
        confiance, res = A.Recherche(src).chercher(self.BIEN)
        print("\n", res)
        self.assertEqual((confiance, res["texte"]), ("probable", "11 Rue du 13 Juin 1944 14999 Alpha"), res)
        self.assertEqual((res["lon"], res["lat"]), (-0.43, 49.11))
        self.assertIn("géocodée par le script (score 0.54)", res["preuves"])
        self.assertIn({"code_postal_brut_in": "14999"}, [{k: v for k, v in r.items() if k == "code_postal_brut_in"} for r in src.requetes])

    def test_dpe_brut_introuvable_a_la_ban_est_ignore(self):
        confiance, res = A.Recherche(FausseSource([self.brut()])).chercher(self.BIEN)
        self.assertIsNone(confiance, res)


class BaseDesLogementsNeufs(unittest.TestCase):
    def test_sources_interroge_les_deux_bases(self):
        appels = []

        def faux_json(url, params=None):
            appels.append(url)
            return {"results": [{"numero_dpe": url.split("/")[-2]}]}
        src = A.Sources()
        with unittest.mock.patch.object(src, "_json", side_effect=faux_json):
            res = src.dpe({"code_insee_ban_eq": "14001"})
        self.assertEqual([d["_base"] for d in res], ["dpe03existant", "dpe02neuf"])
        self.assertTrue(appels[1].endswith("/dpe02neuf/lines"), appels)

    def test_lien_et_preuves_d_un_dpe_neuf(self):
        d = dpe("N1", "16 Rue des Lilas 14999 Alpha", "14001", 201, 7, 2310, 150)
        d["_base"] = "dpe02neuf"
        confiance, res = chercher(Recherche.BIEN, [d])
        self.assertEqual(confiance, "forte", res)
        self.assertIn("/datasets/dpe02neuf/", res["lien"])
        self.assertIn("base des logements neufs", res["preuves"])


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
        # Dated 2026, B1 cannot be a DPE recalculated with the 2026 electricity factor.
        return [dpe("B1", "4 Route du Pré 14999 Beta", "14002", 206, 7, 2333, 184, jour="2026-03-10", pt=(-0.5, 49.0)),
                dpe("B2", "8 Route du Pré 14999 Beta", "14002", 150, 7, 2340, 180, pt=(-0.49, 49.0))]

    def test_parcelle_exacte_pres_du_dpe_donne_forte(self):
        parc = {"14002": [("P1", 6862, carre(-0.5003, 49.0)), ("P2", 900, carre(-0.4902, 49.0))]}
        confiance, res = chercher(self.BIEN, self.dpes(), parc)
        self.assertEqual((confiance, res["texte"]), ("forte", "4 Route du Pré 14999 Beta"), res)
        self.assertIn("parcelle P1 de 6862 m² à l'adresse", res["preuves"])

    def test_somme_de_deux_parcelles_proches_ne_compte_pas(self):
        parc = {"14002": [("P1", 6000, carre(-0.5003, 49.0)), ("P2", 862, carre(-0.4998, 49.0))]}
        confiance, res = chercher(self.BIEN, self.dpes(), parc)
        self.assertIsNone(confiance, res)
        self.assertIn("un seul indice", res)

    def test_somme_avec_une_parcelle_eloignee_ne_compte_pas(self):
        # P2 is about 110 m from the address point: too far to belong to the house.
        parc = {"14002": [("P1", 6000, carre(-0.5003, 49.0)), ("P2", 862, carre(-0.4985, 49.0))]}
        confiance, res = chercher(self.BIEN, self.dpes(), parc)
        self.assertIsNone(confiance, res)
        self.assertIn("un seul indice", res)

    def test_somme_de_trois_parcelles_ne_compte_pas(self):
        parc = {"14002": [("P1", 2000, carre(-0.5003, 49.0)), ("P2", 2000, carre(-0.4998, 49.0)),
                          ("P3", 2862, carre(-0.5003, 49.0004))]}
        confiance, res = chercher(self.BIEN, self.dpes(), parc)
        self.assertIsNone(confiance, res)
        self.assertIn("un seul indice", res)

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
        self.assertEqual(out["communes_differentes"], [])
        self.assertNotIn("_insee", adr)

    def test_adresse_dans_une_autre_commune_signalee(self):
        bien = {"id": "x", "commune": "Alpha", "surface": 150,
                "indices": {"kwh": "200", "co2": "7", "depense": "entre 2 000 € et 2 600 €"}}
        src = FausseSource([dpe("B1", "1 Rue Deux 14999 Beta", "14002", 201, 7, 2310, 150)])
        out = A.traiter([bien], A.Recherche(src), "2026-10-29")
        print("\n", out)
        self.assertEqual(len(out["ops"]), 1)
        self.assertEqual(out["communes_differentes"], [{"id": "x", "commune": "Alpha", "trouvee": "1 Rue Deux 14999 Beta"}])

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


class SiteRecherche(unittest.TestCase):
    """The search id reaches the site as `r`, in the URL."""

    def appeler(self, recherche, page, corps=None):
        vus = []

        class Reponse:
            def __enter__(self):
                return self

            def __exit__(self, *a):
                return False

            def read(self):
                return b"{}"

        def faux_urlopen(req, timeout=None):
            vus.append((req.full_url, None if req.data is None else json.loads(req.data), req.get_header("X-maison-jeton")))
            return Reponse()

        with unittest.mock.patch.object(A.urllib.request, "urlopen", faux_urlopen):
            A.Site("jeton", recherche)._appel(page, corps)
        print("\n", vus)
        return vus[0]

    def test_sans_recherche_aucun_parametre(self):
        self.assertEqual(self.appeler(None, "patch.php", {"ops": []})[0], A.SITE + "patch.php")

    def test_recherche_ajoutee_a_une_page_sans_parametre(self):
        self.assertEqual(self.appeler("extensible", "suivi.php")[0], A.SITE + "suivi.php?r=extensible")

    def test_recherche_ajoutee_apres_un_parametre_existant(self):
        self.assertEqual(self.appeler("extensible", "data.php?leger=1")[0], A.SITE + "data.php?leger=1&r=extensible")

    def test_corps_inchange_et_jeton_envoye(self):
        url, corps, jeton = self.appeler("extensible", "patch.php", {"ops": [1]})
        self.assertEqual((url, corps, jeton), (A.SITE + "patch.php?r=extensible", {"ops": [1]}, "jeton"))

    def test_option_recherche_de_la_ligne_de_commande(self):
        p = argparse.ArgumentParser()
        A.option_recherche(p)
        self.assertEqual((p.parse_args([]).recherche, p.parse_args(["--recherche", "extensible"]).recherche), (None, "extensible"))


if __name__ == "__main__":
    unittest.main()
