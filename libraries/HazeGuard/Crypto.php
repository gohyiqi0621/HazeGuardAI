<?php
declare(strict_types=1);

namespace HazeGuard;

/**
 * Reversible encryption for secrets that have to be read back — an API key the
 * application must send to a provider. Passwords are hashed instead (see
 * AdminModel's `hash` flag); a credential that cannot be recovered is useless.
 *
 * AES-256-GCM, so the value is both confidential and tamper-evident. The key
 * lives in `.env` as CREDENTIAL_KEY and never in the database, which means a
 * stolen dump is not enough to read the keys.
 *
 * A stored value looks like `enc:v1:<base64 of nonce|tag|ciphertext>`. The
 * version prefix leaves room to change algorithm later, and lets a value
 * written before encryption existed be recognised and migrated.
 */
final class Crypto
{
    private const PREFIX = 'enc:v1:';

    private const NONCE_BYTES = 12;
    private const TAG_BYTES   = 16;

    /** Encrypt for storage. Null and empty pass through untouched. */
    public static function encrypt(?string $plain): ?string
    {
        if ($plain === null || $plain === '') {
            return $plain;
        }

        $nonce = random_bytes(self::NONCE_BYTES);
        $tag   = '';
        $raw   = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $nonce, $tag);

        if ($raw === false) {
            throw new \RuntimeException('Could not encrypt the secret.');
        }

        return self::PREFIX . base64_encode($nonce . $tag . $raw);
    }

    /**
     * Decrypt a stored value.
     *
     * A value without the prefix is returned as it is: it was written before
     * encryption existed. That keeps a half-migrated table readable rather than
     * throwing, and lets the migration be re-run safely.
     */
    public static function decrypt(?string $stored): ?string
    {
        if ($stored === null || $stored === '' || !str_starts_with($stored, self::PREFIX)) {
            return $stored;
        }

        $blob = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($blob === false || strlen($blob) < self::NONCE_BYTES + self::TAG_BYTES) {
            throw new \RuntimeException('Stored secret is corrupt.');
        }

        $nonce = substr($blob, 0, self::NONCE_BYTES);
        $tag   = substr($blob, self::NONCE_BYTES, self::TAG_BYTES);
        $raw   = substr($blob, self::NONCE_BYTES + self::TAG_BYTES);

        $plain = openssl_decrypt($raw, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $nonce, $tag);
        if ($plain === false) {
            throw new \RuntimeException('Stored secret could not be decrypted. Has CREDENTIAL_KEY changed?');
        }

        return $plain;
    }

    /** True when a stored value is already ciphertext. */
    public static function isEncrypted(?string $stored): bool
    {
        return $stored !== null && str_starts_with($stored, self::PREFIX);
    }

    /** The 32-byte key, from CREDENTIAL_KEY (64 hex characters). */
    private static function key(): string
    {
        $hex = Config::require('CREDENTIAL_KEY');
        $key = ctype_xdigit($hex) ? hex2bin($hex) : false;

        if ($key === false || strlen($key) !== 32) {
            throw new \RuntimeException('CREDENTIAL_KEY must be 64 hex characters (32 bytes).');
        }

        return $key;
    }
}
