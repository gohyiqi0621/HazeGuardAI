<?php
declare(strict_types=1);

namespace HazeGuard;

/**
 * Mailer with pluggable drivers, so the delivery provider is an env choice
 * rather than a code change.
 *
 *   MAIL_DRIVER=log   -> write .txt files under MAIL_LOG_DIR (dev default; no provider)
 *   MAIL_DRIVER=brevo -> send via the Brevo HTTP API (needs an API key)
 *
 * The Brevo key is looked up in two places, in this order:
 *   1. BREVO_API_KEY from .env / the environment — the deployment override.
 *   2. The encrypted credential named by BREVO_CREDENTIAL (default "Brevo") in
 *      the administrator's Credentials panel — the value an operator maintains
 *      from the UI, stored as ciphertext (see Crypto).
 *
 * HTTPS is sent with cURL when that extension is loaded, and with PHP's stream
 * wrapper otherwise, so a PHP that cannot load php_curl.dll still delivers mail.
 * MAIL_CA_BUNDLE names the CA bundle to verify against when php.ini does not.
 * On the stream path the host's A records are dialled before the hostname, so a
 * dual-stack machine cannot stall on a dead IPv6 route (see httpsTargets).
 *
 * Never log message bodies in production: the log driver refuses to run when
 * APP_ENV=production so verification codes cannot leak into a file.
 */
final class Mailer
{
    /** Brevo credential to read when BREVO_API_KEY is not set. */
    private const DEFAULT_BREVO_CREDENTIAL = 'Brevo';

    /** Seconds to wait on the mail provider before giving up. */
    private const TIMEOUT = 10;

    public static function send(string $to, string $subject, string $textBody): void
    {
        $driver = strtolower((string) Config::get('MAIL_DRIVER', 'log'));

        match ($driver) {
            'brevo' => self::sendViaBrevo($to, $subject, $textBody),
            'log'   => self::sendViaLog($to, $subject, $textBody),
            default => throw new \RuntimeException("Unknown MAIL_DRIVER: {$driver}"),
        };
    }

    public static function sendVerificationCode(string $to, string $code): void
    {
        $subject = 'Your HazeGuard AI verification code';
        $body = "Welcome to HazeGuard AI.\n\n"
            . "Your email verification code is: {$code}\n\n"
            . "It expires in " . (int) (Config::int('OTP_TTL_SECONDS', 300) / 60) . " minutes and can be used once.\n"
            . "If you didn't create an account, you can ignore this email.\n";
        self::send($to, $subject, $body);
    }

    public static function sendLoginOtp(string $to, string $code): void
    {
        $subject = 'Your HazeGuard AI sign-in code';
        $body = "Here is your one-time sign-in code.\n\n"
            . "Code: {$code}\n\n"
            . "It expires in " . (int) (Config::int('OTP_TTL_SECONDS', 300) / 60) . " minutes and can be used once.\n"
            . "If you didn't try to sign in, change your password.\n";
        self::send($to, $subject, $body);
    }

    /** Sent once the address is confirmed and the account becomes active. */
    public static function sendWelcome(string $to, string $username): void
    {
        $name  = Config::get('MAIL_FROM_NAME', 'HazeGuard AI');
        $url   = rtrim((string) Config::get('APP_URL', ''), '/');
        $where = $url === '' ? '' : "\nYou can sign in at: {$url}\n";

        $subject = "Welcome to {$name}";
        $body = "Hi {$username},\n\n"
            . "Your email address is confirmed and your {$name} account is now active.\n"
            . $where
            . "\nIf you didn't create this account, reply to this email and we will remove it.\n";
        self::send($to, $subject, $body);
    }

    /**
     * The password-reset code.
     *
     * Deliberately a code and not a link. A link has to be built from APP_URL
     * and then opened on a device that can reach this server, so it broke on a
     * wrong APP_URL and was useless when the mail was read on a phone. A code
     * the recipient types has neither problem — which is why, unlike the link
     * version this replaced, the message needs no APP_URL at all.
     */
    public static function sendPasswordResetCode(string $to, string $code): void
    {
        $name = Config::get('MAIL_FROM_NAME', 'HazeGuard AI');

        $subject = "Reset your {$name} password";
        $body = "Someone asked to reset the password for this address.\n\n"
            . "Code: {$code}\n\n"
            . "It expires in " . (int) (Config::int('OTP_TTL_SECONDS', 300) / 60) . " minutes and can be used once.\n"
            . "Enter it on the password-reset screen together with your new password.\n"
            . "If you didn't ask for this, you can ignore this email — nothing has changed.\n";
        self::send($to, $subject, $body);
    }

    // ---------------------------------------------------------------- drivers

    private static function sendViaLog(string $to, string $subject, string $textBody): void
    {
        if (Config::isProduction()) {
            throw new \RuntimeException('The "log" mail driver must not be used in production.');
        }
        $dir = self::logDirectory();
        // Microseconds in the name keep filenames chronologically sortable even
        // when several messages are written within the same second.
        $stamp = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Ymd-His-u');
        $rand = bin2hex(random_bytes(3));
        $slug = preg_replace('/[^a-z0-9]+/i', '_', $to) ?? 'recipient';
        $file = sprintf('%s/%s_%s_%s.txt', $dir, $stamp, $slug, $rand);

        $contents = "To: {$to}\n"
            . 'From: ' . Config::get('MAIL_FROM_NAME', 'HazeGuard AI') . ' <' . Config::get('MAIL_FROM_ADDRESS', 'no-reply@hazeguard.local') . ">\n"
            . "Subject: {$subject}\n"
            . 'Date: ' . gmdate('D, d M Y H:i:s') . " +0000\n"
            . "X-Driver: log (development only)\n\n"
            . $textBody;

        if (file_put_contents($file, $contents) === false) {
            throw new \RuntimeException("Could not write mail log file: {$file}");
        }
    }

 
private static function sendViaBrevo(string $to, string $subject, string $textBody): void
{
    $payload = [
        'sender'      => [
            'email' => (string) Config::get('MAIL_FROM_ADDRESS', 'no-reply@hazeguard.local'),
            'name'  => Config::get('MAIL_FROM_NAME', 'HazeGuard AI'),
        ],
        'to'          => [['email' => $to]],
        'subject'     => $subject,
        'textContent' => $textBody,
    ];

    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new \RuntimeException('Could not encode the Brevo payload.');
    }

    // Log file location
    $logFile = __DIR__ . '/brevo.log';

    $log = static function (string $message) use ($logFile): void {
        $file = fopen($logFile, 'ab');

        if ($file !== false) {
            fwrite(
                $file,
                '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL
            );
            fclose($file);
        }
    };

    $log('Starting Brevo email request.');
    $log('Recipient: ' . $to);
    $log('Subject: ' . $subject);

    try {
        $response = self::postJson(
            'https://api.brevo.com/v3/smtp/email',
            [
                'accept: application/json',
                'content-type: application/json',
                'api-key: ' . self::brevoApiKey(),
            ],
            $json
        );

        $log('HTTP status: ' . $response['status']);
        $log('Response body: ' . trim($response['body']));

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new \RuntimeException(
                "Brevo send failed (HTTP {$response['status']}): "
                . trim($response['body'])
                . self::brevoHint($response['status'], $response['body'])
            );
        }

        $log('Brevo email request accepted successfully.');
    } catch (\Throwable $e) {
        $log('ERROR: ' . $e->getMessage());
        throw $e;
    }
}


    /**
     * POST a JSON body over HTTPS and return the status and body, whatever the
     * status — a 4xx still carries the explanation we want to report.
     *
     * cURL is used when the extension is loaded, otherwise PHP's HTTP stream
     * wrapper. The fallback is not hypothetical: a stock Windows PHP running as
     * an Apache module frequently cannot load php_curl.dll, because Windows
     * resolves that DLL's own dependencies (libssh2, libcrypto) relative to
     * httpd.exe rather than php.exe. Streams need only openssl, which is part
     * of the default build.
     *
     * @param list<string> $headers
     * @return array{status:int,body:string}
     */
    private static function postJson(string $url, array $headers, string $json): array
    {
        if (function_exists('curl_init')) {
            $options = [
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => self::TIMEOUT,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_POSTFIELDS     => $json,
            ];
            if (($ca = self::caBundle()) !== null) {
                $options[CURLOPT_CAINFO] = $ca;
            }

            $ch = curl_init($url);
            curl_setopt_array($ch, $options);
            $body   = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error  = curl_error($ch);
            curl_close($ch);

            if ($body === false) {
                throw new \RuntimeException("Could not reach {$url}: {$error}");
            }

            return ['status' => $status, 'body' => (string) $body];
        }

        if (!ini_get('allow_url_fopen')) {
            throw new \RuntimeException(
                'Cannot send mail: the cURL extension is not loaded and allow_url_fopen is off.'
            );
        }

        // cURL races both address families itself; the stream wrapper does not,
        // so it is given an ordered list of endpoints to try.
        $failure = null;
        foreach (self::httpsTargets($url) as $target) {
            try {
                return self::postJsonStream($target['url'], $headers, $json, $target);
            } catch (\RuntimeException $e) {
                $failure = $e;
            }
        }

        throw $failure ?? new \RuntimeException("Could not reach {$url}.");
    }

    /**
     * One POST attempt with PHP's HTTP stream wrapper.
     *
     * @param list<string> $headers
     * @param array{url:string,host:string,ip:?string} $target
     * @return array{status:int,body:string}
     */
    private static function postJsonStream(string $url, array $headers, string $json, array $target): array
    {
        // Dialling an address instead of the name hides the name from the
        // wrapper, so the certificate it should expect is pinned by hand and
        // the Host header it would have derived from the URL is sent here.
        if ($target['ip'] !== null) {
            $headers[] = 'Host: ' . $target['host'];
        }

        $context = [
            'http' => [
                'method'        => 'POST',
                'header'        => implode("\r\n", $headers),
                'content'       => $json,
                'timeout'       => self::TIMEOUT,
                'ignore_errors' => true,   // read the body of a 4xx/5xx, do not raise a warning
            ],
        ];

        $ssl = [];
        if ($target['ip'] !== null) {
            $ssl['peer_name']   = $target['host'];
            $ssl['SNI_enabled'] = true;
        }
        if (($ca = self::caBundle()) !== null) {
            $ssl['cafile']           = $ca;
            $ssl['verify_peer']      = true;
            $ssl['verify_peer_name'] = true;
        }
        if ($ssl !== []) {
            $context['ssl'] = $ssl;
        }

        // $http_response_header is filled in by this call, in this scope.
        $body = @file_get_contents($url, false, stream_context_create($context));
        if ($body === false) {
            throw new \RuntimeException(
                "Could not reach {$url}: " . (error_get_last()['message'] ?? 'the request failed')
            );
        }

        // A redirect chain produces several status lines; the last one is real.
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                $status = (int) $m[1];
            }
        }

        return ['status' => $status, 'body' => (string) $body];
    }

    /**
     * Endpoints to try for an https URL, A records first and the hostname last.
     *
     * PHP's stream wrapper hands the hostname to the resolver and uses whatever
     * address comes back, IPv6 included. On a dual-stack machine an AAAA address
     * with a broken route then costs a full timeout before the request even
     * starts — observed as an intermittent "did not properly respond after a
     * period of time" on roughly one send in three, while the same request
     * pinned to an A record always succeeded. Dialling the A records first
     * avoids the stall; keeping the hostname last means an IPv6-only network
     * still works.
     *
     * @return list<array{url:string,host:string,ip:?string}>
     */
    private static function httpsTargets(string $url): array
    {
        $parts  = parse_url($url);
        $scheme = $parts['scheme'] ?? 'https';
        $host   = $parts['host'] ?? '';
        $byName = ['url' => $url, 'host' => $host, 'ip' => null];

        if ($host === '' || $scheme !== 'https' || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$byName];
        }

        $records = function_exists('dns_get_record') ? @dns_get_record($host, DNS_A) : false;
        if (!is_array($records)) {
            return [$byName];
        }

        $prefix = "{$scheme}://";
        $port   = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path   = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');

        // Three is plenty: these are anycast addresses of the same edge, and
        // each miss costs a timeout.
        $targets = [];
        foreach (array_slice($records, 0, 3) as $record) {
            $ip = (string) ($record['ip'] ?? '');
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                $targets[] = ['url' => "{$prefix}{$ip}{$port}{$path}", 'host' => $host, 'ip' => $ip];
            }
        }

        $targets[] = $byName;

        return $targets;
    }

    /**
     * The CA bundle to verify TLS against, or null to use PHP's default.
     *
     * A Windows PHP often ships with curl.cainfo and openssl.cafile unset, and
     * then refuses every HTTPS request with "unable to get local issuer
     * certificate" — a failure that reads like a network fault but is really a
     * missing file. MAIL_CA_BUNDLE names it explicitly; php.ini is the fallback.
     */
    private static function caBundle(): ?string
    {
        $configured = trim((string) Config::get('MAIL_CA_BUNDLE', ''));
        if ($configured !== '' && is_file($configured)) {
            return $configured;
        }

        foreach (['curl.cainfo', 'openssl.cafile'] as $setting) {
            $path = trim((string) ini_get($setting));
            if ($path !== '' && is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Turn Brevo's two most common refusals into the fix, because neither is
     * obvious from the raw response: both look like "it just didn't send".
     */
    private static function brevoHint(int $status, string $response): string
    {
        if ($status === 401 && str_contains($response, 'unrecognised IP')) {
            return ' — Brevo has IP allow-listing switched on for this account.'
                . ' Add this server\'s address at https://app.brevo.com/security/authorised_ips,'
                . ' or turn the restriction off, then try again.';
        }

        if (str_contains($response, 'sender') && $status === 400) {
            return ' — Brevo only accepts a sender address verified on your account.'
                . ' Set MAIL_FROM_ADDRESS to one listed under Senders, Domains and IPs in Brevo.';
        }

        return '';
    }

    /**
     * The Brevo API key: the environment first, then the administrator's
     * encrypted credential.
     *
     * The env var wins because it is the deployment-level override — someone who
     * sets it in .env means it, and a key left over in the Credentials panel
     * should not quietly take precedence.
     */
    public static function brevoApiKey(): string
    {
        $key = trim((string) Config::get('BREVO_API_KEY', ''));
        if ($key !== '') {
            return $key;
        }

        $stored = self::storedBrevoKey();
        if ($stored !== null) {
            return $stored;
        }

        throw new \RuntimeException(
            'No Brevo API key configured. Set BREVO_API_KEY in .env, or save one in'
            . ' the administrator Credentials panel under the name "'
            . self::brevoCredentialName() . '".'
        );
    }

    /** The credential's key, decrypted. Null when it is absent or blank. */
    private static function storedBrevoKey(): ?string
    {
        try {
            $stored = Database::run(
                'SELECT api_key FROM credential_types WHERE name = :name LIMIT 1',
                ['name' => self::brevoCredentialName()]
            )->fetchColumn();
        } catch (\PDOException $e) {
            // No credential table (or no database yet) means there is no stored
            // key — the caller's "not configured" message is the useful one.
            error_log('[hazeguard] Brevo credential lookup failed: ' . $e->getMessage());
            return null;
        }

        if ($stored === false || trim((string) $stored) === '') {
            return null;
        }

        // Values written before encryption existed have no `enc:v1:` prefix and
        // come back unchanged, so an unmigrated row still works.
        $plain = trim((string) Crypto::decrypt((string) $stored));

        return $plain === '' ? null : $plain;
    }

    private static function brevoCredentialName(): string
    {
        $name = trim((string) Config::get('BREVO_CREDENTIAL', self::DEFAULT_BREVO_CREDENTIAL));

        return $name === '' ? self::DEFAULT_BREVO_CREDENTIAL : $name;
    }

    public static function logDirectory(): string
    {
        $dir = Config::get('MAIL_LOG_DIR', 'storage/mail') ?? 'storage/mail';
        if (!str_starts_with($dir, '/') && !preg_match('#^[A-Za-z]:#', $dir)) {
            $dir = dirname(__DIR__, 2) . '/' . $dir; // relative to project root
        }
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Could not create mail log directory: {$dir}");
        }
        return $dir;
    }
}
