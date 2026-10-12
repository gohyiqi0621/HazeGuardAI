<?php
declare(strict_types=1);

namespace HazeGuard\Component\Hazeguard\Site\Model;

use HazeGuard\Config;
use HazeGuard\Database;
use HazeGuard\Mailer;
use HazeGuard\RateLimiter;
use HazeGuard\Session;
use HazeGuard\Validator;

/**
 * The authentication model: registration, email verification, password login
 * and email-OTP login. Each method returns a result array that the view maps
 * straight onto the JSON envelope:
 *
 *   ['ok' => bool, 'code' => string, 'message' => string,
 *    'fields' => array<string,string>, 'data' => mixed]
 *
 * Enumeration defence: password login always returns the same generic message
 * for "no such account" and "wrong password", and OTP requests always report
 * success whether or not the address exists.
 */
final class AuthModel
{
    /**
     * A real bcrypt hash of an unguessable value, verified against when the
     * account does not exist so that "no such user" and "wrong password" take
     * roughly the same time (defeats timing-based user enumeration).
     */
    private const DUMMY_HASH = '$2y$10$CHPDJSplaqKILXzoEYvOWeC3QpWiDAIu8tYva8Xi18k.tGXPFq95.';

    private const RATE_WINDOW = 900; // 15 minutes

    /** usergroups.slug every new account is filed under. */
    private const DEFAULT_GROUP = 'registered';

    private TokenModel $tokens;

    public function __construct()
    {
        $this->tokens = new TokenModel();
    }

    /** @return array{ok:bool,code:string,message:string,fields:array<string,string>,data:mixed} */
    public function register(string $username, string $email, string $password): array
    {
        $username = trim($username);
        $email = strtolower(trim($email));

        $fields = Validator::collect([
            'username' => Validator::username($username),
            'email'    => Validator::email($email),
            'password' => Validator::password($password),
        ]);

        if ($fields === []) {
            if ($this->findBy('username', $username) !== null) {
                $fields['username'] = 'That username is already taken.';
            }
            if ($this->findBy('email', $email) !== null) {
                $fields['email'] = 'That email is already registered.';
            }
        }

        if ($fields !== []) {
            return $this->fail('validation_error', 'Please fix the highlighted fields.', $fields);
        }

        // users.usergroup_id is NOT NULL and a foreign key, so every new account
        // is filed under the default "registered" group.
        $groupId = (int) Database::run(
            'SELECT id FROM usergroups WHERE slug = :slug',
            ['slug' => self::DEFAULT_GROUP]
        )->fetchColumn();

        Database::run(
            'INSERT INTO users (username, email, password_hash, usergroup_id, status, created_at, updated_at)
             VALUES (:u, :e, :h, :g, :s, :now, :now2)',
            [
                'u'    => $username,
                'e'    => $email,
                'h'    => password_hash($password, PASSWORD_DEFAULT),
                'g'    => $groupId,
                's'    => 'pending',
                'now'  => Database::now(),
                'now2' => Database::now(),
            ]
        );
        $userId = (int) Database::pdo()->lastInsertId();

        $code = $this->tokens->issueOtp($userId, TokenModel::PURPOSE_VERIFY_EMAIL);

        $reason = $this->deliver(static fn () => Mailer::sendVerificationCode($email, $code));
        if ($reason !== null) {
            // The account and the code are already stored, so this is
            // recoverable — "Resend code" tries again. Saying so matters: an
            // operator who reads only "failed" retries the form and is then told
            // the username is taken.
            return $this->fail(
                'mail_failed',
                'Your account was created, but the verification email could not be sent'
                . " ({$reason}). Use \"Resend code\" to try again.",
                [],
                502
            );
        }

        return $this->pass(['email' => $email, 'username' => $username]);
    }

    /** @return array{ok:bool,code:string,message:string,fields:array<string,string>,data:mixed} */
    public function verifyEmail(string $email, string $code): array
    {
        $email = strtolower(trim($email));
        $user = $this->findBy('email', $email);

        if ($user === null) {
            return $this->fail('invalid_code', 'That code is not valid. Request a new one and try again.');
        }
        if ($user['email_verified_at'] !== null) {
            return $this->pass(['already_verified' => true]);
        }

        $result = $this->tokens->verifyOtp((int) $user['id'], TokenModel::PURPOSE_VERIFY_EMAIL, trim($code));
        if (!$result['ok']) {
            return $this->fail('invalid_code', $this->otpMessage($result['reason']));
        }

        Database::run(
            'UPDATE users SET email_verified_at = :now, status = :s, updated_at = :now2 WHERE id = :id',
            ['now' => Database::now(), 's' => 'active', 'now2' => Database::now(), 'id' => $user['id']]
        );

        // A courtesy message, not part of the transaction: the account is
        // already active, so a delivery failure must not undo a successful
        // verification. deliver() logs the reason.
        $this->deliver(static fn () => Mailer::sendWelcome($email, (string) $user['username']));

        return $this->pass(['verified' => true]);
    }

    /** @return array{ok:bool,code:string,message:string,fields:array<string,string>,data:mixed} */
    public function resendVerification(string $email, string $ip): array
    {
        $email = strtolower(trim($email));

        if (!RateLimiter::attempt("resend:email:{$email}", 3, self::RATE_WINDOW)
            || !RateLimiter::attempt("resend:ip:{$ip}", 10, self::RATE_WINDOW)) {
            return $this->fail('rate_limited', 'Too many requests. Please wait a few minutes and try again.');
        }

        $user = $this->findBy('email', $email);
        // Always report success so the endpoint cannot be used to probe accounts.
        if ($user !== null && $user['email_verified_at'] === null) {
            $code = $this->tokens->issueOtp((int) $user['id'], TokenModel::PURPOSE_VERIFY_EMAIL);
            // Same reason: a failure that only ever happens for a real address
            // would confirm that address exists. Log it, report success.
            $this->deliver(static fn () => Mailer::sendVerificationCode($email, $code));
        }

        return $this->pass(['sent' => true]);
    }

    /** @return array{ok:bool,code:string,message:string,fields:array<string,string>,data:mixed} */
    public function login(string $identifier, string $password, string $ip): array
    {
        $identifier = trim($identifier);

        if (!RateLimiter::attempt("login:ip:{$ip}", 20, self::RATE_WINDOW)) {
            return $this->fail('rate_limited', 'Too many attempts. Please wait a few minutes and try again.', [], 429);
        }

        $user = $this->findBy('email', strtolower($identifier)) ?? $this->findBy('username', $identifier);

        if ($user === null) {
            password_verify($password, self::DUMMY_HASH); // equalise timing
            return $this->fail('invalid_credentials', 'Incorrect email/username or password.', [], 401);
        }

        if (!password_verify($password, (string) $user['password_hash'])) {
            return $this->fail('invalid_credentials', 'Incorrect email/username or password.', [], 401);
        }

        // Only reveal account state once the password is known to be correct.
        if ($user['email_verified_at'] === null) {
            return $this->fail('email_not_verified', 'Verify your email before signing in. We can send a new code.', [], 403);
        }
        if ($user['status'] === 'suspended') {
            return $this->fail('account_suspended', 'This account is suspended. Contact support if you think this is a mistake.', [], 403);
        }

        RateLimiter::clear("login:ip:{$ip}");
        // One shape for the session and for the response, so the identity the
        // client renders cannot drift from the identity the server stored.
        $public = $this->publicUser($user);
        Session::login($public);

        return $this->pass(['user' => $public]);
    }

    /** @return array{ok:bool,code:string,message:string,fields:array<string,string>,data:mixed} */
    public function requestLoginOtp(string $email, string $ip): array
    {
        $email = strtolower(trim($email));

        if (!RateLimiter::attempt("otp:email:{$email}", 3, self::RATE_WINDOW)
            || !RateLimiter::attempt("otp:ip:{$ip}", 10, self::RATE_WINDOW)) {
            return $this->fail('rate_limited', 'Too many code requests. Please wait a few minutes and try again.', [], 429);
        }

        $user = $this->findBy('email', $email);
        if ($user !== null && $user['status'] === 'active') {
            $code = $this->tokens->issueOtp((int) $user['id'], TokenModel::PURPOSE_LOGIN);
            // Always report success, so the endpoint cannot probe accounts.
            $this->deliver(static fn () => Mailer::sendLoginOtp($email, $code));
        }

        return $this->pass(['sent' => true]);
    }

    /** @return array{ok:bool,code:string,message:string,fields:array<string,string>,data:mixed} */
    public function verifyLoginOtp(string $email, string $code, string $ip): array
    {
        $email = strtolower(trim($email));

        if (!RateLimiter::attempt("otpverify:ip:{$ip}", 20, self::RATE_WINDOW)) {
            return $this->fail('rate_limited', 'Too many attempts. Please wait a few minutes and try again.', [], 429);
        }

        $user = $this->findBy('email', $email);
        if ($user === null || $user['status'] !== 'active') {
            return $this->fail('invalid_code', 'That code is not valid or has expired.', [], 401);
        }

        $result = $this->tokens->verifyOtp((int) $user['id'], TokenModel::PURPOSE_LOGIN, trim($code));
        if (!$result['ok']) {
            return $this->fail('invalid_code', $this->otpMessage($result['reason']), [], 401);
        }

        RateLimiter::clear("otpverify:ip:{$ip}");
        // One shape for the session and for the response, so the identity the
        // client renders cannot drift from the identity the server stored.
        $public = $this->publicUser($user);
        Session::login($public);

        return $this->pass(['user' => $public]);
    }

    /**
     * Email a password-reset code.
     *
     * Always reports success. Only an address that exists and is active reaches
     * the send, so any failure reported here would confirm that address is real
     * — the same reason resendVerification() is silent.
     *
     * @return array{ok:bool,code:string,message:string,fields:array<string,string>,data:mixed}
     */
    public function requestReset(string $email, string $ip): array
    {
        $email = strtolower(trim($email));

        // Validated before the limiter: a typo is not an attack, and it should
        // not spend a real user's budget.
        $bad = Validator::email($email);
        if ($bad !== null) {
            return $this->fail('validation_error', 'Please fix the highlighted fields.', ['email' => $bad]);
        }

        if (!RateLimiter::attempt("reset:email:{$email}", 3, self::RATE_WINDOW)
            || !RateLimiter::attempt("reset:ip:{$ip}", 10, self::RATE_WINDOW)) {
            return $this->fail('rate_limited', 'Too many requests. Please wait a few minutes and try again.', [], 429);
        }

        $user = $this->findBy('email', $email);
        // Only an active account gets a code. A pending account has never proven
        // the address, so resetting its password would not make it usable, and a
        // suspended one must not be reachable this way either.
        if ($user !== null && $user['status'] === 'active') {
            $code = $this->tokens->issueOtp((int) $user['id'], TokenModel::PURPOSE_RESET_PASSWORD);
            // The reason is logged, never returned. Unlike register()'s
            // mail_failed, surfacing it here would leak that the address exists.
            $this->deliver(static fn () => Mailer::sendPasswordResetCode($email, $code));
        }

        return $this->pass(['sent' => true]);
    }

    /**
     * Redeem a reset code and set the new password.
     *
     * @return array{ok:bool,code:string,message:string,fields:array<string,string>,data:mixed}
     */
    public function resetPassword(string $email, string $code, string $password, string $ip): array
    {
        // The password is judged first so a rejected one does not spend the code.
        // The code is the scarce thing here: burning it on a typo in an unrelated
        // field would make the visitor request another just to fix their mistake.
        $fields = Validator::collect(['password' => Validator::password($password)]);
        if ($fields !== []) {
            return $this->fail('validation_error', 'Please fix the highlighted fields.', $fields);
        }

        $email = strtolower(trim($email));

        // The per-code cap in verifyOtp() bounds guessing against one address;
        // this bounds spraying across many addresses from one client.
        if (!RateLimiter::attempt("resetverify:ip:{$ip}", 20, self::RATE_WINDOW)) {
            return $this->fail('rate_limited', 'Too many attempts. Please wait a few minutes and try again.', [], 429);
        }

        $user = $this->findBy('email', $email);
        if ($user === null || $user['status'] !== 'active') {
            // Answered exactly as a wrong code is, so this endpoint cannot be
            // used to find out which addresses have accounts.
            return $this->fail('invalid_code', $this->otpMessage('missing'), [], 401);
        }

        $result = $this->tokens->verifyOtp((int) $user['id'], TokenModel::PURPOSE_RESET_PASSWORD, trim($code));
        if (!$result['ok']) {
            return $this->fail('invalid_code', $this->otpMessage($result['reason']), [], 401);
        }

        Database::run(
            'UPDATE users SET password_hash = :h, password_changed_at = :now2, updated_at = :now WHERE id = :id',
            [
                'h'    => password_hash($password, PASSWORD_DEFAULT),
                'now2' => Database::now(),
                'now'  => Database::now(),
                'id'   => (int) $user['id'],
            ]
        );

        RateLimiter::clear("resetverify:ip:{$ip}");

        // No session is created here. Existing sessions elsewhere are not destroyed
        // either — PHP keeps them in files, so there is no list to clear — but
        // `password_changed_at` was just stamped, so the next time one of them asks
        // who it is, GET /api/auth/session finds the session older than the change
        // and ends it. That check lives in AuthController::session().
        return $this->pass(['reset' => true]);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Send a message without letting a provider failure become a bare 500.
     *
     * @param callable():void $send
     * @return string|null the reason it did not go out, or null when it did
     */
    private function deliver(callable $send): ?string
    {
        try {
            $send();

            return null;
        } catch (\RuntimeException $e) {
            error_log('[hazeguard] mail delivery failed: ' . $e->getMessage());

            // The provider's own words are the only thing that tells an operator
            // whether to blame the key, the sender address or the IP allow-list,
            // so they are worth surfacing while APP_DEBUG is on — and worth
            // withholding in production, where they describe our configuration.
            return Config::bool('APP_DEBUG', false)
                ? $e->getMessage()
                : 'the mail provider refused the message';
        }
    }

    /** @return array<string,mixed>|null */
    private function findBy(string $column, string $value): ?array
    {
        $allowed = ['email', 'username', 'id'];
        if (!in_array($column, $allowed, true)) {
            throw new \InvalidArgumentException("Unsupported lookup column: {$column}");
        }
        $row = Database::run("SELECT * FROM users WHERE {$column} = :v LIMIT 1", ['v' => $value])->fetch();
        return $row === false ? null : $row;
    }

    /** @return array{id:int,username:string,email:string,verified:bool} */
    private function publicUser(array $user): array
    {
        return [
            'id'       => (int) $user['id'],
            'username' => (string) $user['username'],
            'email'    => (string) $user['email'],
            // Both login paths refuse an unverified account before reaching
            // here, so this is true for every session that exists — but reading
            // it off the row keeps the client's claim tied to stored data
            // rather than to that argument staying true.
            'verified' => $user['email_verified_at'] !== null,
        ];
    }

    private function otpMessage(string $reason): string
    {
        return match ($reason) {
            'expired'  => 'That code has expired. Request a new one and try again.',
            'too_many' => 'Too many incorrect attempts. Request a new code.',
            default    => 'That code is not valid. Check the digits and try again.',
        };
    }

    private function pass(mixed $data): array
    {
        return ['ok' => true, 'code' => 'ok', 'message' => '', 'fields' => [], 'data' => $data];
    }

    private function fail(string $code, string $message, array $fields = [], int $status = 400): array
    {
        return ['ok' => false, 'code' => $code, 'message' => $message, 'fields' => $fields, 'status' => $status, 'data' => null];
    }
}
