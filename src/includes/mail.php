<?php
/**
 * Thin wrapper around PHPMailer so the rest of the app just calls
 * send_email(...) without touching SMTP details. If SMTP_HOST isn't
 * configured, falls back to PHP's built-in mail() transport (fine for
 * quick testing, but most hosts need real SMTP creds to actually deliver).
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/email_templates.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * Providers that only accept mail whose "From" address is the account you log in with.
 * (Gmail silently rewrites it, Zoho/Outlook reject it.) Sending as anything else fails or lands in spam.
 */
function smtp_strict_from_host(string $host): bool {
    return (bool) preg_match('/(^|\.)(gmail\.com|googlemail\.com|zoho\.(com|in|eu|com\.au|jp|sa)|office365\.com|outlook\.com|live\.com|yahoo\.com|icloud\.com)$/i', $host);
}

/**
 * The SMTP settings actually used to send: the saved ones, with the mistakes that most often make a
 * correct-looking configuration fail fixed automatically —
 *   - port 465 is implicit TLS ("ssl") and 587/25 are STARTTLS ("tls"); a mismatched choice hangs or errors;
 *   - Gmail app passwords are shown as "abcd efgh ijkl mnop", the spaces are not part of the password;
 *   - Gmail/Zoho/Outlook/Yahoo/iCloud require From = the login address.
 */
function smtp_effective(): array {
    $c = smtp_settings();
    $c['host'] = trim($c['host']);
    $c['user'] = trim($c['user']);
    if ($c['port'] < 1) $c['port'] = $c['secure'] === 'ssl' ? 465 : 587;
    if ($c['port'] === 465) $c['secure'] = 'ssl';
    elseif (in_array($c['port'], [587, 25, 2525], true) && $c['secure'] === 'ssl') $c['secure'] = 'tls';
    if (preg_match('/(^|\.)(gmail|googlemail)\.com$/i', $c['host'])) $c['pass'] = preg_replace('/\s+/', '', $c['pass']);
    $c['reply_to'] = null;
    if ($c['host'] !== '' && $c['user'] !== '' && smtp_strict_from_host($c['host']) && filter_var($c['user'], FILTER_VALIDATE_EMAIL)
        && strcasecmp($c['from_email'], $c['user']) !== 0) {
        $c['reply_to'] = $c['from_email'];   // replies still reach the store address
        $c['from_email'] = $c['user'];
    }
    return $c;
}

/** Things that are wrong with the saved SMTP settings before anything is even sent. */
function smtp_config_warnings(): array {
    $c = smtp_settings();
    $w = [];
    if ($c['host'] === '') return $w;
    $h = strtolower(trim($c['host']));
    if ($c['port'] > 0 && !in_array($c['port'], [25, 465, 587, 2525, 2465, 8025], true))
        $w[] = 'Port ' . $c['port'] . ' isn\'t a mail port. Use 465 (SSL/TLS) or 587 (STARTTLS).';
    if (preg_match('/(^|\.)zoho\.[a-z.]+$/', $h) && !preg_match('/^smtp(pro)?\.zoho\./', $h))
        $w[] = 'For Zoho the SMTP host is smtp.zoho.com (or smtppro.zoho.com for paid business mail), not ' . $c['host'] . '. If your account is on another Zoho data centre use smtp.zoho.in, smtp.zoho.eu, smtp.zoho.com.au or smtp.zoho.jp to match.';
    return $w;
}

/** A plain-English next step for the error strings mail servers / PHPMailer produce. */
function mail_error_hint(?string $err): string {
    $e = strtolower((string) $err);
    if ($e === '') return '';
    if (str_contains($e, 'could not authenticate') && preg_match('/zoho/i', smtp_settings()['host']))
        return 'Zoho refused the login. The usual causes, in order: (1) two-factor is on for that mailbox, so a normal password won\'t work — create an Application-Specific Password in Zoho (Account -> Security) and use that; (2) SMTP/IMAP access is switched off for the mailbox (Zoho Mail settings -> Mail Accounts -> IMAP/SMTP); (3) the account is on a different Zoho data centre than the host (use smtp.zoho.in / .eu / .com.au to match where you log in); (4) the username isn\'t the full mailbox address.';
    if (str_contains($e, 'could not authenticate') || str_contains($e, 'authentication') || str_contains($e, '535') || str_contains($e, 'username and password not accepted'))
        return 'The server refused the username/password. Gmail needs an App Password (Google account → Security → 2-Step Verification → App passwords); Zoho needs an application-specific password if 2FA is on. Check the username is the full email address.';
    if (str_contains($e, 'connect()') || str_contains($e, 'connection refused') || str_contains($e, 'timed out') || str_contains($e, 'failed to connect') || str_contains($e, 'could not connect'))
        return 'The server could not reach the mail host. Check the host name and port (587 + STARTTLS, or 465 + SSL/TLS), and that the Docker host allows outbound connections on that port (some networks block 25/465/587).';
    if (str_contains($e, 'ssl') || str_contains($e, 'tls') || str_contains($e, 'certificate'))
        return 'Encryption negotiation failed. Port 587 uses STARTTLS and port 465 uses SSL/TLS — the two must match the setting above.';
    if (str_contains($e, 'sender') || str_contains($e, 'not owned') || str_contains($e, 'from address') || str_contains($e, 'relay') || str_contains($e, '550') || str_contains($e, '553') || str_contains($e, '554'))
        return 'The provider rejected the "From" address or the recipient. Use a From address on the same account/domain as the username, and (for your own domain) verify it with the provider.';
    if (str_contains($e, 'mail() ') || str_contains($e, 'could not instantiate mail function'))
        return 'No SMTP host is set, so PHP mail() was used, which cannot work from a Docker container. Fill in the SMTP host above.';
    return '';
}

/**
 * @param string      $toEmail
 * @param string      $toName
 * @param string      $subject
 * @param string      $htmlBody   HTML body (a plain-text version is auto-derived).
 * @param string|null $replyToEmail
 * @param string|null $replyToName
 * @param string      $kind       short label for the email log ('verify', 'order', 'promo', …)
 * @param array       $headers    extra headers, e.g. ['List-Unsubscribe' => '<https://…>']
 * @return bool true on success. Failures are logged, never thrown — a mail
 *              hiccup should never break checkout, registration, etc.
 */
function send_email(string $toEmail, string $toName, string $subject, string $htmlBody, ?string $replyToEmail = null, ?string $replyToName = null, string $kind = '', array $headers = []): bool {
    $GLOBALS['__mail_error'] = null;
    $cfg = smtp_effective();
    $mail = new PHPMailer(true);
    $serverErrors = [];
    try {
        if ($cfg['host'] !== '') {
            $mail->isSMTP();
            // Keep only the server's own 4xx/5xx replies (e.g. "535 5.7.8 Authentication failed") so the admin sees
            // WHY it refused. Client lines (which contain the encoded login) are never kept.
            $mail->SMTPDebug = 2;
            $mail->Debugoutput = function ($str, $level) use (&$serverErrors) {
                if (preg_match('/SERVER -> CLIENT:\s*([45]\d\d[ -].*)/', $str, $m)) $serverErrors[] = trim($m[1]);
            };
            $mail->Port = $cfg['port'];
            $mail->Timeout = 15;
            // Docker hosts often have no IPv6 route but the mail host publishes AAAA records, which makes the
            // connection hang until it times out. Connect over IPv4 explicitly, while TLS still verifies the real host name.
            $host = $cfg['host'];
            $v4 = filter_var($host, FILTER_VALIDATE_IP) ? false : @gethostbynamel($host);
            if ($v4) {
                $mail->Host = implode(';', array_slice($v4, 0, 3));
                $mail->SMTPOptions = ['ssl' => ['peer_name' => $host, 'verify_peer_name' => true]];
            } else {
                $mail->Host = $host;
            }
            if ($cfg['user'] !== '') {
                $mail->SMTPAuth = true;
                $mail->Username = $cfg['user'];
                $mail->Password = $cfg['pass'];
            }
            if ($cfg['secure'] === 'tls') $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            elseif ($cfg['secure'] === 'ssl') $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            else $mail->SMTPAutoTLS = false;
        } else {
            $mail->isMail();
        }

        $mail->CharSet = 'UTF-8';
        $mail->setFrom($cfg['from_email'], $cfg['from_name']);
        $mail->addAddress($toEmail, $toName);
        if ($replyToEmail) {
            $mail->addReplyTo($replyToEmail, $replyToName ?: $replyToEmail);
        } elseif (!empty($cfg['reply_to'])) {
            $mail->addReplyTo($cfg['reply_to'], $cfg['from_name']);
        }
        foreach ($headers as $hn => $hv) $mail->addCustomHeader($hn, $hv);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;
        $mail->AltBody = trim(html_entity_decode(strip_tags(preg_replace('~<(br\s*/?|/p|/h\d|/tr|/li)>~i', "\n", $htmlBody)), ENT_QUOTES, 'UTF-8'));

        $mail->send();
        email_log_write($kind, $toEmail, $subject, true, null);
        return true;
    } catch (PHPMailerException | Throwable $e) {
        $GLOBALS['__mail_error'] = $mail->ErrorInfo ?: $e->getMessage();
        if ($serverErrors) $GLOBALS['__mail_error'] .= ' — server said: ' . implode(' | ', array_slice(array_unique($serverErrors), -2));
        error_log('[mail] Failed to send "' . $subject . '" to ' . $toEmail . ': ' . $GLOBALS['__mail_error']);
        email_log_write($kind, $toEmail, $subject, false, $GLOBALS['__mail_error']);
        return false;
    }
}

/** Best-effort record of every send attempt (shown in Admin → Settings & email). Never throws. */
function email_log_write(string $kind, string $to, string $subject, bool $ok, ?string $error): void {
    try {
        db()->prepare('INSERT INTO email_log (kind, to_email, subject, status, error) VALUES (?,?,?,?,?)')
            ->execute([mb_substr($kind, 0, 30), mb_substr($to, 0, 190), mb_substr($subject, 0, 255), $ok ? 'sent' : 'failed', $error !== null ? mb_substr($error, 0, 500) : null]);
        if (random_int(1, 50) === 1) db()->exec("DELETE FROM email_log WHERE created_at < (NOW() - INTERVAL 60 DAY)");
    } catch (Throwable $e) {
        error_log('[mail] could not write email_log: ' . $e->getMessage());
    }
}

/** Reason the most recent send_email() call failed (for the admin "send test email" button). */
function mail_last_error(): ?string {
    return $GLOBALS['__mail_error'] ?? null;
}

/** Wraps a body of content in a minimal, on-brand HTML email shell (store name or logo, store email in the footer). */
function email_wrap(string $title, string $bodyHtml): string {
    $store = store_info();
    $dark = theme_settings()['dark'];
    $logo = brand_logo_email_url();
    // A logo goes on a white band (any logo colours are readable there); without one, the store name sits on the theme colour.
    $head = $logo
        ? '<div style="background:#ffffff;padding:16px 24px;border-bottom:4px solid ' . e($dark) . ';"><img src="' . e($logo) . '" alt="' . e($store['name']) . '" style="display:block;max-height:44px;max-width:220px;height:auto;width:auto;border:0;"></div>'
        : '<div style="background:' . e($dark) . ';color:' . e(contrast_text($dark)) . ';padding:18px 24px;font-size:1.1rem;font-weight:bold;">' . e($store['name']) . '</div>';
    $foot = e($store['name']) . ($store['email'] !== '' ? ' &middot; ' . e($store['email']) : '') . implode('', array_map(fn ($p) => ' &middot; ' . e($p), array_slice(store_phones(), 0, 2)));
    return '<div style="font-family:Arial,Helvetica,sans-serif;background:#efece2;padding:32px 16px;">'
        . '<div style="max-width:520px;margin:0 auto;background:#fffdf8;border:1px solid #d9d4c3;border-radius:8px;overflow:hidden;">'
        . $head
        . '<div style="padding:24px;color:#20293b;line-height:1.6;">'
        . '<h2 style="margin-top:0;color:#20293b;">' . e($title) . '</h2>'
        . $bodyHtml
        . '</div>'
        . '<div style="padding:16px 24px;background:#f8f6ee;color:#8791a6;font-size:0.78rem;">' . $foot . '</div>'
        . '</div></div>';
}
