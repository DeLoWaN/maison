"""Tests of scripts/coordonnees.py, with a fake of the BAN (no network).

Run: python3 -m unittest scripts/test_coordonnees.py -v
"""
import pathlib
import sys
import unittest

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))
import coordonnees as C  # noqa: E402


class FausseBan:
    def __init__(self, reponses):
        self.reponses, self.appels = reponses, []

    def _json(self, url, params=None):
        self.appels.append(params["q"])
        score, point = self.reponses.get(params["q"], (None, None))
        if score is None:
            return {"features": []}
        return {"features": [{"properties": {"score": score}, "geometry": {"coordinates": list(point)}}]}


ADR = {"texte": "3 Rue Une 14999 Alpha", "confiance": "forte", "preuves": "p", "le": "2026-10-22", "essais": 1}


class Coordonnees(unittest.TestCase):
    def test_ajoute_lat_lon_et_garde_les_autres_champs(self):
        ban = FausseBan({ADR["texte"]: (0.97, (-0.5, 49.1))})
        out = C.traiter([{"id": "a", "adresse": dict(ADR)}], ban)
        self.assertEqual(out["ops"], [{"op": "maj", "id": "a", "champs": {"adresse": {**ADR, "lon": -0.5, "lat": 49.1}}}])
        self.assertEqual(out["sans_point"], [])

    def test_saute_les_biens_sans_adresse_ou_deja_avec_point(self):
        ban = FausseBan({})
        biens = [{"id": "a"}, {"id": "b", "adresse": {"confiance": "non_trouvee"}},
                 {"id": "c", "adresse": {**ADR, "lat": 49.1, "lon": -0.5}}]
        self.assertEqual(C.traiter(biens, ban), {"ops": [], "sans_point": []})
        self.assertEqual(ban.appels, [])

    def test_score_trop_bas_ou_aucune_reponse_va_dans_sans_point(self):
        ban = FausseBan({"x": (0.3, (0, 0))})
        biens = [{"id": "a", "adresse": {**ADR, "texte": "x"}}, {"id": "b", "adresse": {**ADR, "texte": "y"}}]
        out = C.traiter(biens, ban)
        self.assertEqual(out["ops"], [])
        self.assertEqual([s["id"] for s in out["sans_point"]], ["a", "b"])


if __name__ == "__main__":
    unittest.main()
