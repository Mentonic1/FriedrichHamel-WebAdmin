<?php
declare(strict_types=1);
require_once __DIR__ . '/lib.php';

$errors = [];
$successRef = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_csrf();
    $name = trim((string)($_POST['name'] ?? ''));
    $email = normalize_email((string)($_POST['email'] ?? ''));
    $firma = trim((string)($_POST['firma'] ?? ''));
    $telefon = trim((string)($_POST['telefon'] ?? ''));
    $geraete = max(1, min(10, (int)($_POST['geraete'] ?? 1)));
    $notiz = trim((string)($_POST['notiz'] ?? ''));
    $privacy = !empty($_POST['privacy']);
    $website = trim((string)($_POST['website'] ?? ''));

    if (mb_strlen($name) < 2) $errors[] = 'Bitte einen Namen angeben.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Bitte eine gültige E-Mail-Adresse angeben.';
    if (!$privacy) $errors[] = 'Bitte die Datenschutzhinweise bestätigen.';
    if ($website !== '') $errors[] = 'Anfrage konnte nicht verarbeitet werden.';
    $rl = db()->prepare("SELECT COUNT(*) FROM aufmass_lizenz_antraege WHERE ip=? AND erstellt_am > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    $rl->execute([client_ip()]);
    if ((int)$rl->fetchColumn() >= 5) $errors[] = 'Zu viele Anfragen von dieser Verbindung. Bitte später erneut versuchen.';

    if (!$errors) {
        $existing = db()->prepare("SELECT COUNT(*) FROM aufmass_lizenz_antraege WHERE LOWER(email)=LOWER(?) AND status='offen'");
        $existing->execute([$email]);
        if ((int)$existing->fetchColumn() > 0) {
            $errors[] = 'Für diese E-Mail-Adresse liegt bereits ein offener Antrag vor.';
        } else {
            $ref = strtoupper(substr(bin2hex(random_bytes(8)), 0, 12));
            $st = db()->prepare('INSERT INTO aufmass_lizenz_antraege (referenz, name, email, firma, telefon, gewuenschte_geraete, notiz, status, ip) VALUES (?,?,?,?,?,?,?,\'offen\',?)');
            $st->execute([$ref, $name, $email, $firma ?: null, $telefon ?: null, $geraete, $notiz ?: null, client_ip()]);
            audit('lizenz_antrag_eingegangen', 'antrag', (int)db()->lastInsertId(), ['referenz' => $ref]);
            $successRef = $ref;
        }
    }
}
?><!doctype html>
<html lang="de">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Aufmaß Cloud – Friedrich Hamel GmbH</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body class="public-body">
<header class="topbar"><div class="container topbar-inner"><div class="brand"><div class="brand-mark">FH</div><div><strong>Friedrich Hamel GmbH</strong><span>Aufmaß Cloud</span></div></div><a class="btn btn-ghost" href="admin.php">Admin</a></div></header>
<main>
<section class="hero"><div class="container hero-grid"><div><div class="eyebrow">ELEKTRO • AUFMASS • CLOUD</div><h1>Aufmaßdaten zentral, sicher und auf allen freigegebenen Geräten.</h1><p class="lead">Die Friedrich-Hamel-Aufmaß-App verbindet lokale Offline-Arbeit mit zentraler Projektsynchronisation und persönlicher Gerätefreigabe.</p><div class="hero-points"><span>✓ Offline weiterarbeiten</span><span>✓ Projekte geräteübergreifend</span><span>✓ Persönliche Lizenz & Gerätebindung</span></div></div><div class="hero-card"><h2>Lizenzzugang anfragen</h2><p class="muted">Ein Antrag erzeugt <b>noch keine Lizenz</b>. Die Freigabe erfolgt ausschließlich manuell durch einen Administrator.</p>
<?php if ($successRef): ?><div class="alert success"><b>Antrag eingegangen.</b><br>Referenz: <?=h($successRef)?><br>Die Lizenz wird erst nach manueller Prüfung freigegeben.</div><?php else: ?>
<?php foreach ($errors as $e): ?><div class="alert error"><?=h($e)?></div><?php endforeach; ?>
<form method="post" class="form-grid"><?=csrf_field()?>
<div class="hp-field" aria-hidden="true"><label>Website<input name="website" tabindex="-1" autocomplete="off"></label></div>
<label><span>Name *</span><input name="name" required maxlength="255" value="<?=h($_POST['name'] ?? '')?>"></label>
<label><span>E-Mail *</span><input type="email" name="email" required maxlength="255" value="<?=h($_POST['email'] ?? '')?>"></label>
<label><span>Firma / Abteilung</span><input name="firma" maxlength="255" value="<?=h($_POST['firma'] ?? '')?>"></label>
<label><span>Telefon</span><input name="telefon" maxlength="80" value="<?=h($_POST['telefon'] ?? '')?>"></label>
<label><span>Benötigte Geräte</span><select name="geraete"><?php for($i=1;$i<=5;$i++): ?><option value="<?=$i?>" <?=((int)($_POST['geraete'] ?? 1)===$i?'selected':'')?>><?=$i?></option><?php endfor?></select></label>
<label class="full"><span>Hinweis / Einsatzbereich</span><textarea name="notiz" maxlength="2000" rows="4"><?=h($_POST['notiz'] ?? '')?></textarea></label>
<label class="check full"><input type="checkbox" name="privacy" value="1" required><span>Ich stimme zu, dass meine Angaben zur Bearbeitung des Zugangs gespeichert werden.</span></label>
<button class="btn btn-primary full" type="submit">Zugang unverbindlich anfragen</button>
</form><?php endif; ?></div></div></section>
<section class="section"><div class="container"><h2 class="center">Für den betrieblichen Einsatz gebaut</h2><div class="feature-grid"><article><b>Gerätebindung</b><p>Jeder freigegebene Arbeitsplatz wird separat registriert und kann zentral gesperrt oder entfernt werden.</p></article><article><b>Cloud-Synchronisation</b><p>Projekte werden dem jeweiligen Nutzerkonto zugeordnet und zwischen dessen Geräten abgeglichen.</p></article><article><b>Offline-fähig</b><p>Die lokale SQLite-Datenbank bleibt Arbeitsgrundlage. Bei Verbindung werden Änderungen synchronisiert.</p></article></div></div></section>
</main>
<footer><div class="container footer-inner"><span><?=h(COMPANY_NAME)?> · <?=h(COMPANY_ADDRESS)?></span><span><?=h(COMPANY_PHONE)?> · <?=h(COMPANY_EMAIL)?> · <a href="rechtliches.php">Impressum & Datenschutz</a></span></div></footer>
</body></html>
