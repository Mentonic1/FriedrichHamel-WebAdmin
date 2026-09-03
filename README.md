# Friedrich Hamel GmbH – Aufmaß Web-Admin V8

Dieses Repository enthält die Web-/Admin-Erweiterung für die Friedrich-Hamel-Aufmaß-Cloud.

## Wichtiger Freigabe-Ablauf

1. Nutzer stellt einen Lizenzantrag.
2. Im Admin erscheint der Antrag als **offen**.
3. Admin prüft und gibt den Antrag frei.
4. Benutzer und Lizenz-Key werden erzeugt, **aber keine E-Mail wird automatisch versendet**.
5. Erst über den separaten Button **„Lizenz jetzt per E-Mail senden“** wird der Key verschickt.

## Installation

1. Datenbank sichern.
2. `database_setup.sql` in die Datenbank `Aufmass` importieren.
3. PHP-Dateien und `assets/` in den Webroot hochladen.
4. `config.php` bearbeiten: DB-Passwort, APP_URL, SETUP_KEY, APP_SECRET und SMTP-Daten.
5. `setup.php` aufrufen und ersten Superadmin anlegen.
6. Danach `SETUP_ENABLED = false` setzen und `setup.php` möglichst löschen.
7. `admin.php` öffnen und SMTP-Test durchführen.

## Sicherheit

Alle Zugangsdaten in `config.php` sind nur Platzhalter. Niemals echte DB- oder SMTP-Passwörter in dieses öffentliche Repository committen.
