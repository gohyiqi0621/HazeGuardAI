<?php
declare(strict_types=1);

namespace HazeGuard;

/**
 * Session-bound CSRF tokens. The token is created lazily, kept server-side in
 * the session, and compared in constant time.
 *
 * The JSON API echoes it in the `X-CSRF-Token` header; the administrator
 * dashboard embeds it as a hidden form field.
 *
 * Joomla's equivalent is `Joomla\CMS\Session\Session::checkToken()`.
 */
final class Csrf
{
    private const SESSION_KEY = '_csrf_token';

    public static function token(): string
    {
        Session::start();
        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION[self::SESSION_KEY];
    }

    public static function validate(?string $token): bool
    {
        Session::start();
        $expected = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_string($expected) || $expected === '' || !is_string($token) || $token === '') {
            return false;
        }
        return hash_equals($expected, $token);
    }

    /** Abort with 403 unless a valid CSRF token accompanies the request. */
    public static function requireValid(?string $token): void
    {
        if (!self::validate($token)) {
            Response::error('csrf_invalid', 'Your session expired. Refresh the page and try again.', 403);
        }
    }

    /** Rotate the token (e.g. after login) so a pre-auth token cannot be reused. */
    public static function rotate(): string
    {
        Session::start();
        $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        return (string) $_SESSION[self::SESSION_KEY];
    }
}
