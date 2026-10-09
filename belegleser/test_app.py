"""Tests: erzeugt einen QR-Code mit RKSV-Inhalt, druckt ihn auf ein Bon-ähnliches Bild und liest ihn zurück."""

import io

import qrcode
from fastapi.testclient import TestClient
from PIL import Image, ImageDraw, ImageFont

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


# ---------- Sprint 2: Texterkennung ----------

BON_ZEILEN = [
    "GASTHAUS ZUR LINDE",
    "Hauptplatz 3, 9570 Ossiach",
    "UID: ATU12345678",
    "",
    "2 x Wiener Schnitzel      37,80",
    "1 x Kaesespaetzle         14,20",
    "3 x Zipfer Maerzen 0,5    15,30",
    "1 x Mineral               3,40",
    "",
    "SUMME EUR                 70,70",
    "Bar                       70,70",
    "",
    "MwSt  Netto  Steuer  Brutto",
    "10%   47,27   4,73   52,00",
    "20%   15,58   3,12   18,70",
    "",
    "Kassen-ID: KASSE-01",
    "Beleg-Nr.: 4711",
    "08.10.2026 19:42:11",
]


def thermobon(zeilen=BON_ZEILEN, qr_inhalt=None) -> bytes:
    schrift = ImageFont.truetype("DejaVuSansMono.ttf", 26)
    hoehe = 60 + len(zeilen) * 36 + (520 if qr_inhalt else 0)
    bon = Image.new("L", (620, hoehe), 248)
    z = ImageDraw.Draw(bon)
    for i, zeile in enumerate(zeilen):
        z.text((30, 30 + i * 36), zeile, fill=25, font=schrift)
    if qr_inhalt:
        bon.paste(qrcode.make(qr_inhalt).convert("L").resize((460, 460)), (80, 40 + len(zeilen) * 36))
    puffer = io.BytesIO()
    bon.save(puffer, format="JPEG", quality=85)
    return puffer.getvalue()


def test_lesen_liefert_text_und_qr():
    antwort = client.post("/lesen", files={"datei": ("bon.jpg", thermobon(qr_inhalt=INHALT), "image/jpeg")})
    assert antwort.status_code == 200
    j = antwort.json()
    assert [c["text"] for c in j["codes"]] == [INHALT]
    assert j["quelle"] == "ocr"
    assert "70,70" in j["text"]
    assert "ATU12345678" in j["text"]
    assert j["sicherheit"] > 0.7


def test_textschicht_nur_bei_pdf():
    from app import pdf_textschicht

    assert pdf_textschicht([]) is None


def test_gescanntes_pdf_nutzt_eingebettetes_bild():
    """Scanner-App-PDF: Originalbild verwenden, die Textschicht der Scanner-App ignorieren."""
    from app import _pdf_seiten, pdf_textschicht

    bild = Image.open(io.BytesIO(_bon(INHALT))).convert("RGB")
    puffer = io.BytesIO()
    bild.save(puffer, format="PDF", resolution=72)

    seiten = _pdf_seiten(puffer.getvalue())
    assert seiten[0]["scan"] is True
    assert seiten[0]["bild"].size == bild.size
    assert pdf_textschicht(seiten) is None

    antwort = client.post("/lesen", files={"datei": ("scan.pdf", puffer.getvalue(), "application/pdf")})
    j = antwort.json()
    assert [c["text"] for c in j["codes"]] == [INHALT]
    assert j["quelle"] == "ocr"


# ---------- Bildforensik ----------

def _jpeg_mit_exif(software=None, extra=b"") -> bytes:
    bild = Image.open(io.BytesIO(thermobon()))
    exif = Image.Exif()
    exif[0x010F] = "Apple"
    exif[0x0110] = "iPhone 15"
    if software:
        exif[0x0131] = software
    puffer = io.BytesIO()
    bild.save(puffer, format="JPEG", exif=exif.tobytes())
    return puffer.getvalue() + extra


def test_forensik_unauffaelliges_handyfoto():
    j = client.post("/lesen", files={"datei": ("bon.jpg", _jpeg_mit_exif("18.6"), "image/jpeg")}).json()
    assert j["forensik"]["ki_kennzeichen"] == []
    assert j["forensik"]["bearbeitungssoftware"] == []
    assert j["forensik"]["metadaten"]["modell"] == "iPhone 15"


def test_forensik_photoshop():
    j = client.post("/lesen", files={"datei": ("bon.jpg", _jpeg_mit_exif("Adobe Photoshop 26.1 (Windows)"), "image/jpeg")}).json()
    assert j["forensik"]["bearbeitungssoftware"] == ["photoshop"]


def test_forensik_ki_herkunft():
    xmp = b'<x:xmpmeta><Iptc4xmpExt:DigitalSourceType>http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia</Iptc4xmpExt:DigitalSourceType></x:xmpmeta>'
    j = client.post("/lesen", files={"datei": ("bon.jpg", _jpeg_mit_exif(extra=xmp), "image/jpeg")}).json()
    assert "IPTC-Herkunftsangabe „KI-erzeugt“" in j["forensik"]["ki_kennzeichen"]
