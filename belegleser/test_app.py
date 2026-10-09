"""Tests: erzeugt einen QR-Code mit RKSV-Inhalt, druckt ihn auf ein Bon-ähnliches Bild und liest ihn zurück."""

import io

import qrcode
from fastapi.testclient import TestClient
from PIL import Image, ImageDraw

from app import app

INHALT = "_R1-AT1_KASSE-01_4711_2026-10-08T19:42:11_12,40_31,80_0,00_0,00_0,00_dGVzdHRlc3Q=_3a7f19c2_AAAAAAAAAAA=_" + "A" * 86 + "=="

client = TestClient(app)


def _bon(inhalt: str, verkleinern: float = 1.0) -> bytes:
    qr = qrcode.make(inhalt).convert("L")
    if verkleinern != 1.0:
        qr = qr.resize((int(qr.width * verkleinern), int(qr.height * verkleinern)))
    bon = Image.new("L", (qr.width + 200, qr.height + 600), 245)
    zeichnen = ImageDraw.Draw(bon)
    for i, zeile in enumerate(["GASTHAUS ZUR LINDE", "2x Schnitzel  37,80", "Summe EUR 44,20"]):
        zeichnen.text((40, 40 + i * 30), zeile, fill=30)
    bon.paste(qr, (100, 300))
    puffer = io.BytesIO()
    bon.save(puffer, format="JPEG", quality=70)
    return puffer.getvalue()


def test_gesund():
    assert client.get("/gesund").json()["status"] == "ok"


def test_liest_rksv_qr_aus_jpeg():
    antwort = client.post("/qr", files={"datei": ("bon.jpg", _bon(INHALT), "image/jpeg")})
    assert antwort.status_code == 200
    codes = antwort.json()["codes"]
    assert [c["text"] for c in codes] == [INHALT]


def test_kein_code():
    leer = io.BytesIO()
    Image.new("L", (400, 400), 255).save(leer, format="PNG")
    antwort = client.post("/qr", files={"datei": ("leer.png", leer.getvalue(), "image/png")})
    assert antwort.json()["codes"] == []


def test_kaputte_datei():
    antwort = client.post("/qr", files={"datei": ("x.jpg", b"kein bild", "image/jpeg")})
    assert antwort.status_code == 422
