# Beleg-Check by DIGITALFEX (Arbeitstitel)

Prüft Spesenbelege auf Echtheitsmerkmale: Kassen-QR-Code (RKSV / DSFinV-K), Plausibilität, Muster.
Das System markiert, der Mensch entscheidet – keine automatische Ablehnung, keine Vorwürfe.

Spezifikation: https://claude.ai/code/artifact/13bfe3d4-56ab-4bbc-8fc2-c82eebd0754b

## Aufbau

| Teil | Ort | Aufgabe |
| --- | --- | --- |
| Laravel-App | `app/` | Prüfregeln, Risikowert, Befehle, später Oberfläche |
| Prüfregeln Schicht 1 AT | `app/Fiskal/Rksv/` | RKSV-QR zerlegen (`RksvParser`) und prüfen (`RksvPruefer`) |
| Risikowert | `app/Pruefung/` | Stufen, Punkte, Ampel |
| Fingerabdrücke | `app/Hashes/` | Datei-, Inhalts- und Text-Hash gegen Doppel-Einreichungen |
| Belegleser | `belegleser/` | Python-Dienst: QR aus Foto/PDF (später Texterkennung) |

## Stand Sprint 1

- [x] RKSV-Parser mit Tests (gültig, kaputt, Training, Storno, Startbeleg, Ausfall, Feldlängen)
- [x] Regeln AT-QR-02, -03, -08 bis -11; Abgleich mit gedruckten Werten AT-QR-04 bis -07
- [x] Risikowert und Ampel
- [x] Inhalts-Hash und Text-Hash (SimHash) für Doppelfotos
- [x] Belegleser v0: QR aus JPG/PNG/HEIC/PDF
- [x] Befehl `php artisan beleg:pruefe`

## Lokal testen

```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan test

cd belegleser
python3 -m venv .venv && .venv/bin/pip install -r requirements.txt
.venv/bin/python -m pytest -q
.venv/bin/uvicorn app:app --host 127.0.0.1 --port 8090
```

```bash
php artisan beleg:pruefe pfad/zum/bon.jpg
php artisan beleg:pruefe --qr="_R1-AT1_…"
php artisan beleg:pruefe bon.jpg --gedruckt=soll.json --json
```

## Server

Schritt-für-Schritt-Einrichtung: [docs/SERVER-SPRINT1.md](docs/SERVER-SPRINT1.md)
