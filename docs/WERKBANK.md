# Prüf-Werkbank (Prototyp)

Kleine Weboberfläche zum Prüfen vieler Belege: Dateien oder ganze Ordner wählen bzw. hineinziehen,
Ergebnis je Beleg mit Ampel, Details per Klick, Abgleich mit gedruckten Werten, Export als CSV.
Hochgeladene Dateien werden nicht gespeichert. Gespeichert wird nur das Kassen-Gedächtnis (Kassen-ID, Lokal, Belegnummer, Zeit, Betrag) aus grünen oder bestätigten Belegen. Leeren: `php artisan beleg:gedaechtnis-leeren`.

Erreichbar nur über SSH-Tunnel (läuft auf 127.0.0.1:8095 am Server, nicht öffentlich).

## Einrichten (einmalig, am Server)

```bash
sudo -u belegcheck -i
cd app && git pull && composer install --no-dev --no-interaction
php artisan migrate --force
exit
sudo cp /srv/belegcheck/app/deploy/belegcheck-web.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now belegcheck-web
```

## Öffnen (am Windows-Rechner)

```powershell
ssh -L 8095:127.0.0.1:8095 BENUTZER@SERVER
```

Fenster offen lassen, im Browser http://localhost:8095 öffnen.

## Regionscheck (Ort/Zeit)

Einmalig das PLZ-Verzeichnis laden (GeoNames, CC BY 4.0, ca. 30 MB Download, danach offline):

```bash
sudo -u belegcheck php /srv/belegcheck/app/artisan beleg:plz-import
```

In der Werkbank gilt ein Upload-Stapel als Belege einer Person. Liegen zwei Belege zeitlich zu nah für ihre
Entfernung (z. B. Wien und München am selben Abend), werden beide gelb (Regel MU-OZ-01).

## Texterkennung und Sprachmodell

- **Tesseract mit `tessdata_best`** (genauere Modelle, wie beim Bescheidwisser): wird automatisch genutzt, wenn
  `deu.traineddata` in `BELEGLESER_TESSDATA`, `/opt/belegcheck/tessdata_best` oder `/opt/fristwerk/erkennung/tessdata_best` liegt.
- **Zweitlesung mit RapidOCR** (PaddleOCR-Modelle über ONNX, lokal, Modelle im pip-Paket): andere Technik als Tesseract,
  liest Ziffern auf Thermobons deutlich zuverlässiger. Läuft parallel; fehlt das Paket, entfällt sie ohne Fehler.
- **Lokales Sprachmodell** (`BELEG_SPRACHMODELL_URL`, z. B. das Qwen des Bescheidwissers auf `http://127.0.0.1:8081`):
  liest aus beiden Texterkennungen Summe, Datum, Steuersätze und Aussteller. Nur Adressen auf diesem Rechner sind erlaubt.
  Gefragt wird nur, wenn Werte fehlen oder nicht zum QR-Code passen (`BELEG_SPRACHMODELL_IMMER=true`: immer).
  Schutzregeln: Ein KI-Wert zählt nur, wenn er wörtlich im erkannten Text steht. Er bestätigt den QR-Wert oder füllt eine
  Lücke mit Lesesicherheit 0,7 – eine Abweichung ergibt dadurch höchstens einen Hinweis, nie einen Widerspruch.
  In der Werkbank sind solche Werte mit „KI“ markiert.
