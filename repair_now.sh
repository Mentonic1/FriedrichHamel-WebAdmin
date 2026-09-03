#!/usr/bin/env bash
set -euo pipefail

TARGET="${1:-/var/www/html}"

echo "== Friedrich Hamel WebAdmin Sofort-Reparatur =="
echo "Ziel: $TARGET"

patch_admin() {
  local f="$1"
  [ -f "$f" ] || return 0
  cp -a "$f" "$f.bak.$(date +%Y%m%d_%H%M%S)"
  python3 - "$f" <<'PY'
from pathlib import Path
import sys
p=Path(sys.argv[1])
s=p.read_text(encoding='utf-8')
old=s
# Ungültige alternative try/catch-Syntax beseitigen.
s=s.replace("}catch(Throwable $e):?><div class=\"alert warn\"><?=h($e->getMessage())?></div><?php endtry?>",
            "} catch (Throwable $e) { ?><div class=\"alert warn\"><?=h($e->getMessage())?></div><?php } ?>")
s=s.replace("catch(Throwable $e):?>", "catch(Throwable $e) { ?>")
s=s.replace("<?php endtry?>", "<?php } ?>")
s=s.replace("<?php endtry; ?>", "<?php } ?>")
if s != old:
    p.write_text(s, encoding='utf-8')
    print('admin.php: Syntaxmuster repariert')
else:
    print('admin.php: kein bekanntes fehlerhaftes try/catch-Muster gefunden')
PY
  php -l "$f"
}

patch_api() {
  local f="$1"
  [ -f "$f" ] || return 0
  cp -a "$f" "$f.bak.$(date +%Y%m%d_%H%M%S)"
  php -l "$f" || true
}

# Sowohl Webroot als auch geklonten Unterordner prüfen.
patch_admin "$TARGET/admin.php"
patch_admin "$TARGET/FriedrichHamel-WebAdmin/admin.php"
patch_api "$TARGET/lizenz_api.php"
patch_api "$TARGET/FriedrichHamel-WebAdmin/lizenz_api.php"

echo
echo "== Aktuelle PHP-Dateien prüfen =="
find "$TARGET" -maxdepth 2 -type f -name '*.php' -print0 | while IFS= read -r -d '' f; do
  if ! php -l "$f" >/tmp/php_lint_out 2>&1; then
    echo "FEHLER: $f"
    cat /tmp/php_lint_out
  fi
done

echo
echo "Fertig. Falls noch ein Fehler besteht:"
echo "sudo tail -n 30 /var/log/apache2/error.log"
