<?php
declare(strict_types=1);

namespace HazeGuard;

/**
 * Server-side session handling: hardened cookie, id regeneration on privilege
 * change, and the authenticated-user accessor used by protected routes.
 *
 * Joomla's equivalent is `Joomla\CMS\Session\Session` plus the `$app->getSession()`
 * object; the behaviour here is the same, minus the framework plumbing.
 */
final class Session
{
    private const USER_KEY = '_auth_user';

    /**
     * When this session was granted, stored beside the identity rather than
     * inside it.
     *
     * A separate key on purpose: the payload under USER_KEY is returned to the
     * client verbatim, and `issued_at` is server bookkeeping the client has no
     * use for. Keeping it apart means the guarantee that the login response and
     * the stored identity are the same value still holds exactly.
     */
    private const ISSUED_AT_KEY = '_auth_issued_at';

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || Config::isProduction();
        session_name(Config::get('SESSION_NAME', 'hzgsid') ?? 'hzgsid');
        session_set_cookie_params([
            'lifetime' => Config::int('SESSION_LIFETIME', 1209600),
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => $secure,
        ]);
        session_start();
    }

    /** Regenerate the id (and rotate CSRF) after a privilege change. */
    public static function regenerate(): void
    {
        self::start();
        session_regenerate_id(true);
        Csrf::rotate();
    }

    /** @param array{id:int,username:string,email:string,verified?:bool} $user */
    public static function login(array $user): void
    {
        self::start();
        self::regenerate();
        $_SESSION[self::USER_KEY] = [
            'id'       => (int) $user['id'],
            'username' => (string) $user['username'],
            'email'    => (string) $user['email'],
            // Captured at sign-in from the row both login paths already loaded,
            // so it costs no extra query. It describes the session as granted,
            // not the row as it stands now: an account suspended mid-session
            // keeps this value until the session ends. Re-reading the row on
            // every request would buy only that one case.
            'verified' => (bool) ($user['verified'] ?? false),
        ];

        // PHP stores sessions in files, so a password change has no list of them
        // to invalidate. Comparing this against users.password_changed_at is how a
        // session that predates the change is recognised; see
        // AuthController::sessionOutlivedPasswordChange().
        $_SESSION[self::ISSUED_AT_KEY] = time();
    }

    /** When this session was granted, or null for one minted before it existed. */
    public static function issuedAt(): ?int
    {
        self::start();
        $at = $_SESSION[self::ISSUED_AT_KEY] ?? null;

        return is_int($at) ? $at : null;
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool) ($params['secure'] ?? false), true);
        }
        session_destroy();
    }

    /** @return array{id:int,username:string,email:string,verified:bool}|null */
    public static function user(): ?array
    {
        self::start();
        $user = $_SESSION[self::USER_KEY] ?? null;
        return is_array($user) ? $user : null;
    }

    public static function userId(): ?int
    {
        $user = self::user();
        return $user['id'] ?? null;
    }

    public static function isAuthenticated(): bool
    {
        return self::user() !== null;
    }

    /** @return array{id:int,username:string,email:string,verified:bool} */
    public static function requireAuth(): array
    {
        $user = self::user();
        if ($user === null) {
            Response::error('unauthenticated', 'Please sign in to continue.', 401);
        }
        return $user;
    }
}
