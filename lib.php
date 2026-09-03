<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
}

function require_csrf(): void
{
    $sent = (string)($_POST['csrf_token'] ?? '');
    $known = (string)($_SESSION['csrf_token'] ?? '');
    if ($known === '' || $sent === '' || !hash_equals($known, $sent)) {
        http_response_code(419);
        exit('Ungültige oder abgelaufene Anfrage. Bitte Seite neu laden.');
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return is_array($f) ? $f : [];
}

function client_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
}

function is_admin(): bool
{
    return !empty($_SESSION['admin_id']);
}

function require_admin(): void
{
    if (!is_admin()) redirect('admin.php?view=login');
    $st = db()->prepare('SELECT id, benutzername, name, aktiv FROM aufmass_admins WHERE id=? LIMIT 1');
    $st->execute([(int)$_SESSION['admin_id']]);
    $admin = $st->fetch();
    if (!$admin || (int)$admin['aktiv'] !== 1) {
        $_SESSION = [];
        session_destroy();
        redirect('admin.php?view=login');
    }
}

function current_admin(): ?array
{
    if (!is_admin()) return null;
    $st = db()->prepare('SELECT id, benutzername, name, email, rolle FROM aufmass_admins WHERE id=? LIMIT 1');
    $st->execute([(int)$_SESSION['admin_id']]);
    $r = $st->fetch();
    return $r ?: null;
}

function audit(string $action, string $entityType = '', ?int $entityId = null, array $details = []): void
{
    try {
        $st = db()->prepare('INSERT INTO aufmass_audit_log (admin_id, aktion, entity_typ, entity_id, details_json, ip) VALUES (?,?,?,?,?,?)');
        $st->execute([is_admin() ? (int)$_SESSION['admin_id'] : null,$action,$entityType ?: null,$entityId,$details ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,client_ip()]);
    } catch (Throwable $e) { error_log('[Aufmass Audit] ' . $e->getMessage()); }
}

function login_rate_limited(string $username): bool
{
    $st = db()->prepare("SELECT COUNT(*) FROM aufmass_admin_login_log WHERE erfolgreich=0 AND ip=? AND versucht_am > DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
    $st->execute([client_ip()]);
    return (int)$st->fetchColumn() >= 8;
}

function admin_login(string $username, string $password): bool
{
    $username = trim($username);
    if ($username === '' || $password === '' || login_rate_limited($username)) return false;
    $st = db()->prepare('SELECT * FROM aufmass_admins WHERE benutzername=? LIMIT 1');
    $st->execute([$username]);
    $admin = $st->fetch();
    $ok = $admin && (int)$admin['aktiv'] === 1 && password_verify($password, (string)$admin['passwort_hash']);
    db()->prepare('INSERT INTO aufmass_admin_login_log (benutzername, ip, erfolgreich) VALUES (?,?,?)')->execute([$username, client_ip(), $ok ? 1 : 0]);
    if (!$ok) return false;
    session_regenerate_id(true);
    $_SESSION['admin_id'] = (int)$admin['id'];
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    db()->prepare('UPDATE aufmass_admins SET letzter_login=NOW() WHERE id=?')->execute([(int)$admin['id']]);
    audit('admin_login', 'admin', (int)$admin['id']);
    return true;
}

function admin_logout(): void
{
    if (is_admin()) audit('admin_logout', 'admin', (int)$_SESSION['admin_id']);
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool)$p['secure'], (bool)$p['httponly']);
    }
    session_destroy();
}

function normalize_email(string $email): string { return mb_strtolower(trim($email), 'UTF-8'); }

function generate_license_key(): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $part = static function(int $len) use ($alphabet): string {
        $out = '';
        for ($i = 0; $i < $len; $i++) $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        return $out;
    };
    return 'HAMEL-' . $part(5) . '-' . $part(5) . '-' . $part(5);
}

function app_secret_binary(): string
{
    $v = trim(APP_SECRET);
    if (preg_match('/^[0-9a-fA-F]{64}$/', $v)) return hex2bin($v) ?: hash('sha256', $v, true);
    return hash('sha256', $v, true);
}

function encrypt_license_key(string $plain): string
{
    $iv = random_bytes(12); $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', app_secret_binary(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) throw new RuntimeException('Lizenz-Key konnte nicht verschlüsselt werden.');
    return base64_encode($iv . $tag . $cipher);
}

function decrypt_license_key(string $encoded): string
{
    $raw = base64_decode($encoded, true);
    if ($raw === false || strlen($raw) < 29) throw new RuntimeException('Gespeicherter Lizenz-Key ist ungültig.');
    $iv = substr($raw, 0, 12); $tag = substr($raw, 12, 16); $cipher = substr($raw, 28);
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', app_secret_binary(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($plain === false) throw new RuntimeException('Lizenz-Key konnte nicht entschlüsselt werden. APP_SECRET unverändert?');
    return $plain;
}

function approve_application(int $id, int $licenseDays, int $maxDevices): array
{
    $pdo = db(); $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT * FROM aufmass_lizenz_antraege WHERE id=? FOR UPDATE'); $st->execute([$id]); $req = $st->fetch();
        if (!$req) throw new RuntimeException('Antrag nicht gefunden.');
        if ((string)$req['status'] !== 'offen') throw new RuntimeException('Antrag wurde bereits bearbeitet.');
        $email = normalize_email((string)$req['email']);
        $existing = $pdo->prepare('SELECT id FROM aufmass_cloud_benutzer WHERE LOWER(email)=LOWER(?) LIMIT 1'); $existing->execute([$email]);
        if ($existing->fetch()) throw new RuntimeException('Für diese E-Mail existiert bereits ein Benutzer. Bitte vorhandenen Benutzer verwalten.');
        $u = $pdo->prepare('INSERT INTO aufmass_cloud_benutzer (name, email, aktiv, max_geraete) VALUES (?,?,1,?)'); $u->execute([(string)$req['name'], $email, max(1, $maxDevices)]); $uid = (int)$pdo->lastInsertId();
        do { $key = generate_license_key(); $hash = hash('sha256', $key); $check = $pdo->prepare('SELECT COUNT(*) FROM aufmass_cloud_lizenzen WHERE lizenz_hash=?'); $check->execute([$hash]); } while ((int)$check->fetchColumn() > 0);
        $expiry = (new DateTimeImmutable('now'))->modify('+' . max(1, $licenseDays) . ' days')->format('Y-m-d H:i:s');
        $l = $pdo->prepare('INSERT INTO aufmass_cloud_lizenzen (benutzer_id, lizenz_hash, lizenz_prefix, aktiv, gueltig_bis, aktiviert_am) VALUES (?,?,?,?,?,NOW())');
        $l->execute([$uid, $hash, 'HAMEL', 1, $expiry]); $licenseId = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO aufmass_lizenz_secrets (lizenz_id, key_ciphertext) VALUES (?,?)')->execute([$licenseId, encrypt_license_key($key)]);
        $pdo->prepare("UPDATE aufmass_lizenz_antraege SET status='freigegeben', benutzer_id=?, lizenz_id=?, freigegeben_von=?, freigegeben_am=NOW() WHERE id=?")->execute([$uid, $licenseId, (int)$_SESSION['admin_id'], $id]);
        $pdo->commit(); audit('antrag_freigegeben', 'antrag', $id, ['benutzer_id'=>$uid,'lizenz_id'=>$licenseId,'max_geraete'=>$maxDevices,'tage'=>$licenseDays]);
        return ['request'=>$req,'user_id'=>$uid,'license_id'=>$licenseId,'license_key'=>$key,'expiry'=>$expiry];
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

function create_user_with_license(string $name, string $email, int $licenseDays, int $maxDevices): array
{
    $name = trim($name); $email = normalize_email($email);
    if (mb_strlen($name) < 2) throw new RuntimeException('Bitte einen Namen angeben.');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('E-Mail-Adresse ungültig.');
    $pdo = db(); $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT id FROM aufmass_cloud_benutzer WHERE LOWER(email)=LOWER(?) LIMIT 1 FOR UPDATE'); $st->execute([$email]);
        if ($st->fetch()) throw new RuntimeException('Für diese E-Mail existiert bereits ein Benutzer.');
        $pdo->prepare('INSERT INTO aufmass_cloud_benutzer (name,email,aktiv,max_geraete) VALUES (?,?,1,?)')->execute([$name,$email,max(1,min(20,$maxDevices))]); $uid=(int)$pdo->lastInsertId();
        do { $key=generate_license_key(); $hash=hash('sha256',$key); $check=$pdo->prepare('SELECT COUNT(*) FROM aufmass_cloud_lizenzen WHERE lizenz_hash=?'); $check->execute([$hash]); } while ((int)$check->fetchColumn()>0);
        $expiry=(new DateTimeImmutable('now'))->modify('+'.max(1,min(3650,$licenseDays)).' days')->format('Y-m-d H:i:s');
        $pdo->prepare('INSERT INTO aufmass_cloud_lizenzen (benutzer_id,lizenz_hash,lizenz_prefix,aktiv,gueltig_bis,aktiviert_am) VALUES (?,?,\'HAMEL\',1,?,NOW())')->execute([$uid,$hash,$expiry]);
        $licenseId=(int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO aufmass_lizenz_secrets (lizenz_id,key_ciphertext) VALUES (?,?)')->execute([$licenseId,encrypt_license_key($key)]);
        $pdo->commit(); audit('benutzer_mit_lizenz_angelegt','benutzer',$uid,['lizenz_id'=>$licenseId,'max_geraete'=>$maxDevices,'tage'=>$licenseDays]);
        return ['user_id'=>$uid,'license_id'=>$licenseId,'license_key'=>$key,'expiry'=>$expiry];
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

function ensure_category_room_template(string $category): void
{
    $category=trim($category); if($category==='') return; $pdo=db();
    $pdo->prepare('INSERT IGNORE INTO katalog_kategorien (name) VALUES (?)')->execute([$category]);
    $pdo->prepare('INSERT IGNORE INTO globale_raum_vorlagen (name) VALUES (?)')->execute([$category]);
}

function license_key_for_id(int $licenseId): string
{
    $st=db()->prepare('SELECT key_ciphertext FROM aufmass_lizenz_secrets WHERE lizenz_id=? LIMIT 1'); $st->execute([$licenseId]); $cipher=$st->fetchColumn();
    if(!$cipher) throw new RuntimeException('Für diese Lizenz ist kein abrufbarer Schlüssel gespeichert.');
    return decrypt_license_key((string)$cipher);
}

function rotate_license(int $licenseId, bool $revokeDevices=true): string
{
    $pdo=db(); $pdo->beginTransaction();
    try {
        $st=$pdo->prepare('SELECT * FROM aufmass_cloud_lizenzen WHERE id=? FOR UPDATE'); $st->execute([$licenseId]); $lic=$st->fetch();
        if(!$lic) throw new RuntimeException('Lizenz nicht gefunden.');
        $key=generate_license_key(); $hash=hash('sha256',$key);
        $pdo->prepare('UPDATE aufmass_cloud_lizenzen SET lizenz_hash=?, letzter_login=NULL WHERE id=?')->execute([$hash,$licenseId]);
        $pdo->prepare('INSERT INTO aufmass_lizenz_secrets (lizenz_id,key_ciphertext) VALUES (?,?) ON DUPLICATE KEY UPDATE key_ciphertext=VALUES(key_ciphertext), zuletzt_gesendet_am=NULL')->execute([$licenseId,encrypt_license_key($key)]);
        if($revokeDevices) $pdo->prepare('UPDATE aufmass_cloud_geraete SET aktiv=0 WHERE lizenz_id=?')->execute([$licenseId]);
        $pdo->commit(); audit('lizenz_key_rotiert','lizenz',$licenseId,['geraete_gesperrt'=>$revokeDevices]); return $key;
    } catch(Throwable $e){ if($pdo->inTransaction())$pdo->rollBack(); throw $e; }
}

function mail_log(string $to,string $subject,bool $success,string $type,?int $userId=null,?int $licenseId=null,?string $error=null): void
{
    db()->prepare('INSERT INTO aufmass_mail_log (empfaenger, betreff, mail_typ, erfolgreich, fehler, benutzer_id, lizenz_id, admin_id) VALUES (?,?,?,?,?,?,?,?)')->execute([$to,$subject,$type,$success?1:0,$error,$userId,$licenseId,is_admin()?(int)$_SESSION['admin_id']:null]);
}

function format_dt(?string $v): string
{
    if(!$v) return '—';
    try { return (new DateTimeImmutable($v))->format('d.m.Y H:i'); } catch(Throwable) { return $v; }
}
