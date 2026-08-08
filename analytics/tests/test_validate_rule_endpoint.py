from fastapi.testclient import TestClient
from main import app

client = TestClient(app)


def _valid_rules():
    return {
        "entry": {
            "combinator": "all",
            "conditions": [
                {"left": {"indicator": "ema", "length": 5}, "comparator": "crosses_above", "right": {"indicator": "ema", "length": 20}},
            ],
        },
        "exit": {
            "combinator": "all",
            "conditions": [
                {"left": {"indicator": "ema", "length": 5}, "comparator": "crosses_below", "right": {"indicator": "ema", "length": 20}},
            ],
        },
    }


def test_validate_rule_returns_valid_true_for_a_well_formed_rule():
    response = client.post("/validate-rule", json={"rules": _valid_rules()})

    assert response.status_code == 200
    assert response.json() == {"valid": True}


def test_validate_rule_returns_valid_false_for_an_unknown_comparator():
    rules = _valid_rules()
    rules["entry"]["conditions"][0]["comparator"] = "not_a_real_comparator"

    response = client.post("/validate-rule", json={"rules": rules})

    assert response.status_code == 200
    body = response.json()
    assert body["valid"] is False
    assert "not_a_real_comparator" in body["error"]


def test_validate_rule_returns_valid_false_for_an_unknown_indicator():
    rules = _valid_rules()
    rules["exit"]["conditions"][0]["left"] = {"indicator": "made_up"}

    response = client.post("/validate-rule", json={"rules": rules})

    assert response.status_code == 200
    assert response.json()["valid"] is False


def test_validate_rule_requires_both_entry_and_exit():
    response = client.post("/validate-rule", json={"rules": {"entry": _valid_rules()["entry"]}})

    assert response.status_code == 200
    body = response.json()
    assert body["valid"] is False
    assert "exit" in body["error"]
