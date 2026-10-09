"""
Belegleser v0 – interner Dienst für Beleg-Check.

Sprint 1: QR-Codes aus Fotos und PDFs lesen.
Läuft nur auf 127.0.0.1, wird von der Laravel-App aufgerufen.

Start (Entwicklung):  uvicorn app:app --host 127.0.0.1 --port 8090
"""

from __future__ import annotations

import io

import zxingcpp
from fastapi import FastAPI, File, HTTPException, UploadFile
from PIL import Image, ImageOps

try:
    from pillow_heif import register_heif_opener  # iPhone-Fotos (HEIC)

    register_heif_opener()
except ImportError:  # optional
    pass

app = FastAPI(title="Belegleser", version="0.1.0")

MAX_BYTES = 20 * 1024 * 1024


def _bilder_aus_datei(daten: bytes, name: str) -> list[Image.Image]:
    """Liefert eine Liste von Bildern (PDF: eine Seite je Bild, 300 dpi)."""
    if name.lower().endswith(".pdf") or daten[:4] == b"%PDF":
        import pypdfium2 as pdfium

        pdf = pdfium.PdfDocument(daten)
        return [seite.render(scale=300 / 72).to_pil() for seite in pdf]

    bild = Image.open(io.BytesIO(daten))
    return [ImageOps.exif_transpose(bild)]  # Handyfotos richtig drehen


def _varianten(bild: Image.Image):
    """Mehrere Aufbereitungen, weil Thermopapier oft blass oder verzogen ist."""
    grau = bild.convert("L")
    yield grau
    yield ImageOps.autocontrast(grau, cutoff=2)
    # kleine QR-Codes auf großen Fotos: vergrößern hilft dem Decoder
    if max(grau.size) < 2000:
        yield grau.resize((grau.width * 2, grau.height * 2), Image.LANCZOS)
    yield grau.point(lambda p: 255 if p > 140 else 0)  # harte Schwelle


def qr_codes_lesen(bild: Image.Image) -> list[dict]:
    gefunden: dict[str, dict] = {}
    for variante in _varianten(bild):
        for code in zxingcpp.read_barcodes(variante):
            if code.text and code.text not in gefunden:
                gefunden[code.text] = {"text": code.text, "format": str(code.format).split(".")[-1]}
        if gefunden:
            break
    return list(gefunden.values())


@app.get("/gesund")
def gesund() -> dict:
    return {"status": "ok", "version": app.version}


@app.post("/qr")
async def qr(datei: UploadFile = File(...)) -> dict:
    daten = await datei.read()
    if len(daten) > MAX_BYTES:
        raise HTTPException(413, "Datei größer als 20 MB")
    try:
        bilder = _bilder_aus_datei(daten, datei.filename or "")
    except Exception as e:  # noqa: BLE001 – Fehler an die App zurückgeben
        raise HTTPException(422, f"Datei nicht lesbar: {e}") from e

    codes: list[dict] = []
    for seite, bild in enumerate(bilder, start=1):
        for c in qr_codes_lesen(bild):
            codes.append({**c, "seite": seite})
    return {"codes": codes, "seiten": len(bilder)}
