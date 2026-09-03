FRIEDRICH HAMEL GMBH - AUFMASS WEB-ADMIN V8
============================================

ZIEL
----
Dieses Paket ergänzt die bestehende V7-Aufmaß-App um eine öffentliche Zugangsseite
und einen zentralen Web-Admin. Die Daten werden in denselben Cloud-Tabellen wie die
V7-App verwaltet.

WICHTIGER FREIGABE-ABLAUF
-------------------------
1. Nutzer stellt auf index.php einen Lizenzantrag.
2. Im Admin erscheint der Antrag als OFFEN.
3. Admin prüft und klickt "Antrag freigeben".
4. Dabei werden Benutzer + Lizenz-Key erzeugt, ABER KEINE E-MAIL versendet.
5. Erst in der Lizenzansicht klickt der Admin separat auf
   "Lizenz jetzt per E-Mail senden".

Es gibt also KEINEN automatischen Lizenzversand vor oder direkt bei der Freigabe.

INSTALLATION
------------
1. Backup der Datenbank erstellen.
2. database_setup.sql in der Datenbank "Aufmass" importieren.
3. Alle PHP-Dateien + assets-Ordner in den Webroot hochladen.
4. config.php bearbeiten:
   - DB_PASS
   - APP_URL
   - SETUP_KEY
   - APP_SECRET (64 Hex-Zeichen)
   - SMTP_HOST / SMTP_PORT / SMTP_USER / SMTP_PASS / SMTP_ENCRYPTION
5. Im Browser öffnen:
   https://DEINE-DOMAIN/setup.php
6. Ersten Superadmin anlegen.
7. Danach in config.php unbedingt:
   SETUP_ENABLED = false
   und setup.php möglichst vom Server löschen.
8. Admin öffnen:
   https://DEINE-DOMAIN/admin.php
9. Unter "E-Mail" einen SMTP-Test durchführen.

APP_SECRET ERZEUGEN
-------------------
Auf einem Rechner mit PHP:
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"

Der APP_SECRET muss danach unverändert bleiben. Er verschlüsselt die noch nicht
versendeten Lizenz-Keys im Server. In aufmass_cloud_lizenzen selbst bleibt weiterhin
nur der SHA-256-Hash, den die App zur Anmeldung nutzt.

DATEIEN
-------
index.php        öffentliche Website + Lizenzantrag
admin.php        kompletter Adminbereich
setup.php        einmalige Superadmin-Einrichtung
config.php       DB-, SMTP- und Sicherheitskonfiguration
db.php           PDO-Verbindung
lib.php          Auth, CSRF, Lizenzlogik, Audit
mailer.php       SMTP-Mailversand + Lizenzmail
lizenz_api.php   V7-kompatible Cloud-API (gleiche Datenbank)
database_setup.sql  Tabellen / Migration
assets/style.css Design
.htaccess        Basisschutz für Apache

ADMIN-FUNKTIONEN
----------------
- Dashboard
- offene / freigegebene / abgelehnte Lizenzanträge
- manuelle Lizenzfreigabe
- separater manueller Lizenz-Mailversand
- Benutzer bearbeiten, sperren/aktivieren
- Gerätezahl konfigurieren
- Lizenzen sperren/aktivieren
- Laufzeit ändern
- Lizenz-Key neu erzeugen
- Geräte sperren, aktivieren, entfernen
- Cloud-Projekte anzeigen
- individuelle E-Mails an Nutzer
- SMTP-Test
- Mailprotokoll
- Audit-/Adminprotokoll
- zusätzliche Admin-Konten

SICHERHEIT
----------
- Admin-Passwörter über password_hash()
- CSRF-Schutz auf schreibenden Aktionen
- Prepared Statements / PDO
- Session-ID-Wechsel beim Login
- Login-Rate-Limit
- Lizenz-Key serverseitig SHA-256 für die App und zusätzlich verschlüsselt nur für
  den kontrollierten manuellen Versand gespeichert
- Keine automatische Lizenzmail
- Geräte können bei Key-Wechsel automatisch gesperrt werden

HINWEIS ZU SMTP
---------------
Der eingebaute SMTP-Client unterstützt AUTH LOGIN sowie STARTTLS (tls) und
SMTPS (ssl). Bei Microsoft 365 / Google Workspace können je nach Tenant zusätzliche
SMTP-Authentifizierungsregeln oder App-Passwörter erforderlich sein.

ZENTRALER KATALOG
-----------------
Im Admin-Menü "Katalog" können Kategorien, Materialien und globale Raum-Master
bearbeitet werden. Neue Kategorien erzeugen automatisch eine gleichnamige
Raum-Vorlage. Die Desktop-App lädt diesen zentralen Katalog über lizenz_api.php.

MANUELLE NUTZERANLAGE
---------------------
Unter "Nutzer" -> "+ Nutzer + Lizenz" kann ein Benutzer auch ohne öffentlichen
Antrag direkt angelegt werden. Auch dabei wird KEINE Lizenz-Mail automatisch
verschickt. Der Versand bleibt ein separater Admin-Schritt.

DESKTOP-APP
-----------
Im Unterordner desktop_app liegt die dazu passende V7-Desktop-App als Referenz.
Diesen Ordner NICHT in den Webroot hochladen. Die App verwendet weiterhin:
https://DEINE-DOMAIN/lizenz_api.php
