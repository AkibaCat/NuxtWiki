<?php
declare(strict_types=1);

/**
 * 邮件发送（页面订阅通知）
 * 支持两种传输方式：
 *   - mail : PHP mail()
 *   - smtp : 内置最小 SMTP 客户端（AUTH LOGIN，支持 SSL/TLS/明文）
 * 发送失败不影响主流程（静默记录错误）。
 */
final class Mailer
{
    public static function send(string $to, string $subject, string $html, ?string $text = null): bool
    {
        try {
            $cfg = app_config()['mail'] ?? [];
            $from = $cfg['from'] ?? 'wiki@example.com';
            $transport = $cfg['transport'] ?? 'mail';

            if ($transport === 'smtp' && !empty($cfg['smtp']['host'])) {
                return self::sendSmtp($to, $subject, $html, $text ?? strip_tags($html), $from, $cfg['smtp']);
            }
            return self::sendMail($to, $subject, $html, $from);
        } catch (Throwable $e) {
            error_log('[NuxtWiki Mailer] ' . $e->getMessage());
            return false;
        }
    }

    private static function sendMail(string $to, string $subject, string $html, string $from): bool
    {
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $from,
            'X-Mailer: NuxtWiki',
        ];
        return @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $html, implode("\r\n", $headers));
    }

    /** 最小 SMTP 客户端 */
    private static function sendSmtp(string $to, string $subject, string $html, string $text, string $from, array $smtp): bool
    {
        $host = $smtp['host'];
        $port = (int)($smtp['port'] ?? 465);
        $user = $smtp['user'] ?? '';
        $pass = $smtp['password'] ?? '';
        $enc  = $smtp['encryption'] ?? 'ssl';

        $remote = ($enc === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
        $fp = @stream_socket_client($remote, $errno, $errstr, 15);
        if (!$fp) {
            throw new RuntimeException('SMTP 连接失败: ' . $errstr);
        }

        $read = fn(): string => (string)fgets($fp, 515);
        $write = function (string $cmd) use ($fp, $read): void {
            fwrite($fp, $cmd . "\r\n");
            while (true) {
                $line = $read();
                if (strlen($line) < 4 || $line[3] !== '-') {
                    break;
                }
            }
        };

        $read(); // 服务器欢迎
        $write('EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
        if ($enc === 'tls') {
            $write('STARTTLS');
            $crypto = stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if (!$crypto) {
                fclose($fp);
                throw new RuntimeException('STARTTLS 失败');
            }
            $write('EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
        }
        if ($user !== '') {
            $write('AUTH LOGIN');
            $write(base64_encode($user));
            $write(base64_encode($pass));
        }
        $write('MAIL FROM:<' . $from . '>');
        $write('RCPT TO:<' . $to . '>');
        $write('DATA');

        $boundary = '----=_NuxtWiki_' . bin2hex(random_bytes(8));
        $body  = "From: $from\r\n";
        $body .= "To: $to\r\n";
        $body .= 'Subject: =?UTF-8?B?' . base64_encode($subject) . "?=\r\n";
        $body .= "MIME-Version: 1.0\r\n";
        $body .= "Content-Type: multipart/alternative; boundary=\"$boundary\"\r\n";
        $body .= "\r\n--$boundary\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n" . $text . "\r\n";
        $body .= "--$boundary\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n\r\n" . $html . "\r\n";
        $body .= "--$boundary--\r\n.";
        fwrite($fp, $body . "\r\n");
        $read();
        $write('QUIT');
        fclose($fp);
        return true;
    }
}
