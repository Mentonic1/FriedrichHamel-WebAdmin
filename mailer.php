<?php
declare(strict_types=1);
require_once __DIR__ . '/lib.php';

function smtp_read($fp): string
{
    $data = '';
    while (($line = fgets($fp, 515)) !== false) {
        $data .= $line;
        if (strlen($line) < 4 || $line[3] === ' ') break;
    }
    return $data;
}

function smtp_expect($fp, array $codes): string
{
    $r = smtp_read($fp);
    $code = (int)substr($r, 0, 3);
    if (!in_array($code, $codes, true)) {
        throw new RuntimeException('SMTP-Fehler ' . $code . ': ' . trim($r));
    }
    return $r;
}

function smtp_cmd($fp, string $cmd, array $codes): string
{
    fwrite($fp, $cmd . "\r\n");
    return smtp_expect($fp, $codes);
}

function mail_header_encode(string $value): string
{
    return '=?UTF-8?B?' . base64_encode($value) . '?=';
}

function smtp_send(string $to, string $subject, string $html, string $text = ''): void
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Ungültige Empfängeradresse.');
    if (SMTP_HOST === '' || str_contains(SMTP_HOST, 'example.')) throw new RuntimeException('SMTP ist in config.php noch nicht konfiguriert.');

    $transport = SMTP_ENCRYPTION === 'ssl' ? 'ssl://' : 'tcp://';
    $errno = 0; $errstr = '';
    $fp = @stream_socket_client($transport . SMTP_HOST . ':' . SMTP_PORT, $errno, $errstr, 12, STREAM_CLIENT_CONNECT);
    if (!$fp) throw new RuntimeException("SMTP-Verbindung fehlgeschlagen: $errstr ($errno)");
    stream_set_timeout($fp, 12);

    try {
        smtp_expect($fp, [220]);
        $host = $_SERVER['SERVER_NAME'] ?? 'localhost';
        smtp_cmd($fp, 'EHLO ' . $host, [250]);

        if (SMTP_ENCRYPTION === 'tls') {
            smtp_cmd($fp, 'STARTTLS', [220]);
            $ok = stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($ok !== true) throw new RuntimeException('STARTTLS konnte nicht aktiviert werden.');
            smtp_cmd($fp, 'EHLO ' . $host, [250]);
        }

        if (SMTP_USER !== '') {
            smtp_cmd($fp, 'AUTH LOGIN', [334]);
            smtp_cmd($fp, base64_encode(SMTP_USER), [334]);
            smtp_cmd($fp, base64_encode(SMTP_PASS), [235]);
        }

        smtp_cmd($fp, 'MAIL FROM:<' . MAIL_FROM . '>', [250]);
        smtp_cmd($fp, 'RCPT TO:<' . $to . '>', [250, 251]);
        smtp_cmd($fp, 'DATA', [354]);

        $boundary = 'b_' . bin2hex(random_bytes(12));
        $messageId = '<' . bin2hex(random_bytes(12)) . '@' . ($host ?: 'localhost') . '>';
        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'From: ' . mail_header_encode(MAIL_FROM_NAME) . ' <' . MAIL_FROM . '>',
            'Reply-To: ' . MAIL_REPLY_TO,
            'To: <' . $to . '>',
            'Subject: ' . mail_header_encode($subject),
            'Message-ID: ' . $messageId,
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];

        if ($text === '') {
            $text = trim(preg_replace('/\s+/', ' ', strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html))) ?? '');
        }

        $body = implode("\r\n", $headers) . "\r\n\r\n";
        $body .= '--' . $boundary . "\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
        $body .= chunk_split(base64_encode($text)) . "\r\n";
        $body .= '--' . $boundary . "\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
        $body .= chunk_split(base64_encode($html)) . "\r\n";
        $body .= '--' . $boundary . "--\r\n";
        $body = preg_replace('/(?m)^\./', '..', $body) ?? $body;

        fwrite($fp, $body . "\r\n.\r\n");
        smtp_expect($fp, [250]);
        smtp_cmd($fp, 'QUIT', [221]);
    } finally {
        fclose($fp);
    }
}

function license_mail_html(array $user, array $license, string $key): string
{
    $name = h((string)$user['name']);
    $expiry = h(format_dt((string)$license['gueltig_bis']));
    $devices = (int)$user['max_geraete'];
    $keyHtml = h($key);
    return '<!doctype html><html><body style="font-family:Arial,sans-serif;color:#1f2937;background:#f4f5f7;padding:28px">'
        . '<div style="max-width:680px;margin:auto;background:#fff;border-radius:14px;padding:32px;border:1px solid #e5e7eb">'
        . '<h2 style="margin-top:0">Ihre Aufmaß-App wurde freigegeben</h2>'
        . '<p>Hallo ' . $name . ',</p>'
        . '<p>Ihre Nutzung der Friedrich-Hamel-Aufmaß-App wurde von uns freigegeben.</p>'
        . '<div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:10px;padding:18px;margin:22px 0">'
        . '<div style="font-size:12px;color:#9a3412;text-transform:uppercase;letter-spacing:.08em">Persönlicher Lizenz-Key</div>'
        . '<div style="font:700 22px monospace;margin-top:7px">' . $keyHtml . '</div></div>'
        . '<p><b>Erlaubte Geräte:</b> ' . $devices . '<br><b>Gültig bis:</b> ' . $expiry . '</p>'
        . '<p>Bitte geben Sie den Lizenz-Key nicht weiter. Jedes freigegebene Gerät wird separat registriert.</p>'
        . '<p>Ihre Projekte werden nach der Anmeldung mit Ihrem Benutzerkonto synchronisiert.</p>'
        . '<p style="margin-top:28px">Freundliche Grüße<br><b>' . h(COMPANY_NAME) . '</b><br>' . h(COMPANY_ADDRESS) . '<br>' . h(COMPANY_PHONE) . '</p>'
        . '</div></body></html>';
}

function send_license_mail(int $licenseId): void
{
    $pdo = db();
    $st = $pdo->prepare('SELECT l.*, b.name, b.email, b.max_geraete, b.id AS benutzer_id FROM aufmass_cloud_lizenzen l JOIN aufmass_cloud_benutzer b ON b.id=l.benutzer_id WHERE l.id=? LIMIT 1');
    $st->execute([$licenseId]);
    $row = $st->fetch();
    if (!$row) throw new RuntimeException('Lizenz nicht gefunden.');
    if (!filter_var((string)$row['email'], FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Benutzer hat keine gültige E-Mail-Adresse.');
    $key = license_key_for_id($licenseId);
    $subject = 'Ihre Freigabe für die Friedrich-Hamel-Aufmaß-App';
    $html = license_mail_html($row, $row, $key);
    try {
        smtp_send((string)$row['email'], $subject, $html);
        $pdo->prepare('UPDATE aufmass_lizenz_secrets SET zuletzt_gesendet_am=NOW(), gesendet_anzahl=gesendet_anzahl+1 WHERE lizenz_id=?')->execute([$licenseId]);
        mail_log((string)$row['email'], $subject, true, 'lizenz', (int)$row['benutzer_id'], $licenseId);
        audit('lizenz_mail_gesendet', 'lizenz', $licenseId, ['empfaenger' => (string)$row['email']]);
    } catch (Throwable $e) {
        mail_log((string)$row['email'], $subject, false, 'lizenz', (int)$row['benutzer_id'], $licenseId, $e->getMessage());
        throw $e;
    }
}

function send_custom_mail(string $to, string $subject, string $message, ?int $userId = null): void
{
    $safe = nl2br(h($message));
    $html = '<!doctype html><html><body style="font-family:Arial,sans-serif;color:#1f2937;background:#f4f5f7;padding:28px"><div style="max-width:680px;margin:auto;background:#fff;border-radius:14px;padding:32px;border:1px solid #e5e7eb">' . $safe . '<p style="margin-top:28px">Freundliche Grüße<br><b>' . h(COMPANY_NAME) . '</b></p></div></body></html>';
    try {
        smtp_send($to, $subject, $html, $message);
        mail_log($to, $subject, true, 'individuell', $userId);
        audit('mail_gesendet', 'benutzer', $userId, ['empfaenger' => $to, 'betreff' => $subject]);
    } catch (Throwable $e) {
        mail_log($to, $subject, false, 'individuell', $userId, null, $e->getMessage());
        throw $e;
    }
}
