<?php
declare(strict_types=1);

namespace HazeGuard;

/**
 * Server-side HTTPS JSON client.
 *
 * Two transports, for the same reason the mailer has two: a stock Windows PHP
 * running as an Apache module frequently cannot load php_curl.dll, because
 * Windows resolves that DLL's own dependencies relative to httpd.exe rather
 * than php.exe. Streams need only openssl, so they have to work.
 *
 * But PHP's stream wrapper does no Happy Eyeballs — it hands the hostname to
 * the resolver and uses whichever address comes back. On a machine whose IPv6
 * route to a CDN is intermittently dead, that stalls the request for the whole
 * timeout. This is not hypothetical: it is exactly what made Brevo sends fail
 * at random, and Open-Meteo sits behind the same kind of edge.
 *
 * So the stream path resolves the host's A records and dials them first, with
 * the hostname kept last so an IPv6-only network still works. cURL already
 * races both families and needs no help.
 *
 * Joomla's equivalent is `Joomla\Http\Http`; here it is one static method.
 */
final class Http
{
    /** Seconds. Kept short: a caller may sit on the critical path of our own API. */
    public const TIMEOUT = 6;

    /** At most this many pinned addresses — each miss costs a whole timeout. */
    private const MAX_PINNED = 3;

    /** @var string|null|false false = not looked up yet */
    private static string|false|null $caBundle = false;

    /**
     * GET a JSON document and decode it.
     *
     * @param array<string,scalar> $query
     * @return array<string,mixed>
     * @throws \RuntimeException on a transport failure, a non-2xx status, or a body that is not JSON
     */
    public static function getJson(string $url, array $query = [], int $timeout = self::TIMEOUT): array
    {
        $target = $query === [] ? $url : $url . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $response = self::request($target, $timeout);

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new \RuntimeException("GET {$target} returned HTTP {$response['status']}");
        }

        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded)) {
            throw new \RuntimeException("GET {$target} did not return a JSON object");
        }

        return $decoded;
    }

    /** @return array{status:int,body:string} */
    private static function request(string $url, int $timeout): array
    {
        if (function_exists('curl_init')) {
            return self::curl($url, $timeout);
        }

        if (!ini_get('allow_url_fopen')) {
            throw new \RuntimeException(
                'Cannot make the request: the cURL extension is not loaded and allow_url_fopen is off.'
            );
        }

        // The http context's `timeout` bounds the read, not the connect, and
        // the default_socket_timeout this PHP ships with is 60s — so a stalled
        // connect would otherwise blow straight past the budget we thought we
        // had set. A total budget bounds the walk over targets as well.
        $budget  = $timeout * 2;
        $started = microtime(true);
        $failure = null;

        foreach (self::targets($url) as $target) {
            $left = $budget - (microtime(true) - $started);
            if ($left < 1) {
                break;
            }

            try {
                return self::stream($target, (int) min($timeout, $left));
            } catch (\RuntimeException $e) {
                // Keep the last one: the hostname is the last resort, so its
                // error is the one that describes why nothing worked.
                $failure = $e;
            }
        }

        throw $failure ?? new \RuntimeException("Could not reach {$url}");
    }

    /**
     * Candidate ways to dial $url: the host's IPv4 addresses first, then the
     * hostname itself. Mirrors Mailer::httpsTargets(), so the two behave alike.
     *
     * @return list<array{url:string,ip:?string,host:string}>
     */
    private static function targets(string $url): array
    {
        $parts = parse_url($url);
        $host  = is_array($parts) ? (string) ($parts['host'] ?? '') : '';
        $plain = [['url' => $url, 'ip' => null, 'host' => $host]];

        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || $host === ''
            || filter_var($host, FILTER_VALIDATE_IP) !== false
        ) {
            return $plain;
        }

        $pinned = [];
        foreach (self::ipv4($host) as $ip) {
            $pinned[] = ['url' => self::swapHost($parts, $ip), 'ip' => $ip, 'host' => $host];
        }

        return [...$pinned, ...$plain];
    }

    /**
     * The host's A records, as IPv4 literals.
     *
     * @return list<string>
     */
    private static function ipv4(string $host): array
    {
        if (!function_exists('dns_get_record')) {
            return [];
        }

        $records = @dns_get_record($host, DNS_A);
        if (!is_array($records)) {
            return [];
        }

        $ips = [];
        foreach ($records as $record) {
            $ip = $record['ip'] ?? null;
            if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                $ips[] = $ip;
            }
            if (count($ips) >= self::MAX_PINNED) {
                break;
            }
        }

        return $ips;
    }

    /** @param array<string,mixed> $parts */
    private static function swapHost(array $parts, string $ip): string
    {
        $user = (string) ($parts['user'] ?? '');
        $pass = (string) ($parts['pass'] ?? '');
        $auth = $user === '' ? '' : $user . ($pass === '' ? '' : ':' . $pass) . '@';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return (string) $parts['scheme'] . '://' . $auth . $ip . $port
            . (string) ($parts['path'] ?? '/')
            . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    /**
     * One attempt over PHP's HTTP stream wrapper.
     *
     * @param array{url:string,ip:?string,host:string} $target
     * @return array{status:int,body:string}
     */
    private static function stream(array $target, int $timeout): array
    {
        $headers = ['Accept: application/json', 'Connection: close'];

        // Dialling an address hides the name from the wrapper, so the Host
        // header has to be put back by hand or the edge will not route it.
        if ($target['ip'] !== null) {
            $headers[] = 'Host: ' . $target['host'];
        }

        $ssl = ['peer_name' => $target['host'], 'verify_peer' => true, 'verify_peer_name' => true];
        if (($ca = self::caBundle()) !== null) {
            $ssl['cafile'] = $ca;
        }

        $context = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'header'        => implode("\r\n", $headers),
                'timeout'       => $timeout,
                'ignore_errors' => true,   // read a 4xx/5xx body instead of raising a warning
            ],
            'ssl' => $ssl,
        ]);

        // $http_response_header is filled in by this call, in this scope — the
        // try/finally below does not change that.
        $socket = ini_get('default_socket_timeout');
        ini_set('default_socket_timeout', (string) $timeout);
        try {
            $body = @file_get_contents($target['url'], false, $context);
        } finally {
            if (is_string($socket) && $socket !== '') {
                ini_set('default_socket_timeout', $socket);
            }
        }

        if ($body === false) {
            throw new \RuntimeException(
                "Could not reach {$target['url']}: " . (error_get_last()['message'] ?? 'the request failed')
            );
        }

        // A redirect chain produces several status lines; the last one is real.
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                $status = (int) $m[1];
            }
        }

        return ['status' => $status, 'body' => $body];
    }

    /** @return array{status:int,body:string} */
    private static function curl(string $url, int $timeout): array
    {
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_FOLLOWLOCATION => true,   // the geocoder answers 307 before the real reply
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
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

    /**
     * The CA bundle to verify against, or null to use PHP's own defaults.
     *
     * HTTP_CA_BUNDLE is the canonical name; MAIL_CA_BUNDLE is honoured so an
     * install that already fixed the mail transport needs no new setting. The
     * two php.ini keys are last because this PHP sets neither.
     */
    private static function caBundle(): ?string
    {
        if (self::$caBundle !== false) {
            return self::$caBundle;
        }

        foreach (['HTTP_CA_BUNDLE', 'MAIL_CA_BUNDLE'] as $key) {
            $value = trim((string) Config::get($key, ''));
            if ($value !== '' && is_file($value)) {
                return self::$caBundle = $value;
            }
        }

        foreach (['openssl.cafile', 'curl.cainfo'] as $key) {
            $value = trim((string) ini_get($key));
            if ($value !== '' && is_file($value)) {
                return self::$caBundle = $value;
            }
        }

        return self::$caBundle = null;
    }
}
