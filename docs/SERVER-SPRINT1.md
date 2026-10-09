# Sprint 1 auf dem IPAX-Server einrichten

Ziel: Am Ende läuft auf dem Server `php artisan beleg:pruefe beleg.jpg` und liefert eine Ampel.
Noch keine Webseite, kein Caddy-Eintrag – das kommt in Sprint 4.

Dauer: ca. 30–45 Minuten. Bescheidwisser wird nicht berührt.

Konventionen:
- Befehle in grauen Kästen **einzeln** kopieren und mit Enter ausführen.
- `PASSWORT_HIER` jeweils durch ein eigenes, langes Passwort ersetzen (z. B. aus dem Passwortmanager).
- Wenn ein Befehl eine Fehlermeldung bringt: stoppen und die Ausgabe in den Chat kopieren.

---

## Schritt 1 – Anmelden

In der **PowerShell** auf deinem Windows-Rechner, wie bei Bescheidwisser:

```powershell
ssh DEIN_BENUTZER@DEIN_SERVER
```

Alle weiteren Befehle laufen auf dem Server.

## Schritt 2 – Prüfen, was schon da ist

```bash
php -v
psql --version
python3 --version
composer -V
git --version
```

Erwartet: PHP 8.3 oder neuer, PostgreSQL, Python 3.12 oder neuer, Composer, Git.
Fehlt etwas, Ausgabe in den Chat kopieren.

Zusätzlich die PHP-Erweiterungen für Postgres und Verschlüsselung prüfen:

```bash
php -m | grep -E "pdo_pgsql|sodium|mbstring"
```

Erwartet: drei Zeilen. Fehlt `pdo_pgsql`:

```bash
sudo apt install -y php-pgsql
```

## Schritt 3 – Eigenen Linux-Benutzer anlegen

Beleg-Check läuft unter einem eigenen Benutzer, getrennt von Bescheidwisser.

```bash
sudo adduser --system --group --home /srv/belegcheck --shell /bin/bash belegcheck
```

## Schritt 4 – Eigene Datenbank anlegen

```bash
sudo -u postgres psql
```

Die Eingabezeile ändert sich zu `postgres=#`. Dort nacheinander:

```sql
CREATE USER belegcheck WITH PASSWORD 'PASSWORT_HIER';
CREATE DATABASE belegcheck OWNER belegcheck ENCODING 'UTF8';
REVOKE ALL ON DATABASE belegcheck FROM PUBLIC;
\q
```

Das Passwort für Schritt 7 notieren.

## Schritt 5 – Zugriff auf das GitHub-Repo (Deploy-Key)

Der Server bekommt einen eigenen Schlüssel, der **nur dieses eine Repo lesen** darf.

```bash
sudo -u belegcheck mkdir -p -m 700 /srv/belegcheck/.ssh
sudo -u belegcheck ssh-keygen -t ed25519 -C "belegcheck@ipax" -f /srv/belegcheck/.ssh/id_ed25519 -N ""
sudo cat /srv/belegcheck/.ssh/id_ed25519.pub
```

Die ausgegebene Zeile (beginnt mit `ssh-ed25519`) kopieren, dann im Browser:

1. github.com/digitalfex/belegcheck → **Settings** → **Deploy keys** → **Add deploy key**
2. Title: `IPAX-Server`, Key: die kopierte Zeile, **Allow write access: nicht anhaken**
3. **Add key**

## Schritt 6 – Code holen und installieren

```bash
sudo -u belegcheck -i
```

Die Eingabezeile zeigt jetzt `belegcheck@…`. Ab hier alles als dieser Benutzer:

```bash
ssh -o StrictHostKeyChecking=accept-new -T git@github.com
```

Erwartet: „Hi digitalfex/belegcheck! You've successfully authenticated …“ (die Meldung „does not provide shell access“ ist richtig).

```bash
git clone git@github.com:digitalfex/belegcheck.git app
cd app
composer install --no-dev --no-interaction
cp .env.example .env
php artisan key:generate
```

## Schritt 7 – Datenbank-Passwort eintragen

```bash
nano .env
```

Mit den Pfeiltasten zur Zeile `DB_PASSWORD=` gehen und das Passwort aus Schritt 4 dahinter schreiben. Außerdem setzen:

```
APP_ENV=production
APP_DEBUG=false
```

Speichern: **Strg+O**, Enter, **Strg+X**.

Verbindung testen:

```bash
php artisan db:show
```

Erwartet: eine Tabelle mit `pgsql` und `belegcheck`.

## Schritt 8 – Belegleser (Python) einrichten

```bash
cd ~/app/belegleser
python3 -m venv .venv
.venv/bin/pip install --upgrade pip
.venv/bin/pip install -r requirements.txt
.venv/bin/python -m pytest -q
```

Erwartet am Ende: `4 passed`.

Meldet `python3 -m venv` einen Fehler wie „ensurepip is not available“: `exit`, dann `sudo apt install -y python3-venv` und Schritt 8 wiederholen.

```bash
exit
```

(zurück zu deinem eigenen Benutzer)

## Schritt 9 – Belegleser als Dienst starten

Der Pfad im Code wird angepasst, weil wir nach `/srv/belegcheck/app` geklont haben:

```bash
sudo sed 's#/srv/belegcheck/belegleser#/srv/belegcheck/app/belegleser#g' /srv/belegcheck/app/deploy/belegleser.service | sudo tee /etc/systemd/system/belegleser.service
sudo systemctl daemon-reload
sudo systemctl enable --now belegleser
sudo systemctl status belegleser --no-pager
```

Erwartet: `active (running)`. Dann:

```bash
curl -s http://127.0.0.1:8090/gesund
```

Erwartet: `{"status":"ok","version":"0.1.0"}`.

Port 8090 ist nur intern erreichbar (127.0.0.1), es muss keine Firewall geöffnet werden.

## Schritt 10 – Erster echter Beleg

Ein Foto eines österreichischen Kassenbons vom Windows-Rechner auf den Server kopieren (in einer **neuen** PowerShell auf dem Windows-Rechner):

```powershell
scp C:\Users\DEIN_NAME\Downloads\bon1.jpg DEIN_BENUTZER@DEIN_SERVER:/tmp/
```

Auf dem Server:

```bash
sudo -u belegcheck php /srv/belegcheck/app/artisan beleg:pruefe /tmp/bon1.jpg
```

Erwartet: Tabelle mit Kassen-ID, Belegnummer, Datum, Summe und den Prüfergebnissen.
Die Ausgabe bitte in den Chat kopieren – damit prüfen wir das Dezimalkomma und das Feldformat an echten Belegen (offener Punkt der Spezifikation).

Mit gedruckten Werten vergleichen (Sprint 1: von Hand, ab Sprint 2 automatisch):

```bash
echo '{"gesamt_cent": 4420, "datum_uhrzeit": "2026-10-08 19:42", "kassen_id": "KASSE-01"}' > /tmp/soll.json
sudo -u belegcheck php /srv/belegcheck/app/artisan beleg:pruefe /tmp/bon1.jpg --gedruckt=/tmp/soll.json
```

Werte im JSON durch die gedruckten Werte des Bons ersetzen (Betrag in Cent: 44,20 € = 4420).

Danach Testbild löschen:

```bash
rm /tmp/bon1.jpg /tmp/soll.json
```

## Später aktualisieren

```bash
sudo -u belegcheck -i
cd app && git pull && composer install --no-dev --no-interaction
belegleser/.venv/bin/pip install -r belegleser/requirements.txt
exit
sudo systemctl restart belegleser
```
