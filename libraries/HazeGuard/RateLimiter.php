<?php
declare(strict_types=1);

namespace HazeGuard;

/**
 * Fixed-window rate limiter backed by the `rate_limits` table.
 * A bucket is a namespaced key, e.g. "otp:email:203.0.113.7" or "login:ip:10.0.0.1".
 */
final class RateLimiter
{
    /**
     * Record one attempt against a bucket and report whether it is allowed.
     * The window resets once `$windowSeconds` have elapsed since its start.
     */
    public static function attempt(string $bucket, int $limit, int $windowSeconds): bool
    {
        $now = Database::now();
        $cutoff = Database::past($windowSeconds);

        // Atomic upsert: reset the window when it has expired, otherwise increment.
        Database::run(
            'INSERT INTO rate_limits (bucket, hits, window_start)
             VALUES (:bucket, 1, :now)
             ON DUPLICATE KEY UPDATE
               hits = IF(window_start <= :cutoff, 1, hits + 1),
               window_start = IF(window_start <= :cutoff2, :now2, window_start)',
            [
                'bucket'  => $bucket,
                'now'     => $now,
                'cutoff'  => $cutoff,
                'cutoff2' => $cutoff,
                'now2'    => $now,
            ]
        );

        $hits = (int) Database::run('SELECT hits FROM rate_limits WHERE bucket = :b', ['b' => $bucket])
            ->fetchColumn();

        return $hits <= $limit;
    }

    /** Clear a bucket — call after a successful action so honest users aren't penalised. */
    public static function clear(string $bucket): void
    {
        Database::run('DELETE FROM rate_limits WHERE bucket = :b', ['b' => $bucket]);
    }

    /** Best-effort client IP for bucketing (behind a proxy, prefer the forwarded value). */
    public static function clientIp(): string
    {
        $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($forwarded !== '') {
            $first = trim(explode(',', $forwarded)[0]);
            if ($first !== '') {
                return $first;
            }
        }
        return (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    }
}
