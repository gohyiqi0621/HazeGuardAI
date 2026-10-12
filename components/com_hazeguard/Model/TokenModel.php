<?php
declare(strict_types=1);

namespace HazeGuard\Component\Hazeguard\Site\Model;

use HazeGuard\Config;
use HazeGuard\Database;

/**
 * Issues and verifies the hashed credentials behind the email flows: the
 * 6-digit codes used for email verification, OTP login and password reset.
 *
 * Everything is stored as a SHA-256 hash, expires, and can be used once. The
 * codes carry a wrong-attempt cap because six digits are guessable.
 *
 * Password reset used to be a different shape — a 32-byte token emailed as a
 * link. That is gone: a link has to be built from APP_URL and clicked from the
 * device that can reach the server, so it broke whenever APP_URL was wrong and
 * was useless when the mail was read on a phone. A typed code has neither
 * problem, and it means reset shares one credential mechanism with the other
 * two flows instead of a parallel one.
 */
final class TokenModel
{
    public const PURPOSE_VERIFY_EMAIL = 'verify_email';
    public const PURPOSE_LOGIN = 'login';
    public const PURPOSE_RESET_PASSWORD = 'reset_password';

    /**
     * Invalidate any outstanding code for this user+purpose, then issue a fresh
     * one. Returns the raw code — the caller emails it and never stores it.
     */
    public function issueOtp(int $userId, string $purpose): string
    {
        Database::run(
            'UPDATE email_otps SET used_at = :now
             WHERE user_id = :u AND purpose = :p AND used_at IS NULL',
            ['now' => Database::now(), 'u' => $userId, 'p' => $purpose]
        );

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        Database::run(
            'INSERT INTO email_otps (user_id, purpose, otp_hash, expires_at)
             VALUES (:u, :p, :h, :e)',
            [
                'u' => $userId,
                'p' => $purpose,
                'h' => $this->hash($code),
                'e' => Database::future(Config::int('OTP_TTL_SECONDS', 300)),
            ]
        );

        return $code;
    }

    /**
     * @return array{ok:bool, reason:string}
     *   reason: ok | missing | expired | mismatch | too_many
     */
    public function verifyOtp(int $userId, string $purpose, string $code): array
    {
        $row = Database::run(
            'SELECT id, otp_hash, expires_at, attempt_count
             FROM email_otps
             WHERE user_id = :u AND purpose = :p AND used_at IS NULL
             ORDER BY id DESC LIMIT 1',
            ['u' => $userId, 'p' => $purpose]
        )->fetch();

        if (!$row) {
            return ['ok' => false, 'reason' => 'missing'];
        }
        if ($row['expires_at'] <= Database::now()) {
            return ['ok' => false, 'reason' => 'expired'];
        }

        $attempts = (int) $row['attempt_count'] + 1;
        Database::run('UPDATE email_otps SET attempt_count = :a WHERE id = :id', ['a' => $attempts, 'id' => $row['id']]);

        if ($attempts > Config::int('OTP_MAX_ATTEMPTS', 5)) {
            // burn the code so it cannot be brute-forced further
            Database::run('UPDATE email_otps SET used_at = :now WHERE id = :id', ['now' => Database::now(), 'id' => $row['id']]);
            return ['ok' => false, 'reason' => 'too_many'];
        }

        if (!hash_equals((string) $row['otp_hash'], $this->hash($code))) {
            return ['ok' => false, 'reason' => 'mismatch'];
        }

        Database::run('UPDATE email_otps SET used_at = :now WHERE id = :id', ['now' => Database::now(), 'id' => $row['id']]);
        return ['ok' => true, 'reason' => 'ok'];
    }

    private function hash(string $value): string
    {
        return hash('sha256', $value);
    }
}
