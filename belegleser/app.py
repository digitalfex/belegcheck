"""
Belegleser v0 – interner Dienst für Beleg-Check.

Sprint 1: QR-Codes aus Fotos und PDFs lesen.
Läuft nur auf 127.0.0.1, wird von der Laravel-App aufgerufen.

Start (Entwicklung):  uvicorn app:app --host 127.0.0.1 --port 8090
"""

from __future__ import annotations

import io

import pytesseract
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


def _scanbild(seite) -> Image.Image | None:
    """
    Gescannte PDF-Seite (Scanner-App wie SwiftScan, Adobe Scan …): genau ein Bild, das die ganze Seite füllt.
    Dann das eingebettete Bild in Originalauflösung nehmen statt neu zu rendern – Hochrechnen auf 300 dpi
    vergrößert nur das Scan-Raster und verschlechtert QR- und Texterkennung.
    """
    bilder = [o for o in seite.get_objects() if o.type == 3]  # FPDF_PAGEOBJ_IMAGE
    if len(bilder) != 1 or seite.get_rotation():
        return None
    breite, hoehe = seite.get_size()
    links, unten, rechts, oben = bilder[0].get_bounds()
    if (rechts - links) * (oben - unten) < 0.9 * breite * hoehe:
        return None
    try:
        bild = bilder[0].get_bitmap(render=False).to_pil()
    except Exception:  # noqa: BLE001 – exotische Bildformate: normal rendern
        return None
    if abs(bild.width / bild.height - breite / hoehe) > 0.03 * breite / hoehe:
        return None  # gedreht/gespiegelt eingebettet
    return bild


def _pdf_seiten(daten: bytes) -> list[dict]:
    """Je Seite: Bild, ob es ein Scan ist, und die Textschicht."""
    import pypdfium2 as pdfium

    seiten = []
    for seite in pdfium.PdfDocument(daten):
        scan = _scanbild(seite)
        seiten.append({
            "bild": scan if scan is not None else seite.render(scale=300 / 72).to_pil(),
            "scan": scan is not None,
            "text": seite.get_textpage().get_text_range(),
        })
    return seiten


def _bilder_aus_datei(daten: bytes, name: str) -> list[Image.Image]:
    """Liefert eine Liste von Bildern (PDF: eine Seite je Bild)."""
    if name.lower().endswith(".pdf") or daten[:4] == b"%PDF":
        return [s["bild"] for s in _pdf_seiten(daten)]

    bild = Image.open(io.BytesIO(daten))
    return [ImageOps.exif_transpose(bild)]  # Handyfotos richtig drehen


def pdf_textschicht(seiten: list[dict]) -> list[str] | None:
    """
    Digitale PDF-Belege (z. B. E-Mail-Rechnungen) haben eine Textschicht – die ist genauer als jede Texterkennung.
    Bei Scans stammt die Textschicht von der Scanner-App (deren eigene, oft schwache Texterkennung) → nicht verwenden.
    """
    if not seiten or any(s["scan"] for s in seiten):
        return None
    texte = [s["text"] for s in seiten]
    return texte if sum(len(t.strip()) for t in texte) > 30 else None


# ---------- Bildforensik (Schicht 4, Basis) ----------

# Kennzeichnungen, mit denen KI-Bildgeneratoren ihre Bilder markieren (Metadaten, nicht Pixel)
KI_KENNUNGEN = {
    b"trainedAlgorithmicMedia": "IPTC-Herkunftsangabe „KI-erzeugt“",
    b"compositeWithTrainedAlgorithmicMedia": "IPTC-Herkunftsangabe „mit KI bearbeitet“",
    b"c2pa": "C2PA-Herkunftsnachweis (Content Credentials)",
    b"Stable Diffusion": "Stable-Diffusion-Parameter",
    b"\"prompt\"": "Generierungs-Prompt in den Metadaten",
    b"Midjourney": "Midjourney-Kennung",
    b"DALL-E": "DALL-E-Kennung",
    b"Firefly": "Adobe-Firefly-Kennung",
    b"Imagen": "Google-Imagen-Kennung",
}

BEARBEITUNGS_SOFTWARE = ("photoshop", "gimp", "affinity", "pixelmator", "canva", "paint.net", "lightroom",
                         "picsart", "fotor", "photopea", "illustrator", "inkscape", "krita")


def vorschau(bild: Image.Image, max_hoehe: int = 4000) -> str:
    """Originalansicht für die Werkbank (JPEG, base64) – funktioniert auch für HEIC und PDF."""
    import base64

    kopie = bild.convert("RGB")
    kopie.thumbnail((1400, max_hoehe))  # lange Bons nicht stauchen: Breite bestimmt die Lesbarkeit
    puffer = io.BytesIO()
    kopie.save(puffer, format="JPEG", quality=82)
    return base64.b64encode(puffer.getvalue()).decode()


def forensik(daten: bytes, bilder: list[Image.Image]) -> dict:
    """Metadaten-Prüfung. Bewusst nur Merkmale mit wenig Fehlalarmen; fehlende Metadaten sind kein Signal."""
    ki = sorted({text for kennung, text in KI_KENNUNGEN.items() if kennung in daten})
    # C2PA allein bedeutet nicht KI (auch Kameras signieren) – nur zusammen mit KI-Herkunft werten
    if ki == ["C2PA-Herkunftsnachweis (Content Credentials)"]:
        ki = []

    exif: dict = {}
    software = None
    if bilder and daten[:4] != b"%PDF":
        roh = bilder[0].getexif()
        if roh:
            unter = roh.get_ifd(0x8769)  # Exif-IFD
            exif = {
                "hersteller": roh.get(0x010F),
                "modell": roh.get(0x0110),
                "software": roh.get(0x0131),
                "aufnahme": unter.get(0x9003) or roh.get(0x0132),  # DateTimeOriginal, sonst DateTime
            }
            exif = {k: str(v).strip("\x00 ").strip() for k, v in exif.items() if v}
            software = exif.get("software")
    elif daten[:4] == b"%PDF":
        import pypdfium2 as pdfium

        meta = pdfium.PdfDocument(daten).get_metadata_dict()
        software = " / ".join(v for k, v in meta.items() if k in ("Creator", "Producer") and v) or None
        exif = {"software": software} if software else {}

    bearbeitung = [s for s in BEARBEITUNGS_SOFTWARE if software and s in software.lower()]
    return {"ki_kennzeichen": ki, "metadaten": exif, "bearbeitungssoftware": bearbeitung}


def _varianten(bild: Image.Image):
    """Mehrere Aufbereitungen, weil Thermopapier oft blass oder verzogen ist."""
    grau = bild.convert("L")
    yield grau
    yield ImageOps.autocontrast(grau, cutoff=2)
    # kleine QR-Codes auf großen Fotos: vergrößern hilft dem Decoder
    if max(grau.size) < 2000:
        yield grau.resize((grau.width * 2, grau.height * 2), Image.LANCZOS)
    yield grau.point(lambda p: 255 if p > 140 else 0)  # harte Schwelle


def _qr_varianten_gruendlich(bild: Image.Image):
    """
    Zweite Stufe für Scans mit Raster-/Punktmuster (Scanner-Apps schärfen und rastern blasse Thermobons):
    vergrößern und weichzeichnen, damit aus dem Punktmuster wieder geschlossene QR-Module werden.
    """
    from PIL import ImageFilter

    grau = bild.convert("L")
    for faktor in (1.5, 2, 2.5, 3):
        if max(grau.size) * faktor > 9000:
            break
        gross = grau.resize((int(grau.width * faktor), int(grau.height * faktor)), Image.LANCZOS)
        for kontrast in (False, True):
            basis = ImageOps.autocontrast(gross, cutoff=2) if kontrast else gross
            yield basis.filter(ImageFilter.GaussianBlur(faktor))


def qr_codes_lesen(bild: Image.Image) -> list[dict]:
    gefunden: dict[str, dict] = {}

    def sammle(codes) -> bool:
        for code in codes:
            if code.text and code.text not in gefunden:
                gefunden[code.text] = {"text": code.text, "format": str(code.format).split(".")[-1]}
        return any("QR" in c["format"] for c in gefunden.values())

    for variante in _varianten(bild):
        if sammle(zxingcpp.read_barcodes(variante)):
            return list(gefunden.values())

    # Stufe 2 nur, wenn noch kein QR-Code gefunden wurde
    qr = zxingcpp.BarcodeFormat.QRCode
    for variante in _qr_varianten_gruendlich(bild):
        for binarisierung in (zxingcpp.Binarizer.GlobalHistogram, zxingcpp.Binarizer.LocalAverage):
            if sammle(zxingcpp.read_barcodes(variante, formats=qr, binarizer=binarisierung)):
                return list(gefunden.values())
    return list(gefunden.values())


def _schraeglage(grau: Image.Image) -> float:
    """Schätzt die Schräglage (Grad) über das Zeilenprofil: bei richtigem Winkel sind die Zeilen am schärfsten."""
    klein = grau.copy()
    klein.thumbnail((600, 600))
    sw = klein.point(lambda p: 255 if p < 128 else 0)  # Text weiß auf schwarz
    bester, beste_schaerfe = 0.0, -1.0
    for zehntel in range(-60, 61, 5):  # -6° … +6° in 0,5°-Schritten
        gedreht = sw.rotate(zehntel / 10, resample=Image.NEAREST, fillcolor=0)
        breite = gedreht.width
        daten = gedreht.tobytes()
        zeilen = [sum(daten[y * breite:(y + 1) * breite]) for y in range(gedreht.height)]
        schaerfe = sum((zeilen[i + 1] - zeilen[i]) ** 2 for i in range(len(zeilen) - 1))
        if schaerfe > beste_schaerfe:
            bester, beste_schaerfe = zehntel / 10, schaerfe
    return bester


def _gerade(bild: Image.Image) -> Image.Image:
    """Graustufen, Kontrast, Schräglage korrigieren."""
    grau = ImageOps.autocontrast(bild.convert("L"), cutoff=1)
    winkel = _schraeglage(grau)
    if abs(winkel) >= 0.5:
        grau = grau.rotate(winkel, resample=Image.BICUBIC, expand=True, fillcolor=255)
    return grau


def _auf_hoehe(grau: Image.Image, hoehe: int) -> Image.Image:
    if grau.height < hoehe:
        faktor = hoehe / grau.height
        grau = grau.resize((int(grau.width * faktor), hoehe), Image.LANCZOS)
    return grau


def _ocr_varianten(bild: Image.Image):
    """Drei Aufbereitungen – Thermopapier ist oft blass, ungleichmäßig belichtet oder klein fotografiert."""
    from PIL import ImageChops, ImageFilter

    grau = _gerade(bild)
    # A: Standard
    yield "standard", _auf_hoehe(grau, 1800)
    # B: lokale Schwelle gegen ungleichmäßige Beleuchtung und Schatten
    hintergrund = grau.filter(ImageFilter.GaussianBlur(25))
    differenz = ImageChops.subtract(hintergrund, grau)  # Text = dunkler als Umgebung
    yield "schwelle", _auf_hoehe(differenz.point(lambda p: 0 if p > 18 else 255), 1800)
    # C: größer und geschärft für kleine oder unscharfe Schrift
    yield "gross", _auf_hoehe(grau, 2600).filter(ImageFilter.UnsharpMask(radius=2, percent=150, threshold=3))


def _ocr(bild: Image.Image) -> list[dict]:
    daten = pytesseract.image_to_data(bild, lang="deu+eng", config="--psm 4", output_type=pytesseract.Output.DICT)
    zeilen: dict[tuple, dict] = {}
    for i, wort in enumerate(daten["text"]):
        wort = wort.strip()
        conf = float(daten["conf"][i])
        if not wort or conf < 0:
            continue
        schluessel = (daten["block_num"][i], daten["par_num"][i], daten["line_num"][i])
        z = zeilen.setdefault(schluessel, {"woerter": [], "conf": []})
        z["woerter"].append(wort)
        z["conf"].append(conf)
    return [
        {"text": " ".join(z["woerter"]), "sicherheit": round(sum(z["conf"]) / len(z["conf"]) / 100, 2)}
        for z in zeilen.values()
    ]


def text_lesen(bild: Image.Image) -> dict:
    """
    Texterkennung in mehreren Varianten. Hauptergebnis = Variante mit der höchsten Lesesicherheit;
    die anderen Varianten werden als „alternativen“ mitgeliefert, damit die Prüfung einen QR-Wert
    bestätigen kann, wenn er in irgendeiner Variante richtig gelesen wurde.
    """
    from concurrent.futures import ThreadPoolExecutor

    varianten = list(_ocr_varianten(bild))
    # Tesseract läuft als eigener Prozess je Variante → parallel
    with ThreadPoolExecutor(max_workers=len(varianten)) as pool:
        ergebnisse = list(pool.map(lambda v: (v[0], _ocr(v[1])), varianten))

    laeufe = []
    for name, zeilen in ergebnisse:
        if zeilen:
            schnitt = sum(z["sicherheit"] for z in zeilen) / len(zeilen)
            laeufe.append((schnitt, name, zeilen))
    if not laeufe:
        return {"text": "", "zeilen": [], "sicherheit": 0.0, "alternativen": [], "variante": None}

    laeufe.sort(key=lambda l: l[0], reverse=True)
    beste = laeufe[0]
    return {
        "text": "\n".join(z["text"] for z in beste[2]),
        "zeilen": beste[2],
        "sicherheit": round(beste[0], 2),
        "variante": beste[1],
        "alternativen": [z for _, _, zeilen in laeufe[1:] for z in zeilen],
    }


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


@app.post("/lesen")
async def lesen(datei: UploadFile = File(...)) -> dict:
    """QR-Codes und Text in einem Durchgang (Sprint 2)."""
    daten = await datei.read()
    if len(daten) > MAX_BYTES:
        raise HTTPException(413, "Datei größer als 20 MB")
    try:
        if daten[:4] == b"%PDF" or (datei.filename or "").lower().endswith(".pdf"):
            pdf_seiten = _pdf_seiten(daten)
            bilder = [s["bild"] for s in pdf_seiten]
        else:
            pdf_seiten = []
            bilder = _bilder_aus_datei(daten, datei.filename or "")
    except Exception as e:  # noqa: BLE001
        raise HTTPException(422, f"Datei nicht lesbar: {e}") from e

    textschicht = pdf_textschicht(pdf_seiten)

    codes: list[dict] = []
    seiten: list[dict] = []
    for nr, bild in enumerate(bilder, start=1):
        for c in qr_codes_lesen(bild):
            codes.append({**c, "seite": nr})
        if textschicht is not None:
            zeilen = [z.strip() for z in textschicht[nr - 1].splitlines() if z.strip()]
            seiten.append({"seite": nr, "text": "\n".join(zeilen),
                           "zeilen": [{"text": z, "sicherheit": 1.0} for z in zeilen], "sicherheit": 1.0})
        else:
            seiten.append({"seite": nr, **text_lesen(bild)})

    return {
        "codes": codes,
        "seiten": len(bilder),
        "text": "\n".join(s["text"] for s in seiten),
        "zeilen": [z for s in seiten for z in s["zeilen"]],
        "alternativen": [z for s in seiten for z in s.get("alternativen", [])],
        "sicherheit": round(sum(s["sicherheit"] for s in seiten) / len(seiten), 2) if seiten else 0.0,
        "quelle": "pdf-text" if textschicht is not None else "ocr",
        "forensik": forensik(daten, bilder),
        "vorschau": [vorschau(b) for b in bilder[:3]],
    }
