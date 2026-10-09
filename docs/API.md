# Belegcheck API (v1)

REST-Schnittstelle für ERP- und Spesensysteme. Vollständige Beschreibung: [`openapi.yaml`](openapi.yaml)
(auch unter `GET /api/v1/openapi.yaml`, importierbar in Postman, Swagger, SAP API Management, Azure APIM …).

## Grundsätze

- **Hinweisen statt entscheiden:** Ergebnis ist ein Score (0–100), die Abdeckung (0–1), eine Ampel, eine
  Empfehlung (`automatisch_freigeben` | `stichprobe` | `manuell_pruefen`) und die Befunde mit Begründung.
  Es gibt kein „ablehnen“.
- **Score 100 ≠ echt**, sondern „nichts gefunden“. Die **Abdeckung** zeigt, wie viel prüfbar war
  (Beleg mit Kassen-QR-Code und bekanntem Aussteller ≈ 1,0; handschriftlicher Beleg ≈ 0,2).
- **Datenschutz:** Belegdateien werden nach der Prüfung verworfen; es bleiben Ergebnis und Fingerabdrücke
  (für Dubletten), bis `DELETE /pruefungen/{id}`. `einreicher` bitte pseudonym (Personalnummer).

## Zugang einrichten (am Server)

```bash
sudo -u belegcheck -H bash -c 'cd ~/app && php artisan beleg:mandant ubm --name="UBM Development" --webhook=https://erp.example/belegcheck'
sudo -u belegcheck -H bash -c 'cd ~/app && php artisan beleg:api-schluessel ubm --name="SAP Produktion"'
```

Der Schlüssel (`bc_…`) wird nur einmal angezeigt. Widerrufen: `--widerrufen=<präfix>`, Liste: `--liste`.
Einstellungen je Mandant: `--freigabe-ab=90`, `--compliance=gluecksspiel=erlaubt` (Stufen: erlaubt | hinweis | pruefen).

## Beispiele

Synchron (Ergebnis direkt in der Antwort):

```bash
curl -s https://HOST/api/v1/pruefungen \
  -H "Authorization: Bearer bc_…" -H "Idempotency-Key: SP-2026-0815-1" \
  -F datei=@beleg.pdf -F externe_referenz=SP-2026-0815 -F einreicher=MA-17 \
  -F betrag=46,00 -F datum=2025-06-13 -F kategorie=bewirtung
```

Asynchron (sofort `202`, Ergebnis per Webhook oder Abruf):

```bash
curl -si https://HOST/api/v1/pruefungen -H "Authorization: Bearer bc_…" -H "Prefer: respond-async" -F datei=@beleg.jpg
curl -s  https://HOST/api/v1/pruefungen/01JA… -H "Authorization: Bearer bc_…"
```

JSON statt Formular: `{"datei_base64": "…", "dateiname": "beleg.pdf", "betrag": "46,00", …}`.

Entscheidung zurückmelden (verbessert das Kassen-Gedächtnis):

```bash
curl -s https://HOST/api/v1/pruefungen/01JA…/entscheidung -H "Authorization: Bearer bc_…" \
  -H "Content-Type: application/json" -d '{"ergebnis":"in_ordnung"}'
```

## Webhook prüfen

Kopfzeile `Belegcheck-Signatur: t=1760000000,v1=<hex>`; v1 = HMAC-SHA256(Geheimnis, `t + "." + Rohtext`).

```php
[$t, $v1] = sscanf($_SERVER['HTTP_BELEGCHECK_SIGNATUR'], 't=%d,v1=%s');
$ok = abs(time() - $t) < 300 && hash_equals(hash_hmac('sha256', $t.'.'.file_get_contents('php://input'), $geheimnis), $v1);
```

```python
t, v1 = (p.split("=", 1)[1] for p in request.headers["Belegcheck-Signatur"].split(","))
ok = abs(time.time() - int(t)) < 300 and hmac.compare_digest(hmac.new(geheimnis.encode(), f"{t}.".encode() + request.body, "sha256").hexdigest(), v1)
```

Mehrfach zugestellte Ereignisse an `id` erkennen. Ohne 2xx-Antwort: Wiederholung nach 1, 5, 15, 60 Minuten, 6 Stunden.

## Prüfregeln (Auswahl)

| Bereich | Codes |
|---|---|
| Kassen-QR Österreich / Deutschland | `AT-QR-*`, `DE-QR-*`, `DE-TX-01` |
| Kassen-Gedächtnis | `AT-KA-*`, `DE-KA-*` |
| Abgleich mit der Spesenabrechnung | `EA-01` Betrag, `EA-02` Datum |
| Aussteller | `AS-01` UID (VIES), `AS-02` Name zur UID, `AS-03` Lokal (OpenStreetMap), `AS-04` Öffnungszeiten |
| Branche / Compliance | `BR-01` Branche ↔ Kategorie, `CO-01` Aussteller-Kategorie, `CO-02` Positionen |
| Muster | `MU-DU-01..03` Dubletten, `MU-OZ-01` Orte/Zeiten derselben Person |
| Bild | `BF-01..03` |

Compliance-Kategorien (Standard): Erwachsenenunterhaltung, Glücksspiel/Wetten, Gutscheine/Guthabenkarten,
Pfandleihe/Geldtransfer, Waffen → `pruefen`; Tabakwaren → `hinweis`.

## Betrieb

- Asynchrone Prüfungen und Webhooks laufen über `belegcheck-queue.service` (Laravel-Queue in der Datenbank).
- Von außen erreichbar nur über Caddy (HTTPS) und nur der Pfad `/api/*`; die Werkbank bleibt intern:

```
belegcheck.example.at {
    handle /api/* {
        reverse_proxy 127.0.0.1:8095
    }
    respond 404
}
```

- Öffentliche Overpass-Server (OpenStreetMap) sind für geringe Mengen gedacht; bei vielen Belegen eigenen
  Overpass-Server betreiben und `BELEG_OVERPASS_URL` setzen. Screening abschalten: `BELEG_SCREENING=false`.
