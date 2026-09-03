<?php
declare(strict_types=1);

// ============================================================
// Friedrich Hamel GmbH - Aufmaß Cloud / Web-Admin V8
// ZENTRALE KONFIGURATION
// ============================================================

const DB_HOST = 'localhost';
const DB_NAME = 'Aufmass';
const DB_USER = 'Aufmass';
const DB_PASS = 'BITTE_HIER_DEIN_DB_PASSWORT_EINTRAGEN';

// Öffentliche Basis-URL ohne Slash am Ende.
const APP_URL = 'https://aufmass.gigaworld.ddns.net';

// Einmalig für setup.php ändern. Danach setup.php löschen oder SETUP_ENABLED=false setzen.
const SETUP_ENABLED = true;
const SETUP_KEY = 'BITTE-EINEN-LANGEN-ZUFAELLIGEN-SETUP-KEY-EINTRAGEN';

// Für die verschlüsselte, temporäre Speicherung neu erzeugter Lizenz-Keys.
// Einmalig z. B. mit: php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
// Danach NICHT mehr ändern, sonst können bereits erzeugte Keys nicht mehr angezeigt/versendet werden.
const APP_SECRET = 'BITTE_64_HEX_ZEICHEN_EINTRAGEN_0123456789abcdef0123456789abcdef';

// Lizenz-Standardwerte.
const DEFAULT_LICENSE_DAYS = 365;
const DEFAULT_MAX_DEVICES = 1;

// SMTP / Mailversand. Beispiel für STARTTLS auf Port 587.
const SMTP_HOST = 'smtp.example.de';
const SMTP_PORT = 587;
const SMTP_USER = 'info@elektro-hamel.de';
const SMTP_PASS = 'BITTE_SMTP_PASSWORT_EINTRAGEN';
const SMTP_ENCRYPTION = 'tls'; // tls | ssl | none
const MAIL_FROM = 'info@elektro-hamel.de';
const MAIL_FROM_NAME = 'Friedrich Hamel GmbH';
const MAIL_REPLY_TO = 'info@elektro-hamel.de';

const COMPANY_NAME = 'Friedrich Hamel GmbH';
const COMPANY_ADDRESS = 'Rajen 236, 26817 Rhauderfehn';
const COMPANY_PHONE = '04952 - 92920';
const COMPANY_EMAIL = 'info@elektro-hamel.de';

const API_VERSION = '3.0';
const OFFLINE_GRACE_DAYS = 30;

date_default_timezone_set('Europe/Berlin');

// Session-Härtung. Bei HTTPS wird Secure automatisch gesetzt.
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    ini_set('session.cookie_secure', '1');
}
