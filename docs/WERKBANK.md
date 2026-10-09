# Prüf-Werkbank (Prototyp)

Kleine Weboberfläche zum Prüfen vieler Belege: Dateien oder ganze Ordner wählen bzw. hineinziehen,
Ergebnis je Beleg mit Ampel, Details per Klick, Abgleich mit gedruckten Werten, Export als CSV.
Hochgeladene Dateien werden nicht gespeichert.

Erreichbar nur über SSH-Tunnel (läuft auf 127.0.0.1:8081 am Server, nicht öffentlich).

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
ssh -L 8081:127.0.0.1:8081 BENUTZER@SERVER
```

Fenster offen lassen, im Browser http://localhost:8081 öffnen.
