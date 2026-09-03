<?php
declare(strict_types=1);
require_once __DIR__ . '/lib.php';

if (!SETUP_ENABLED) { http_response_code(403); exit('Setup ist deaktiviert.'); }
$error = ''; $done = false;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_csrf();
    $key = (string)($_POST['setup_key'] ?? '');
    $username = trim((string)($_POST['username'] ?? ''));
    $name = trim((string)($_POST['name'] ?? ''));
    $email = normalize_email((string)($_POST['email'] ?? ''));
    $pw = (string)($_POST['password'] ?? '');
    if (!hash_equals(SETUP_KEY, $key)) $error = 'Setup-Key ist falsch.';
    elseif (mb_strlen($username) < 3 || mb_strlen($pw) < 12) $error = 'Benutzername mindestens 3 Zeichen, Passwort mindestens 12 Zeichen.';
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $error = 'E-Mail ungültig.';
    else {
        try {
            $st = db()->prepare('INSERT INTO aufmass_admins (benutzername,name,email,passwort_hash,rolle,aktiv) VALUES (?,?,?,?,\'superadmin\',1)');
            $st->execute([$username, $name ?: $username, $email, password_hash($pw, PASSWORD_DEFAULT)]);
            $done = true;
        } catch (Throwable $e) { $error = 'Admin konnte nicht angelegt werden: ' . $e->getMessage(); }
    }
}
?><!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Setup</title><link rel="stylesheet" href="assets/style.css"></head><body class="login-body"><div class="login-card"><div class="brand"><div class="brand-mark">FH</div><div><strong>Ersteinrichtung</strong><span>Aufmaß Web-Admin</span></div></div><?php if($done): ?><div class="alert success">Admin wurde angelegt. Jetzt <b>SETUP_ENABLED=false</b> in config.php setzen und setup.php am besten löschen.</div><a class="btn btn-primary full" href="admin.php">Zum Admin-Login</a><?php else: ?><?php if($error): ?><div class="alert error"><?=h($error)?></div><?php endif; ?><form method="post" class="form-grid"><?=csrf_field()?><label class="full"><span>Setup-Key aus config.php</span><input type="password" name="setup_key" required></label><label class="full"><span>Admin-Benutzername</span><input name="username" required></label><label class="full"><span>Name</span><input name="name"></label><label class="full"><span>E-Mail</span><input type="email" name="email" required></label><label class="full"><span>Passwort (mind. 12 Zeichen)</span><input type="password" name="password" minlength="12" required></label><button class="btn btn-primary full">Admin anlegen</button></form><?php endif; ?></div></body></html>
