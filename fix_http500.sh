#!/usr/bin/env bash
set -euo pipefail

DIR="${1:-.}"
cd "$DIR"

echo "=== Friedrich Hamel WebAdmin: HTTP-500-Kompatibilitaetsfix ==="
echo "Verzeichnis: $(pwd)"

for f in setup.php lib.php config.php; do
  if [ ! -f "$f" ]; then
    echo "FEHLER: $f wurde in $(pwd) nicht gefunden."
    echo "Starte das Script im WebAdmin-Ordner oder uebergib den Pfad als Argument."
    exit 1
  fi
done

STAMP="$(date +%Y%m%d_%H%M%S)"
mkdir -p ".backup_http500_$STAMP"
for f in .htaccess setup.php lib.php lizenz_api.php mailer.php; do
  [ -f "$f" ] && cp -a "$f" ".backup_http500_$STAMP/$f"
done

# Manche Apache-Konfigurationen erlauben 'Options -Indexes' nicht in .htaccess.
# Das kann noch vor dem Start von PHP einen HTTP 500 ausloesen.
if [ -f .htaccess ]; then
  mv .htaccess ".htaccess.disabled_$STAMP"
  echo "OK: .htaccess voruebergehend deaktiviert."
fi

# 'never' als Rueckgabetyp gibt es erst ab PHP 8.1.
# 'void' funktioniert auch auf PHP 7.4/8.0 und ist hier funktional ausreichend.
for f in lib.php lizenz_api.php; do
  if [ -f "$f" ]; then
    sed -i 's/): never/): void/g' "$f"
  fi
done

# PHP-8-Funktion vermeiden, damit der Mailer auch auf aelteren Installationen startet.
if [ -f mailer.php ]; then
  sed -i "s/str_contains(SMTP_HOST, 'example\.')/strpos(SMTP_HOST, 'example.') !== false/g" mailer.php || true
fi

# Catch ohne Variable wurde erst mit PHP 8 eingefuehrt.
for f in lib.php lizenz_api.php; do
  if [ -f "$f" ]; then
    sed -i 's/catch (Throwable) {/catch (Throwable $e) {/g' "$f"
  fi
done

echo
echo "PHP-Version:"
php -v | head -n 1 || true

echo
echo "PHP-Syntaxpruefung:"
FAILED=0
for f in *.php; do
  if ! php -l "$f"; then
    FAILED=1
  fi
done

echo
echo "Wichtige PHP-Erweiterungen:"
php -r '
$mods=["pdo_mysql","openssl","mbstring","json"];
foreach($mods as $m){echo $m.": ".(extension_loaded($m)?"OK":"FEHLT").PHP_EOL;}
' || true

echo
if [ "$FAILED" -eq 0 ]; then
  echo "Fix abgeschlossen. Alle PHP-Dateien sind syntaktisch gueltig."
  echo "Rufe setup.php jetzt erneut im Browser auf."
else
  echo "Mindestens eine PHP-Datei hat noch einen Syntaxfehler."
  echo "Bitte die oben ausgegebene Fehlermeldung verwenden."
fi

echo "Backup: $(pwd)/.backup_http500_$STAMP"
