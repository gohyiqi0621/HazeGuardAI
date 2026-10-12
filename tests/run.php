<?php
declare(strict_types=1);

/**
 * HazeGuard AI — backend acceptance tests (Stage 1B scope).
 * Covers: schema (T1B.6), register + email verification (T1B.1),
 * password login (T1B.2), email-OTP login (T1B.3), plus CSRF and rate limiting.
 *
 * Usage:  php tests/run.php
 *
 * Runs against an isolated database (`hazeguard_test`) so development data is
 * never touched. Exit code 0 = all pass, 1 = at least one failure.
 */

require dirname(__DIR__) . '/bootstrap.php';

// Buffer output: sessions cannot start once anything has been echoed.
ob_start();

use HazeGuard\Component\Hazeguard\Administrator\Model\AdminModel;
use HazeGuard\Component\Hazeguard\Administrator\Model\DashboardModel;
use HazeGuard\Component\Hazeguard\Administrator\Model\Entities;
use HazeGuard\Component\Hazeguard\Administrator\Model\UserAdminModel;
use HazeGuard\Component\Hazeguard\Site\Controller\AirController;
use HazeGuard\Component\Hazeguard\Site\Model\AirModel;
use HazeGuard\Component\Hazeguard\Site\Model\AuthModel;
use HazeGuard\Component\Hazeguard\Site\Model\PlacesModel;
use HazeGuard\Component\Hazeguard\Site\Model\ProfileModel;
use HazeGuard\Config;
use HazeGuard\Crypto;
use HazeGuard\Csrf;
use HazeGuard\Database;
use HazeGuard\Http;
use HazeGuard\Mailer;
use HazeGuard\RateLimiter;
use HazeGuard\Request;
use HazeGuard\Session;

$auth = new AuthModel(); // the model under test

// ---------------------------------------------------------------- test harness
$GLOBALS['pass'] = 0;
$GLOBALS['fail'] = 0;
$GLOBALS['failures'] = [];

function group(string $title): void
{
    echo "\n" . $title . "\n" . str_repeat('-', strlen($title)) . "\n";
}

function check(string $name, bool $ok, string $detail = ''): void
{
    if ($ok) {
        $GLOBALS['pass']++;
        echo "  PASS  {$name}\n";
    } else {
        $GLOBALS['fail']++;
        $GLOBALS['failures'][] = $name . ($detail !== '' ? " — {$detail}" : '');
        echo "  FAIL  {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

// ------------------------------------------------------------- test database
$TEST_DB = 'hazeguard_test';
$host = Config::get('DB_HOST', '127.0.0.1');
$port = Config::get('DB_PORT', '3306');
$user = Config::get('DB_USER', 'root');
$pass = Config::get('DB_PASS', '') ?? '';

$server = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
// Recreated from scratch rather than having its tables dropped one by one: a
// table left behind by an older schema would otherwise block the drop of a
// parent it still references. This database is only ever used by this suite.
$server->exec("DROP DATABASE IF EXISTS `{$TEST_DB}`");
$server->exec("CREATE DATABASE `{$TEST_DB}` DEFAULT CHARACTER SET utf8mb4 DEFAULT COLLATE utf8mb4_unicode_ci");
$server->exec("USE `{$TEST_DB}`");
foreach (splitSql(file_get_contents(dirname(__DIR__) . '/database/schema.sql') ?: '') as $stmt) {
    $server->exec($stmt);
}

Config::set('DB_NAME', $TEST_DB);
Config::set('MAIL_DRIVER', 'log');
Config::set('MAIL_LOG_DIR', 'storage/mail-test');
Database::reset();

$mailDir = dirname(__DIR__) . '/storage/mail-test';
if (is_dir($mailDir)) {
    foreach (glob($mailDir . '/*.txt') ?: [] as $f) {
        @unlink($f);
    }
}

/** Split a .sql file into statements, dropping comment lines. */
function splitSql(string $sql): array
{
    $lines = [];
    foreach (explode("\n", $sql) as $line) {
        $trimmed = ltrim($line);
        if (str_starts_with($trimmed, '--') || $trimmed === '') {
            continue;
        }
        $lines[] = $line;
    }
    $out = [];
    foreach (explode(';', implode("\n", $lines)) as $stmt) {
        $stmt = trim($stmt);
        if ($stmt !== '') {
            $out[] = $stmt;
        }
    }
    return $out;
}

/**
 * Newest message written to `$email` by the log mail driver, or null.
 * Filenames are microsecond-stamped, so a descending name sort is chronological.
 */
function latestMail(string $dir, string $email): ?string
{
    $matches = [];
    foreach (glob($dir . '/*.txt') ?: [] as $f) {
        if (str_contains((string) file_get_contents($f), "To: {$email}\n")) {
            $matches[] = $f;
        }
    }
    if ($matches === []) {
        return null;
    }
    rsort($matches);
    return (string) file_get_contents($matches[0]);
}

/** Newest 6-digit code written to `$email`. */
function latestMailCode(string $dir, string $email): ?string
{
    $body = latestMail($dir, $email);
    return $body !== null && preg_match('/\b(\d{6})\b/', $body, $m) === 1 ? $m[1] : null;
}

/**
 * Newest password-reset code written to `$email`, or null.
 *
 * Scans for the reset message itself rather than taking the newest message: a
 * verification code may have been sent afterwards, and it is six digits too, so
 * taking the newest would silently test the wrong code.
 */
function latestResetCode(string $dir, string $email): ?string
{
    $found = [];
    foreach (glob($dir . '/*.txt') ?: [] as $f) {
        $body = (string) file_get_contents($f);
        if (str_contains($body, "To: {$email}\n")
            && str_contains($body, 'reset the password')
            && preg_match('/\b(\d{6})\b/', $body, $m) === 1
        ) {
            $found[$f] = $m[1];
        }
    }
    if ($found === []) {
        return null;
    }
    krsort($found); // filenames are microsecond-stamped, so this is chronological
    return (string) reset($found);
}

/**
 * How many reset codes this user currently has live (unused and unexpired).
 *
 * Asked of the database rather than the mail log: the log is cumulative, so
 * "was a new code sent?" cannot be answered by looking for any code at all.
 */
function liveResetCodes(int $userId): int
{
    return (int) Database::run(
        "SELECT COUNT(*) FROM email_otps
          WHERE user_id = :u AND purpose = 'reset_password'
            AND used_at IS NULL AND expires_at > :now",
        ['u' => $userId, 'now' => Database::now()]
    )->fetchColumn();
}

function unique(string $prefix): string
{
    return $prefix . bin2hex(random_bytes(4));
}

$PW = 'correct-horse-9';

// ---------------------------------------------------------------------- T1B.6
group('T1B.6 — schema');
$tables = Database::run('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
foreach (['users', 'email_verification_tokens', 'email_otps', 'rate_limits'] as $t) {
    check("table exists: {$t}", in_array($t, $tables, true));
}
// Password reset moved onto email_otps; the link-token table was dropped with it.
check('the retired password_reset_tokens table is gone',
    !in_array('password_reset_tokens', $tables, true));
$userIndexes = Database::run('SHOW INDEX FROM users')->fetchAll();
$uniqueCols = [];
foreach ($userIndexes as $idx) {
    if ((int) $idx['Non_unique'] === 0) {
        $uniqueCols[$idx['Key_name']][] = $idx['Column_name'];
    }
}
check('users has a UNIQUE index on email',
    in_array(['email'], array_values($uniqueCols), true));
check('users has a UNIQUE index on username',
    in_array(['username'], array_values($uniqueCols), true));
$fk = Database::run(
    "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
     WHERE TABLE_SCHEMA = :db AND TABLE_NAME = 'email_otps' AND REFERENCED_TABLE_NAME = 'users'",
    ['db' => $TEST_DB]
)->fetchColumn();
check('email_otps has a foreign key to users', (int) $fk > 0);

// ---------------------------------------------------------------------- T1B.1
group('T1B.1 — registration + email verification');
$u1 = unique('alice');
$e1 = $u1 . '@example.test';
$reg = $auth->register($u1, $e1, $PW);
check('register returns ok', $reg['ok'] === true, $reg['message']);

$row = Database::run('SELECT * FROM users WHERE email = :e', ['e' => $e1])->fetch();
check('user row created', $row !== false);
check('status is pending', ($row['status'] ?? '') === 'pending');
check('email_verified_at is null', $row['email_verified_at'] === null);
check('password is hashed, not plaintext', isset($row['password_hash']) && $row['password_hash'] !== $PW);
check('password verifies with password_verify',
    isset($row['password_hash']) && password_verify($PW, (string) $row['password_hash']));

$code1 = latestMailCode($mailDir, $e1);
check('verification code was emailed', $code1 !== null);
$stored = Database::run('SELECT otp_hash FROM email_otps WHERE user_id = :u ORDER BY id DESC LIMIT 1', ['u' => $row['id']])->fetchColumn();
check('OTP stored as a hash, not plaintext', $stored !== false && $stored !== $code1);
check('OTP hash is sha256 of the code', $stored === hash('sha256', (string) $code1));

$dupU = $auth->register($u1, unique('other') . '@example.test', $PW);
check('duplicate username rejected', $dupU['ok'] === false && isset($dupU['fields']['username']));
// An unfinished sign-up is resumed rather than dead-ended on "already
// registered" (AuthModel::register), and the resume replaces the verification
// code — so re-read it before the checks that use it.
$dupE = $auth->register(unique('bob'), $e1, $PW);
check('unfinished sign-up resumes and sends a fresh code',
    $dupE['ok'] === true && ($dupE['data']['needs_verification'] ?? false) === true,
    json_encode($dupE));
$code1 = latestMailCode($mailDir, $e1);
check('the resumed sign-up emailed a new code', $code1 !== null);
$bad = $auth->register('x', 'not-an-email', 'short');
check('invalid username/email/password rejected with 3 field errors',
    $bad['ok'] === false && count($bad['fields']) === 3, json_encode($bad['fields']));

$wrong = $auth->verifyEmail($e1, '000000');
check('wrong verification code rejected', $wrong['ok'] === false && $wrong['code'] === 'invalid_code');
$ok = $auth->verifyEmail($e1, (string) $code1);
check('correct verification code accepted', $ok['ok'] === true, $ok['message']);
$after = Database::run('SELECT status, email_verified_at FROM users WHERE email = :e', ['e' => $e1])->fetch();
check('account becomes active', ($after['status'] ?? '') === 'active');
check('email_verified_at is set', ($after['email_verified_at'] ?? null) !== null);
$reuse = Database::run('SELECT used_at FROM email_otps WHERE user_id = :u ORDER BY id DESC LIMIT 1', ['u' => $row['id']])->fetchColumn();
check('verification code is marked used after success', $reuse !== false && $reuse !== null);
// the resume path above only applies to *unverified* addresses: once verified,
// the email really is closed to anyone else.
$dupVerified = $auth->register(unique('carol'), $e1, $PW);
check('duplicate email rejected', $dupVerified['ok'] === false && isset($dupVerified['fields']['email']), json_encode($dupVerified));

// expiry
$uExp = unique('exp');
$eExp = $uExp . '@example.test';
$auth->register($uExp, $eExp, $PW);
$expUser = Database::run('SELECT id FROM users WHERE email = :e', ['e' => $eExp])->fetch();
$expCode = latestMailCode($mailDir, $eExp);
Database::run('UPDATE email_otps SET expires_at = :past WHERE user_id = :u', ['past' => Database::past(60), 'u' => $expUser['id']]);
$expired = $auth->verifyEmail($eExp, (string) $expCode);
check('expired verification code rejected', $expired['ok'] === false && $expired['code'] === 'invalid_code');

// resend rate limit (3 per 15 min)
Database::run('DELETE FROM rate_limits');
$resend = [true, true, true, false];
for ($i = 0; $i < 4; $i++) {
    $r = $auth->resendVerification($eExp, '10.0.0.' . ($i + 1));
    $resend[$i] = $r['ok'];
}
check('resend allowed 3x then rate-limited', $resend === [true, true, true, false], json_encode($resend));
$resendUnknown = $auth->resendVerification('nobody@example.test', '10.9.9.9');
check('resend for unknown email still reports success (no enumeration)', $resendUnknown['ok'] === true);

// ---------------------------------------------------------------------- T1B.2
group('T1B.2 — password login (email + username)');
Database::run('DELETE FROM rate_limits');
$u2 = unique('carol');
$e2 = $u2 . '@example.test';
$auth->register($u2, $e2, $PW);

$pre = $auth->login($e2, $PW, '10.1.1.1');
check('unverified account cannot sign in', $pre['ok'] === false && $pre['code'] === 'email_not_verified');
$auth->verifyEmail($e2, (string) latestMailCode($mailDir, $e2));

Session::logout();
$byEmail = $auth->login($e2, $PW, '10.1.1.2');
check('login with email succeeds', $byEmail['ok'] === true, $byEmail['message']);
check('session established after login', Session::user() !== null && Session::user()['email'] === $e2);

Session::logout();
$byUser = $auth->login($u2, $PW, '10.1.1.3');
check('login with username succeeds', $byUser['ok'] === true);
check('session holds the right user', (Session::user()['username'] ?? null) === $u2);
check('the session records the account as verified', (Session::user()['verified'] ?? null) === true);
// The profile screen renders whatever the login response returned and whatever
// the session holds. If those two ever disagree, the screen shows one identity
// and the next request acts as another.
check('the login response is byte-for-byte the identity the session stored',
    ($byUser['data']['user'] ?? null) === Session::user(),
    json_encode($byUser['data']['user'] ?? null) . ' vs ' . json_encode(Session::user()));

// A session cookie minted before `verified` existed must not break the login
// path, and must not be read as "verified".
Session::logout();
Session::login(['id' => 1, 'username' => 'legacy', 'email' => 'legacy@example.test']);
check('a caller that omits verified gets false, not an error',
    (Session::user()['verified'] ?? null) === false);
Session::logout();

$wrongPw = $auth->login($e2, 'definitely-wrong', '10.1.1.4');
$noUser = $auth->login('ghost@example.test', 'definitely-wrong', '10.1.1.5');
check('wrong password rejected', $wrongPw['ok'] === false && $wrongPw['code'] === 'invalid_credentials');
check('unknown account gives the SAME generic message (no enumeration)',
    $noUser['message'] === $wrongPw['message'], $noUser['message'] . ' vs ' . $wrongPw['message']);

Database::run('UPDATE users SET status = :s WHERE email = :e', ['s' => 'suspended', 'e' => $e2]);
$susp = $auth->login($e2, $PW, '10.1.1.6');
check('suspended account blocked', $susp['ok'] === false && $susp['code'] === 'account_suspended');
Database::run('UPDATE users SET status = :s WHERE email = :e', ['s' => 'active', 'e' => $e2]);

Session::logout();
check('logout clears the session', Session::user() === null);

// ---------------------------------------------------------------------- T1B.3
group('T1B.3 — email OTP login');
Database::run('DELETE FROM rate_limits');
$u3 = unique('dave');
$e3 = $u3 . '@example.test';
$auth->register($u3, $e3, $PW);
$auth->verifyEmail($e3, (string) latestMailCode($mailDir, $e3));

$unknown = $auth->requestLoginOtp('ghost@example.test', '10.2.2.1');
check('OTP request for an unregistered email reports no_account (no code sent)', $unknown['ok'] === false && $unknown['code'] === 'no_account');

$req = $auth->requestLoginOtp($e3, '10.2.2.2');
check('OTP request succeeds', $req['ok'] === true);
$otp = latestMailCode($mailDir, $e3);
check('login OTP was emailed', $otp !== null);

$otpWrong = $auth->verifyLoginOtp($e3, '000000', '10.2.2.3');
check('wrong OTP rejected', $otpWrong['ok'] === false);

$otpOk = $auth->verifyLoginOtp($e3, (string) $otp, '10.2.2.4');
check('correct OTP signs the user in', $otpOk['ok'] === true, $otpOk['message']);
check('session established via OTP', Session::user() !== null);
check('the OTP path stores the same identity it returned',
    ($otpOk['data']['user'] ?? null) === Session::user());
check('the OTP session is marked verified', (Session::user()['verified'] ?? null) === true);

$otpReuse = $auth->verifyLoginOtp($e3, (string) $otp, '10.2.2.5');
check('login OTP is single-use', $otpReuse['ok'] === false);

// attempt cap
Database::run('DELETE FROM rate_limits');
$auth->requestLoginOtp($e3, '10.3.3.1');
$realOtp = latestMailCode($mailDir, $e3);
for ($i = 0; $i < 6; $i++) {
    $auth->verifyLoginOtp($e3, '111111', '10.3.3.2');
}
$capped = $auth->verifyLoginOtp($e3, (string) $realOtp, '10.3.3.3');
check('code is burned after 5 wrong attempts', $capped['ok'] === false);

// request rate limit
Database::run('DELETE FROM rate_limits');
$seq = [true, true, true, false];
for ($i = 0; $i < 4; $i++) {
    $seq[$i] = $auth->requestLoginOtp($e3, '10.4.4.' . ($i + 1))['ok'];
}
check('OTP requests allowed 3x then rate-limited', $seq === [true, true, true, false], json_encode($seq));

// ---------------------------------------------------------------------- T1B.4
group('T1B.4 — password reset');
Database::run('DELETE FROM rate_limits');

$uR = unique('erin');
$eR = $uR . '@example.test';
$auth->register($uR, $eR, $PW);
$auth->verifyEmail($eR, (string) latestMailCode($mailDir, $eR));
$idR = (int) Database::run('SELECT id FROM users WHERE email = :e', ['e' => $eR])->fetchColumn();

$unknown = $auth->requestReset('ghost@example.test', '10.5.5.1');
check('reset request for an unknown address reports success (no enumeration)', $unknown['ok'] === true);

$reqReset = $auth->requestReset($eR, '10.5.5.2');
check('reset request for a real address succeeds', $reqReset['ok'] === true);
check('the reset request and the unknown one are indistinguishable',
    $reqReset['code'] === $unknown['code'] && $reqReset['data'] === $unknown['data'],
    json_encode([$reqReset['data'], $unknown['data']]));

$code = latestResetCode($mailDir, $eR);
check('the reset code was emailed', $code !== null && strlen($code) === 6);

$stored = Database::run(
    "SELECT otp_hash FROM email_otps
      WHERE user_id = :u AND purpose = 'reset_password' ORDER BY id DESC LIMIT 1",
    ['u' => $idR]
)->fetchColumn();
check('the code is stored only as a hash, never in the clear',
    $stored === hash('sha256', (string) $code), (string) $stored);

// The whole point of the move off links: there is no URL to build, so no wrong
// APP_URL and no subdirectory install can make the credential unusable.
check('the reset mail carries no URL at all',
    !str_contains((string) latestMail($mailDir, $eR), 'http'));

// a malformed address is a field error, not a silent success
$badEmail = $auth->requestReset('not-an-email', '10.5.5.3');
check('a malformed address is rejected as a field error',
    $badEmail['ok'] === false && ($badEmail['fields']['email'] ?? null) !== null);

// ---- the happy path
$weak = $auth->resetPassword($eR, (string) $code, 'short', '10.5.5.4');
check('a too-short password is refused', $weak['ok'] === false && $weak['code'] === 'validation_error');
check('the refused password is reported against the field',
    ($weak['fields']['password'] ?? null) !== null);

// the failed attempt must NOT have consumed the code
$ok = $auth->resetPassword($eR, (string) $code, 'new-correct-horse-4', '10.5.5.5');
check('the new password is accepted', $ok['ok'] === true, $ok['message']);

Session::logout();
Database::run('DELETE FROM rate_limits');
$oldPw = $auth->login($eR, $PW, '10.5.6.1');
check('the old password no longer works', $oldPw['ok'] === false && $oldPw['code'] === 'invalid_credentials');
Session::logout();
$newPw = $auth->login($eR, 'new-correct-horse-4', '10.5.6.2');
check('the new password works', $newPw['ok'] === true, $newPw['message']);
Session::logout();

// ---- single use
$reuse = $auth->resetPassword($eR, (string) $code, 'third-correct-horse-5', '10.5.6.3');
check('the code cannot be used twice', $reuse['ok'] === false && $reuse['code'] === 'invalid_code');
$afterReuse = $auth->login($eR, 'new-correct-horse-4', '10.5.6.4');
check('a reused code does not overwrite the password set by the first use', $afterReuse['ok'] === true);
Session::logout();

// ---- absent and unknown codes are indistinguishable
$empty = $auth->resetPassword($eR, '', 'fourth-correct-horse-6', '10.5.6.5');
check('an absent code is refused', $empty['ok'] === false && $empty['code'] === 'invalid_code');
check('a spent code and an absent one give the same message (no probing)',
    $reuse['message'] === $empty['message']);

// ---- a wrong code, and the cap that stops it being guessed
Database::run('DELETE FROM rate_limits');
$auth->requestReset($eR, '10.5.7.1');
$live = (string) latestResetCode($mailDir, $eR);
// Always a different six digits, so this cannot collide with the real code.
$wrongCode = str_pad((string) (((int) $live + 1) % 1000000), 6, '0', STR_PAD_LEFT);

$mismatch = $auth->resetPassword($eR, $wrongCode, 'fifth-correct-horse-7', '10.5.7.2');
check('a wrong code is refused', $mismatch['ok'] === false && $mismatch['code'] === 'invalid_code');
check('a wrong code says what an absent one says', $mismatch['message'] === $empty['message']);

// OTP_MAX_ATTEMPTS is 5, and $mismatch was attempt 1.
for ($i = 0; $i < 4; $i++) {
    $auth->resetPassword($eR, $wrongCode, 'fifth-correct-horse-7', '10.5.7.' . (10 + $i));
}
$burned = $auth->resetPassword($eR, $wrongCode, 'fifth-correct-horse-7', '10.5.7.99');
check('the sixth wrong guess is answered differently, not silently the same',
    $burned['message'] !== $mismatch['message'], $burned['message']);
check('and guessing that far burns the code for everyone',
    $auth->resetPassword($eR, $live, 'fifth-correct-horse-7', '10.5.7.98')['ok'] === false);

// ---- expiry
Database::run('DELETE FROM rate_limits');
$auth->requestReset($eR, '10.5.8.1');
$expiring = (string) latestResetCode($mailDir, $eR);
Database::run(
    "UPDATE email_otps SET expires_at = :e
      WHERE user_id = :u AND purpose = 'reset_password' AND used_at IS NULL",
    ['e' => Database::past(60), 'u' => $idR]
);
$expired = $auth->resetPassword($eR, $expiring, 'sixth-correct-horse-8', '10.5.8.2');
check('an expired code is refused', $expired['ok'] === false && $expired['code'] === 'invalid_code');
// Unlike a link, a code has no room to explain itself, so the message matters.
check('an expired code is told apart from a wrong one',
    $expired['message'] !== $mismatch['message'], $expired['message']);

// ---- a second request retires the first code
Database::run('DELETE FROM rate_limits');
$auth->requestReset($eR, '10.5.9.1');
$firstCode = (string) latestResetCode($mailDir, $eR);
$auth->requestReset($eR, '10.5.9.2');
$secondCode = (string) latestResetCode($mailDir, $eR);
check('a second request issues a different code', $firstCode !== $secondCode);
check('and exactly one code is live at a time', liveResetCodes($idR) === 1, (string) liveResetCodes($idR));
$stale = $auth->resetPassword($eR, $firstCode, 'seventh-correct-horse-9', '10.5.9.3');
check('requesting a new code retires the previous one', $stale['ok'] === false && $stale['code'] === 'invalid_code');
$fresh = $auth->resetPassword($eR, $secondCode, 'seventh-correct-horse-9', '10.5.9.4');
check('the newest code still works', $fresh['ok'] === true);
check('a completed reset does not sign the visitor in', Session::user() === null);

// ---- only an active account gets a code
Database::run('DELETE FROM rate_limits');
$uP = unique('frank');
$eP = $uP . '@example.test';
$auth->register($uP, $eP, $PW); // pending: never verified
$idP = (int) Database::run('SELECT id FROM users WHERE email = :e', ['e' => $eP])->fetchColumn();
$pending = $auth->requestReset($eP, '10.5.10.1');
check('a pending account is answered the same as any other', $pending['ok'] === true);
check('but no code is issued for an account that never proved its address',
    liveResetCodes($idP) === 0);

Database::run('DELETE FROM rate_limits');
Database::run('UPDATE users SET status = :s WHERE email = :e', ['s' => 'suspended', 'e' => $eR]);
$suspReq = $auth->requestReset($eR, '10.5.10.2');
check('a suspended account is answered the same as any other', $suspReq['ok'] === true);
check('but no code is issued for a suspended account', liveResetCodes($idR) === 0);
Database::run('UPDATE users SET status = :s WHERE email = :e', ['s' => 'active', 'e' => $eR]);

// ---- a reset must not touch anybody else
$other = Database::run('SELECT password_hash FROM users WHERE email = :e', ['e' => $e3])->fetchColumn();
check('resetting one account leaves other accounts alone',
    is_string($other) && password_verify($PW, $other));

// ---- mail failure must not become an oracle
// The reset mail no longer depends on APP_URL, so an unknown driver is the way
// to make the send throw.
Config::set('MAIL_DRIVER', 'no-such-driver');
Database::run('DELETE FROM rate_limits');
$mailBroken = $auth->requestReset($eR, '10.5.11.1');
check('a mail failure still reports success, so it cannot confirm the address',
    $mailBroken['ok'] === true, $mailBroken['message']);
Config::set('MAIL_DRIVER', 'log');

// ---- rate limit
Database::run('DELETE FROM rate_limits');
$resetSeq = [];
foreach ([1, 2, 3, 4] as $i) {
    $resetSeq[] = $auth->requestReset($eR, '10.6.6.' . $i)['ok'];
}
check('reset requests allowed 3x then rate-limited', $resetSeq === [true, true, true, false], json_encode($resetSeq));

// ------------------------------------------------------------------- CSRF/session
group('CSRF + rate limiter');
Session::logout();
$t1 = Csrf::token();
$t2 = Csrf::token();
check('CSRF token is stable within a session', $t1 === $t2 && strlen($t1) === 64);
check('CSRF validate accepts the real token', Csrf::validate($t1) === true);
check('CSRF validate rejects a wrong token', Csrf::validate(str_repeat('a', 64)) === false);
check('CSRF validate rejects an empty token', Csrf::validate('') === false);

Database::run('DELETE FROM rate_limits');
$probe = [RateLimiter::attempt('t:probe', 2, 900), RateLimiter::attempt('t:probe', 2, 900), RateLimiter::attempt('t:probe', 2, 900)];
check('rate limiter blocks past the limit', $probe === [true, true, false], json_encode($probe));
RateLimiter::clear('t:probe');
check('rate limiter clear resets the bucket', RateLimiter::attempt('t:probe', 2, 900) === true);
Database::run('DELETE FROM rate_limits WHERE bucket = :b', ['b' => 't:probe']);

// ------------------------------------------- profile preferences (T6.2)
group('Profile — activity preferences and saved location');

$profile = new ProfileModel();

// A fresh account has no row yet, and must read the same shape as one that does.
$uPref = unique('pref');
$ePref = $uPref . '@example.test';
$auth->register($uPref, $ePref, $PW);
$auth->verifyEmail($ePref, (string) latestMailCode($mailDir, $ePref));
$auth->login($ePref, $PW, '10.9.9.1');
$prefId = (int) Session::user()['id'];

$empty = $profile->read($prefId);
check('an account that saved nothing reads an empty, usable shape',
    $empty['activities'] === [] && $empty['location'] === null,
    json_encode($empty));

$save = $profile->saveActivities($prefId, ['running', 'evening_walks']);
check('activities save', $save['ok'] === true && $save['data']['activities'] === ['running', 'evening_walks'],
    json_encode($save));

check('they survive a re-read',
    $profile->read($prefId)['activities'] === ['running', 'evening_walks']);

$save = $profile->saveActivities($prefId, ['cycling']);
check('a second save replaces the first rather than adding to it',
    $profile->read($prefId)['activities'] === ['cycling'],
    json_encode($profile->read($prefId)['activities']));

$save = $profile->saveActivities($prefId, []);
check('deselecting everything is a real value, not "no change"',
    $save['ok'] === true && $profile->read($prefId)['activities'] === []);

$save = $profile->saveActivities($prefId, ['running', 'not-an-activity']);
check('an unknown slug is refused rather than silently dropped',
    $save['ok'] === false && $save['code'] === 'validation_error',
    json_encode($save));

$save = $profile->saveActivities($prefId, ['running', 'running', 'cycling']);
check('a duplicate slug is stored once',
    $profile->read($prefId)['activities'] === ['running', 'cycling'],
    json_encode($profile->read($prefId)['activities']));

// -- the location. The name is resolved server-side, so these calls no longer
//    pass one: the city comes from the reverse geocoder, or falls back to the
//    coordinate string when it cannot be reached.
$save = $profile->saveLocation($prefId, 1.4927, 103.7414);
check('a location saves', $save['ok'] === true, json_encode($save));
check('the saved name is a place, never a raw coordinate, when the geocoder answers',
    $save['data']['location']['name'] !== '');

$loc = $profile->read($prefId)['location'];
check('it survives a re-read with its coordinates',
    $loc !== null && abs($loc['lat'] - 1.4927) < 0.0001, json_encode($loc));
check('the stored name is non-empty', $loc['name'] !== '', var_export($loc['name'], true));

// The coordinate is rounded to 4dp (~11 m). Storing the raw value would imply a
// precision neither the model grid nor the geocoder actually has.
$precise = $profile->saveLocation($prefId, 1.492712345, 103.741412345);
check('coordinates are rounded to 4dp before storage',
    $precise['data']['location']['lat'] === 1.4927
    && $precise['data']['location']['lon'] === 103.7414,
    json_encode($precise['data']['location']));

$profile->saveActivities($prefId, ['running']);
$profile->saveLocation($prefId, null, null);
$after = $profile->read($prefId);
check('clearing the location really writes NULL',
    $after['location'] === null, json_encode($after));
check('clearing the location leaves the activities alone',
    $after['activities'] === ['running'], json_encode($after));

$save = $profile->saveLocation($prefId, 999.0, 0.0);
check('an out-of-range latitude is refused',
    $save['ok'] === false && $save['code'] === 'validation_error', json_encode($save));
check('and the refused save changed nothing',
    $profile->read($prefId)['location'] === null);

// -- one account cannot read or write another's row
$uOther = unique('other');
$eOther = $uOther . '@example.test';
$auth->register($uOther, $eOther, $PW);
$auth->verifyEmail($eOther, (string) latestMailCode($mailDir, $eOther));
$otherId = (int) Database::run('SELECT id FROM users WHERE email = :e', ['e' => $eOther])->fetchColumn();

$profile->saveActivities($prefId, ['cycling']);
check('another account reads its own empty row, not this one',
    $profile->read($otherId)['activities'] === []);
check('and this account still reads its own',
    $profile->read($prefId)['activities'] === ['cycling']);

// -- change password
Database::run('DELETE FROM rate_limits');
$before = Database::run('SELECT password_changed_at FROM users WHERE id = :id', ['id' => $prefId])->fetchColumn();
check('a brand-new account has never changed its password', $before === null);

$bad = $profile->changePassword($prefId, 'wrong-current', 'a-long-enough-password', '10.9.9.2');
check('the wrong current password is refused',
    $bad['ok'] === false && $bad['code'] === 'invalid_credentials', json_encode($bad));

// A short mistyped current password must be judged as "wrong", not re-scored by
// the new-password strength rule — that told the user "use at least 8 characters"
// when the answer was simply "that is not your password".
$shortWrong = $profile->changePassword($prefId, 'abc', 'a-long-enough-password', '10.9.9.2');
check('a short wrong current password says "not correct", not a length rule',
    $shortWrong['code'] === 'invalid_credentials', json_encode($shortWrong));

$emptyCurrent = $profile->changePassword($prefId, '', 'a-long-enough-password', '10.9.9.2');
check('an empty current password is refused',
    $emptyCurrent['ok'] === false, json_encode($emptyCurrent));

// read() has to reach the client in the standard envelope, or the whole response
// collapses to a null payload and the profile screen silently shows defaults.
check('a read is wrapped in the standard result envelope',
    ($profile->result($profile->read($prefId))['ok'] ?? null) === true
    && is_array($profile->result($profile->read($prefId))['data'] ?? null));
check('a refused change leaves the hash alone',
    password_verify($PW, (string) Database::run('SELECT password_hash FROM users WHERE id = :id', ['id' => $prefId])->fetchColumn()));

$short = $profile->changePassword($prefId, $PW, 'short', '10.9.9.2');
check('a too-short new password is refused', $short['ok'] === false && $short['code'] === 'validation_error');

$same = $profile->changePassword($prefId, $PW, $PW, '10.9.9.2');
check('re-using the current password is refused', $same['ok'] === false, json_encode($same));

$NEW_PW = 'a-brand-new-password';
$chg = $profile->changePassword($prefId, $PW, $NEW_PW, '10.9.9.2');
check('a valid change succeeds', $chg['ok'] === true, json_encode($chg));
check('the new password verifies', password_verify($NEW_PW,
    (string) Database::run('SELECT password_hash FROM users WHERE id = :id', ['id' => $prefId])->fetchColumn()));
check('the old password no longer does', !password_verify($PW,
    (string) Database::run('SELECT password_hash FROM users WHERE id = :id', ['id' => $prefId])->fetchColumn()));

$stamped = Database::run('SELECT password_changed_at FROM users WHERE id = :id', ['id' => $prefId])->fetchColumn();
check('the change stamped password_changed_at', is_string($stamped) && $stamped !== '', var_export($stamped, true));

// -- deleting an account
$del = $profile->deleteAccount($prefId, 'not-the-password', '10.9.9.3');
check('deleting with the wrong password is refused', $del['ok'] === false, json_encode($del));
check('a refused delete leaves the account in place',
    Database::run('SELECT COUNT(*) FROM users WHERE id = :id', ['id' => $prefId])->fetchColumn() === 1);

$profile->saveLocation($prefId, 1.4927, 103.7414);
$del = $profile->deleteAccount($prefId, $NEW_PW, '10.9.9.3');
check('deleting with the right password succeeds', $del['ok'] === true, json_encode($del));
check('the account is gone',
    Database::run('SELECT COUNT(*) FROM users WHERE id = :id', ['id' => $prefId])->fetchColumn() === 0);
check('its preferences were cascade-deleted, not orphaned',
    Database::run('SELECT COUNT(*) FROM user_preferences WHERE user_id = :id', ['id' => $prefId])->fetchColumn() === 0);
check('the other account was not touched',
    Database::run('SELECT COUNT(*) FROM users WHERE id = :id', ['id' => $otherId])->fetchColumn() === 1);

Session::logout();

// -- notifications must not be storable: nothing delivers them
$notifyCol = Database::run(
    "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'user_preferences' AND COLUMN_NAME LIKE '%notif%'"
)->fetchColumn();
check('no notification column exists, because nothing sends notifications', $notifyCol === 0);

foreach (ProfileModel::ACTIVITIES as $slug => $label) {
    check("the activity {$slug} is a real option", $slug !== '' && $label !== '');
}

// ------------------------------------------------- administrator dashboard
group('Administrator dashboard — access control');
Session::logout();
$dashboard = new DashboardModel();
check('signed out is not an administrator', $dashboard->isAdministrator() === false);

$uAdmin = unique('adm');
$eAdmin = $uAdmin . '@example.test';
$auth->register($uAdmin, $eAdmin, $PW);
$auth->verifyEmail($eAdmin, (string) latestMailCode($mailDir, $eAdmin));
$auth->login($eAdmin, $PW, '10.7.7.1');
check('a registered member is not an administrator', $dashboard->isAdministrator() === false);

Database::run(
    "UPDATE users SET usergroup_id = (SELECT id FROM usergroups WHERE slug = 'superuser') WHERE email = :e",
    ['e' => $eAdmin]
);
check('a superuser is an administrator', $dashboard->isAdministrator() === true);
Session::logout();

// --------------------------------------------- admin CRUD + foreign keys ---
group('Admin panels — CRUD and foreign keys');

/** Build the model a panel would use, exactly as AdminController does. */
function adminModel(string $key): AdminModel
{
    $spec = Entities::get($key);

    return $key === 'user' ? new UserAdminModel($spec) : new AdminModel($spec);
}

/** True when a table exists in the test database. */
function tableExists(string $table): bool
{
    return (int) Database::run(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t',
        ['t' => $table]
    )->fetchColumn() === 1;
}

$types  = adminModel('credentialtype');
$users  = adminModel('user');
$groups = adminModel('usergroup');

// -- every panel points at a table that really exists
$missing = [];
foreach (Entities::all() as $key => $spec) {
    if (!tableExists((string) $spec['table'])) {
        $missing[] = $key;
    }
}
check('all three panels map to a real table', $missing === [], implode(', ', $missing));

// -- the credential panel is a name and a key, and nothing else
check('the credential panel has exactly two fields', array_keys(Entities::get('credentialtype')['fields']) === ['name', 'api_key']);
check('its title is "Credentials"', Entities::get('credentialtype')['title'] === 'Credentials');

// -- credential: create, list, uniqueness
$made = $types->create(['name' => 'AI KEY', 'api_key' => 'keysbuq1oj`12332']);
check('credential is created', $made['ok'] === true);
$typeId = (int) $made['id'];

check('credential appears in the list', count(array_filter(
    $types->all(),
    static fn (array $r): bool => (int) $r['id'] === $typeId
)) === 1);

// -- the key is encrypted at rest, not merely hidden in the UI
$PLAIN  = 'keysbuq1oj`12332';
$stored = (string) Database::run(
    'SELECT api_key FROM credential_types WHERE id = :id',
    ['id' => $typeId]
)->fetchColumn();

check('the key is NOT stored in plain text', $stored !== $PLAIN);
check('the stored value carries the version prefix', str_starts_with($stored, 'enc:v1:'));
check('the stored value is not the key in base64 either', !str_contains($stored, base64_encode($PLAIN)));
check('the ciphertext is longer than the plaintext (nonce + tag + base64)', strlen($stored) > strlen($PLAIN));

// -- and it comes back out intact
check('reading the credential decrypts the key', $types->find($typeId)['api_key'] === $PLAIN);
check('the list decrypts the key too', array_values(array_filter(
    $types->all(),
    static fn (array $r): bool => (int) $r['id'] === $typeId
))[0]['api_key'] === $PLAIN);

// -- Crypto itself
$once  = Crypto::encrypt('same-secret');
$twice = Crypto::encrypt('same-secret');
check('the same key encrypts differently each time (fresh nonce)', $once !== $twice);
check('both ciphertexts decrypt back to the same key', Crypto::decrypt($once) === 'same-secret' && Crypto::decrypt($twice) === 'same-secret');
check('an empty value is passed through, not encrypted', Crypto::encrypt('') === '' && Crypto::decrypt(null) === null);
check('isEncrypted tells ciphertext from plaintext', Crypto::isEncrypted($once) && !Crypto::isEncrypted('plain'));
check('a tampered ciphertext is rejected rather than silently mangled', (static function () use ($once): bool {
    $blob = (string) base64_decode(substr($once, 7), true);
    $blob[30] = chr(ord($blob[30]) ^ 0xFF);              // flip a bit in the body
    try {
        Crypto::decrypt('enc:v1:' . base64_encode($blob));
        return false;                                    // must not succeed
    } catch (RuntimeException $e) {
        return true;                                     // the GCM tag check caught it
    }
})());

// -- validation still applies to the key
check('duplicate credential name is rejected', $types->create(['name' => 'AI KEY', 'api_key' => 'x'])['ok'] === false);
check('a credential with no key is rejected', $types->create(['name' => 'No Key', 'api_key' => ''])['ok'] === false);
check('a key longer than 255 characters is rejected', $types->create([
    'name' => 'Too Long', 'api_key' => str_repeat('k', 256),
])['ok'] === false);

// -- editing re-encrypts rather than copying the old ciphertext across
$before = (string) Database::run('SELECT api_key FROM credential_types WHERE id = :id', ['id' => $typeId])->fetchColumn();
check('editing a credential keeps its own name valid', $types->update($typeId, [
    'name' => 'AI KEY', 'api_key' => $PLAIN,
])['ok'] === true);
$after = (string) Database::run('SELECT api_key FROM credential_types WHERE id = :id', ['id' => $typeId])->fetchColumn();
check('re-saving re-encrypts with a fresh nonce', $after !== $before);
check('the edited key still reads back correctly', $types->find($typeId)['api_key'] === $PLAIN);

// -- a value written under a different CREDENTIAL_KEY degrades, it does not crash
Database::run(
    'UPDATE credential_types SET api_key = :k WHERE id = :id',
    ['k' => 'enc:v1:' . base64_encode(str_repeat("\x00", 40)), 'id' => $typeId]
);
check('an unreadable key comes back empty instead of taking the panel down', $types->find($typeId)['api_key'] === '');

$types->update($typeId, ['name' => 'AI KEY', 'api_key' => $PLAIN]);

// -- the provider and key tables are gone, not merely unused
check('the providers table was dropped', !tableExists('providers'));
check('the credential_type_providers table was dropped', !tableExists('credential_type_providers'));
check('the credential_keys table was dropped', !tableExists('credential_keys'));
check('the old brevo_secret_key column is gone', (int) Database::run(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'credential_types'
        AND COLUMN_NAME = 'brevo_secret_key'"
)->fetchColumn() === 0);
check('the key column is wide enough for the ciphertext', (int) Database::run(
    "SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'credential_types'
        AND COLUMN_NAME = 'api_key'"
)->fetchColumn() === 512);

// -- nothing points at a credential, so deleting one just works
check('credential deletes', $types->delete($typeId)['ok'] === true);
check('the deleted credential is gone from the list', count(array_filter(
    $types->all(),
    static fn (array $r): bool => (int) $r['id'] === $typeId
)) === 0);

// -- usergroup + user: the usergroup is a real foreign key
$groupMade = $groups->create(['title' => 'Editor Test', 'slug' => 'editor-test', 'access' => 'site', 'description' => '']);
check('usergroup is created', $groupMade['ok'] === true);
$groupId = (int) $groupMade['id'];
check('a slug with spaces or capitals is rejected', $groups->create([
    'title' => 'Bad Slug', 'slug' => 'Not A Slug', 'access' => 'site', 'description' => '',
])['ok'] === false);
check('an access level outside the list is rejected', $groups->create([
    'title' => 'Bad Access', 'slug' => 'bad-access', 'access' => 'root', 'description' => '',
])['ok'] === false);

$userMade = $users->create([
    'username' => 'cruduser', 'email' => 'cruduser@example.test', 'password' => $PW,
    'usergroup_id' => (string) $groupId, 'status' => 'active',
]);
check('user is created in the chosen usergroup', $userMade['ok'] === true);
$userId = (int) $userMade['id'];

$userRow = $users->find($userId);
check('the usergroup is persisted as the foreign key', (int) $userRow['usergroup_id'] === $groupId);
check('the password is stored as a hash, never as plain text', $userRow['password_hash'] !== $PW && password_verify($PW, $userRow['password_hash']));
check('an active account is marked email-verified so it can sign in', $userRow['email_verified_at'] !== null);

$noGroup = $users->create([
    'username' => 'nogroup', 'email' => 'nogroup@example.test', 'password' => $PW,
    'usergroup_id' => '99999', 'status' => 'active',
]);
check('user rejected when the usergroup does not exist', $noGroup['ok'] === false && isset($noGroup['fields']['usergroup_id']));
check('duplicate username is rejected', $users->create([
    'username' => 'cruduser', 'email' => 'other@example.test', 'password' => $PW,
    'usergroup_id' => (string) $groupId, 'status' => 'active',
])['ok'] === false);

check('usergroup cannot be deleted while users belong to it', $groups->delete($groupId)['ok'] === false);

// -- editing: a blank password keeps the stored hash
$hashBefore = $userRow['password_hash'];
$users->update($userId, [
    'username' => 'cruduser', 'email' => 'cruduser@example.test', 'password' => '',
    'usergroup_id' => (string) $groupId, 'status' => 'suspended',
]);
check('a blank password on edit keeps the existing hash', $users->find($userId)['password_hash'] === $hashBefore);
check('the status change was persisted', $users->find($userId)['status'] === 'suspended');

// -- editing: a new password replaces it
$users->update($userId, [
    'username' => 'cruduser', 'email' => 'cruduser@example.test', 'password' => $PW . '-new',
    'usergroup_id' => (string) $groupId, 'status' => 'active',
]);
check('a new password replaces the hash', password_verify($PW . '-new', $users->find($userId)['password_hash']));

// -- editing: moving a user to another usergroup re-points the foreign key
$groupMade2 = $groups->create(['title' => 'Author Test', 'slug' => 'author-test', 'access' => 'administrator', 'description' => '']);
$groupId2   = (int) $groupMade2['id'];
$users->update($userId, [
    'username' => 'cruduser', 'email' => 'cruduser@example.test', 'password' => '',
    'usergroup_id' => (string) $groupId2, 'status' => 'active',
]);
check('moving a user to another usergroup persists the new foreign key', (int) $users->find($userId)['usergroup_id'] === $groupId2);

// -- deleting a user takes its dependent rows with it (ON DELETE CASCADE)
Database::run(
    "INSERT INTO email_otps (user_id, purpose, otp_hash, expires_at) VALUES (:id, 'login', :h, :e)",
    ['id' => $userId, 'h' => str_repeat('a', 64), 'e' => Database::future(600)]
);
check('user is deleted', $users->delete($userId)['ok'] === true);
check('the user\'s OTPs went with the account (cascade)', (int) Database::run(
    'SELECT COUNT(*) FROM email_otps WHERE user_id = :id',
    ['id' => $userId]
)->fetchColumn() === 0);
check('usergroup deletes once its users are gone', $groups->delete($groupId)['ok'] === true);
check('the second usergroup also deletes', $groups->delete($groupId2)['ok'] === true);

// -- the schema really declares these keys, rather than trusting the app
// 3 since password_reset_tokens was dropped: email_otps, email_verification_tokens
// and users itself. An orphaned reset code is the thing this guards against, so
// the email_otps key is named rather than just counted.
$fkCount = (int) Database::run(
    'SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
      WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL'
)->fetchColumn();
check('the schema declares foreign keys, not just form checks', $fkCount >= 3, "found {$fkCount}");
$otpFk = Database::run(
    "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_otps'
        AND COLUMN_NAME = 'user_id' AND REFERENCED_TABLE_NAME = 'users'"
)->fetchColumn();
check('email_otps.user_id really points at users', (int) $otpFk === 1);

// ------------------------------------------- Brevo mail integration ---
group('Mail integration — key resolution, welcome mail, failure handling');

// Seed the credential the fallback reads, exactly as the admin panel would:
// encrypted, never plaintext.
$brevoPlain  = 'xkeysib-test-key-not-real';
Database::run(
    'INSERT INTO credential_types (name, api_key) VALUES (:n, :k)',
    ['n' => 'Brevo', 'k' => Crypto::encrypt($brevoPlain)]
);
$brevoStored = (string) Database::run(
    'SELECT api_key FROM credential_types WHERE name = :n',
    ['n' => 'Brevo']
)->fetchColumn();

// -- precedence: the deployment override wins
Config::set('BREVO_API_KEY', 'env-key-123');
check('BREVO_API_KEY is used when it is set', Mailer::brevoApiKey() === 'env-key-123');

// -- and the encrypted credential is the fallback
Config::set('BREVO_API_KEY', '');
check('falls back to the credential when the env var is empty', Mailer::brevoApiKey() === $brevoPlain);
check('the credential really is stored encrypted', $brevoStored !== $brevoPlain && str_starts_with($brevoStored, 'enc:v1:'));
check('an all-whitespace env var does not win',
    (static function (): bool {
        Config::set('BREVO_API_KEY', '   ');
        $ok = Mailer::brevoApiKey() === 'xkeysib-test-key-not-real';
        Config::set('BREVO_API_KEY', '');
        return $ok;
    })());

// -- a missing credential is reported, naming both ways to fix it
Config::set('BREVO_CREDENTIAL', 'No Such Credential');
$missingKey = '';
try {
    Mailer::brevoApiKey();
} catch (RuntimeException $e) {
    $missingKey = $e->getMessage();
}
check('a missing key is reported rather than guessed at', $missingKey !== '');
check('the error names both places a key can come from',
    str_contains($missingKey, 'BREVO_API_KEY') && str_contains($missingKey, 'No Such Credential'));
Config::set('BREVO_CREDENTIAL', 'Brevo');

// -- register -> verify sends a welcome email and completes the journey
$uW = unique('welcome');
$eW = $uW . '@example.test';
$auth->register($uW, $eW, $PW);
$codeW = latestMailCode($mailDir, $eW);
check('the verification email goes out on register', $codeW !== null);
check('the account is pending until the code is used',
    Database::run('SELECT status FROM users WHERE email = :e', ['e' => $eW])->fetchColumn() === 'pending');

check('verifying activates the account', $auth->verifyEmail($eW, (string) $codeW)['ok'] === true);
$welcome = latestMail($mailDir, $eW);
check('a welcome email is sent once the account is activated',
    $welcome !== null && str_contains($welcome, 'Subject: Welcome to HazeGuard AI'));
check('the welcome mail greets the account by name', $welcome !== null && str_contains($welcome, $uW));
check('the welcome mail carries no verification code', $welcome !== null && !str_contains($welcome, (string) $codeW));

// -- a provider failure must not 500 after the account already exists
Config::set('MAIL_DRIVER', 'nope');           // any unknown driver throws
$uF = unique('mailfail');
$eF = $uF . '@example.test';
$failed = $auth->register($uF, $eF, $PW);
Config::set('MAIL_DRIVER', 'log');

check('register reports mail_failed instead of a bare 500',
    $failed['ok'] === false && $failed['code'] === 'mail_failed', json_encode($failed));
check('the failure carries a status the client can act on', ($failed['status'] ?? 0) === 502);
check('the message says the account was created', str_contains($failed['message'], 'account was created'));
check('the account survives the mail failure',
    (int) Database::run('SELECT COUNT(*) FROM users WHERE email = :e', ['e' => $eF])->fetchColumn() === 1);
check('the verification code was stored, so resend can recover it',
    (int) Database::run(
        'SELECT COUNT(*) FROM email_otps o JOIN users u ON u.id = o.user_id WHERE u.email = :e',
        ['e' => $eF]
    )->fetchColumn() === 1);

// -- and the recovery path really works
$auth->resendVerification($eF, '10.5.5.5');
$codeF = latestMailCode($mailDir, $eF);
check('resend delivers once mail works again', $codeF !== null);
check('the recovered code finishes verification', $auth->verifyEmail($eF, (string) $codeF)['ok'] === true);

// -- a courtesy mail failing must not undo a successful verification
$uG = unique('grace');
$eG = $uG . '@example.test';
$auth->register($uG, $eG, $PW);
$codeG = latestMailCode($mailDir, $eG);
Config::set('MAIL_DRIVER', 'nope');           // the welcome mail will fail
$verified = $auth->verifyEmail($eG, (string) $codeG);
Config::set('MAIL_DRIVER', 'log');
check('a welcome-mail failure does not undo the verification', $verified['ok'] === true);
check('the account is active regardless',
    Database::run('SELECT status FROM users WHERE email = :e', ['e' => $eG])->fetchColumn() === 'active');

// -- the anti-enumeration contract holds even when the provider is down
$uH = unique('enum');
$eH = $uH . '@example.test';
$auth->register($uH, $eH, $PW);               // pending, never verified
Config::set('MAIL_DRIVER', 'nope');
check('resend still reports success when the provider fails',
    $auth->resendVerification($eH, '10.6.6.1')['ok'] === true);
check('a failed resend does not leak that the address exists',
    $auth->resendVerification('nobody-at-all@example.test', '10.6.6.2')['ok'] === true);

// ------------------------------------------- mail transport: address family ---
group('Mail transport — IPv4 is tried before the hostname');

$targetsFor = new \ReflectionMethod(Mailer::class, 'httpsTargets');
$targetsFor->setAccessible(true);

// An address literal has nothing to resolve: one target, sent as given.
$literal = $targetsFor->invoke(null, 'https://141.101.90.104/v3/smtp/email');
check('an address literal yields exactly one target', count($literal) === 1);
check('an address literal is used verbatim',
    $literal[0]['url'] === 'https://141.101.90.104/v3/smtp/email' && $literal[0]['ip'] === null);

// Only https is pinned; anything else keeps the wrapper's own behaviour.
$plain = $targetsFor->invoke(null, 'http://api.brevo.com/v3/smtp/email');
check('a non-https URL is left to the wrapper', count($plain) === 1 && $plain[0]['ip'] === null);

// The real hostname: A records first, hostname last — that order is the fix.
$url      = 'https://api.brevo.com/v3/smtp/email';
$resolved = $targetsFor->invoke(null, $url);
$last     = $resolved[count($resolved) - 1];
check('the hostname is always the last resort',
    $last['url'] === $url && $last['ip'] === null);
check('every pinned target is a valid IPv4 literal', (static function () use ($resolved): bool {
    foreach ($resolved as $t) {
        if ($t['ip'] !== null && filter_var($t['ip'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }
    }
    return true;
})());
check('a pinned target keeps the path and names the host it really wants',
    (static function () use ($resolved): bool {
        foreach ($resolved as $t) {
            if ($t['ip'] === null) {
                continue;
            }
            if ($t['url'] !== "https://{$t['ip']}/v3/smtp/email" || $t['host'] !== 'api.brevo.com') {
                return false;
            }
        }
        return true;
    })());
Config::set('MAIL_DRIVER', 'log');

// -- the login OTP path is wired to the same mailer
Database::run('DELETE FROM rate_limits');
$e1Verified = Database::run('SELECT email FROM users WHERE status = :s LIMIT 1', ['s' => 'active'])->fetchColumn();
Config::set('MAIL_DRIVER', 'nope');
check('an OTP request still reports success when the provider fails',
    $auth->requestLoginOtp((string) $e1Verified, '10.7.7.1')['ok'] === true);
Config::set('MAIL_DRIVER', 'log');
$auth->requestLoginOtp((string) $e1Verified, '10.7.7.2');
$otpMail = latestMail($mailDir, (string) $e1Verified);
check('the sign-in code is emailed once mail works again',
    $otpMail !== null && str_contains($otpMail, 'Subject: Your HazeGuard AI sign-in code'));

group('Air quality — the logic that moved out of JavaScript');

$air = new AirModel();

// An isolated cache directory: the suite must never read or write the dev cache.
$airDir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'hazeguard-air-' . bin2hex(random_bytes(4));
Config::set('AIR_CACHE_DIR', $airDir);
Config::set('AIR_CACHE_TTL', '600');

// -- the AQI bands, which used to be a lookup table in the browser
$band = new \ReflectionMethod(AirModel::class, 'category');
$band->setAccessible(true);
foreach ([
    [0, 'Good'], [50, 'Good'], [51, 'Moderate'], [100, 'Moderate'],
    [101, 'Unhealthy for sensitive groups'], [150, 'Unhealthy for sensitive groups'],
    [151, 'Unhealthy'], [200, 'Unhealthy'], [201, 'Very unhealthy'], [500, 'Very unhealthy'],
] as [$aqi, $expected]) {
    check("AQI {$aqi} is {$expected}", $band->invoke(null, $aqi)['label'] === $expected);
}
check('every band names a ribbon class', (static function () use ($band): bool {
    foreach ([0, 51, 101, 151, 201] as $aqi) {
        if (!str_starts_with((string) $band->invoke(null, $aqi)['ribbon'], 'ribbon--')) {
            return false;
        }
    }
    return true;
})());

// -- the payload, which is now assembled server-side
$present = new \ReflectionMethod(AirModel::class, 'present');
$present->setAccessible(true);

$point = ['name' => null, 'lat' => 1.4927, 'lon' => 103.7414, 'source' => 'device'];
$raw   = ['us_aqi' => 174.4, 'pm2_5' => 82.9, 'pm10' => 96.2, 'time' => '2026-10-12T00:00'];
$out   = $present->invoke(null, $raw, $point, 'Johor Bahru');

check('the AQI is rounded, not truncated', $out['aqi'] === 174);
check('PM2.5 and PM10 are rounded', $out['pm25'] === 83 && $out['pm10'] === 96);
check('the band position is the AQI over 300', $out['bandPos'] === 58.0);
check('the band label spells out the category',
    $out['bandLabel'] === 'Air quality index 174 of 300, in the Unhealthy band');
check('the ribbon matches the category', $out['category']['ribbon'] === 'ribbon--4');
check('a resolved city is used verbatim', $out['place']['name'] === 'Johor Bahru');
check('a device position says so', $out['provenance'] === 'For your location');
check('the timestamp is formatted in the location\'s own time',
    $out['observedLabel'] === '12 Oct, 00:00');
check('the refresh interval comes from the server', $out['refreshMs'] === 3600000);
check('the model output is disclosed as a model output',
    str_contains((string) $out['sourceNote'], 'a modelled estimate rather than a ground-station measurement'));

$unnamed = $present->invoke(null, $raw, $point, null);
check('an unresolved city falls back to coordinates, never a guess',
    $unnamed['place']['name'] === '1.493, 103.741');

$home = ['name' => 'Kuala Lumpur', 'lat' => 3.1412, 'lon' => 101.68653, 'source' => 'default'];
$defaulted = $present->invoke(null, $raw, $home, 'Kuala Lumpur');
check('the default city never claims to be the visitor\'s location',
    $defaulted['provenance'] === 'For Kuala Lumpur — your location was not shared');

// -- the saved preferred location
// The instrument used to answer for the device position or the default city and
// never consult the stored preference, so someone who saved Kuala Lumpur while
// standing in Johor Bahru still saw Johor Bahru's AQI.
$saved = ['name' => 'Kuala Lumpur', 'lat' => 3.1412, 'lon' => 101.68653, 'source' => 'saved'];
$savedOut = $present->invoke(null, $raw, $saved, 'Kuala Lumpur');
check('a saved location is reported as such', $savedOut['place']['source'] === 'saved');
check('its provenance names the saved location, not "your location"',
    $savedOut['provenance'] === 'For your saved location — Kuala Lumpur',
    $savedOut['provenance']);
check('the three sources are each described differently',
    count(array_unique([
        $out['provenance'],
        $savedOut['provenance'],
        $defaulted['provenance'],
    ])) === 3);

// A saved place with no resolved name must not invent one.
$savedUnnamed = $present->invoke(null, $raw, $saved, null);
check('an unnamed saved location falls back to coordinates, never a guess',
    $savedUnnamed['place']['name'] === '3.141, 101.687', $savedUnnamed['place']['name']);

// -- which point is chosen, decided before any upstream call
$pointFor = new \ReflectionMethod(AirModel::class, 'pointFor');
$pointFor->setAccessible(true);
$airUnder = new AirModel();

$fromSaved = $pointFor->invoke($airUnder, null, null, ['name' => 'Kuala Lumpur', 'lat' => 3.1412, 'lon' => 101.68653]);
check('a saved location is chosen when no coordinate is asked for',
    $fromSaved['source'] === 'saved'
    && abs($fromSaved['lat'] - 3.1412) < 0.0001
    && abs($fromSaved['lon'] - 101.68653) < 0.0001,
    json_encode($fromSaved));
check('its resolved name travels with it, so the geocoder is not asked twice',
    $fromSaved['name'] === 'Kuala Lumpur', json_encode($fromSaved));

$fromDevice = $pointFor->invoke($airUnder, 1.4927, 103.7414, ['name' => 'Kuala Lumpur', 'lat' => 3.1412, 'lon' => 101.68653]);
check('an explicit coordinate still wins over the saved location',
    $fromDevice['source'] === 'device' && abs($fromDevice['lat'] - 1.4927) < 0.0001,
    json_encode($fromDevice));
check('and the saved location does not lend it a name',
    $fromDevice['name'] === null, json_encode($fromDevice));

$fromHome = $pointFor->invoke($airUnder, null, null, null);
check('with no saved location the default city is used',
    $fromHome['source'] === 'default' && $fromHome['name'] === 'Kuala Lumpur', json_encode($fromHome));

$halfSaved = $pointFor->invoke($airUnder, null, null, ['name' => '', 'lat' => 3.1412, 'lon' => 101.68653]);
check('an unnamed saved location is still used, with the name left to resolve',
    $halfSaved['source'] === 'saved' && $halfSaved['name'] === null, json_encode($halfSaved));

// -- place search ----------------------------------------------------------------
// The provider is Photon (keyless). flatten() is pure, so it is tested directly
// with captured shapes instead of calling the network.
$flatten = new \ReflectionMethod(PlacesModel::class, 'flatten');
$flatten->setAccessible(true);

$features = [
    ['properties' => ['name' => 'Kuala Lumpur', 'country' => 'Malaysia'],
        'geometry' => ['coordinates' => [101.6942371, 3.1516964]]],
    // Same city, a different point 10 m away: offering it twice reads as two
    // different answers, so it is collapsed.
    ['properties' => ['name' => 'Kuala Lumpur', 'country' => 'Malaysia'],
        'geometry' => ['coordinates' => [101.6943, 3.1517]]],
    ['properties' => ['name' => 'George Town', 'country' => 'Malaysia'],
        'geometry' => ['coordinates' => [100.3288, 5.4141]]],
    // Unusable entries must be dropped, not rendered as blank rows.
    ['properties' => ['name' => '', 'country' => 'Malaysia'],
        'geometry' => ['coordinates' => [1, 1]]],
    ['properties' => ['name' => 'Nowhere'], 'geometry' => ['coordinates' => []]],
    ['properties' => ['name' => 'Bad', 'country' => 'X'],
        'geometry' => ['coordinates' => [200.0, 100.0]]],
];

$places = $flatten->invoke(null, $features);
check('place search returns only usable rows', count($places) === 2, json_encode($places));
check('it keeps the name, country and rounded coordinates',
    $places[0] === ['name' => 'Kuala Lumpur', 'lat' => 3.1517, 'lon' => 101.6942, 'country' => 'Malaysia'],
    json_encode($places[0]));
check('a near-duplicate of the same city is collapsed',
    count(array_filter($places, static fn (array $p): bool => $p['name'] === 'Kuala Lumpur')) === 1);
check('an out-of-range coordinate is dropped, not clamped', !in_array('Bad', array_column($places, 'name'), true));
check('a malformed provider payload yields no rows rather than an error',
    $flatten->invoke(null, null) === [] && $flatten->invoke(null, 'nonsense') === []);
check('a feature missing its geometry is skipped',
    $flatten->invoke(null, [['properties' => ['name' => 'X']]]) === []);

// A one-character query must not reach the provider at all: the upstream bill is
// paid per keystroke, and "K" is a typo rather than an abbreviation.
Database::run('DELETE FROM rate_limits WHERE bucket LIKE :b', ['b' => 'places:%']);
check('a one-character search returns nothing',
    (new PlacesModel())->search('K', '10.5.5.5') === []);
check('an empty search returns nothing',
    (new PlacesModel())->search('   ', '10.5.5.5') === []);
check('neither short query spent a rate-limit bucket',
    (int) Database::run("SELECT COUNT(*) FROM rate_limits WHERE bucket LIKE 'places:%'")->fetchColumn() === 0);

check('the reverse lookup refuses an out-of-range coordinate',
    (new PlacesModel())->reverse(999.0, 0.0) === null && (new PlacesModel())->reverse(0.0, 999.0) === null);

$partial = $present->invoke(null, ['us_aqi' => 40, 'time' => 'nonsense'], $point, null);
check('a missing PM figure becomes null, not a stand-in',
    $partial['pm25'] === null && $partial['pm10'] === null);
check('an unparseable time degrades to a sentence, not an error',
    $partial['observedLabel'] === 'the latest model run');

// -- the cache: one upstream call per coordinate per TTL, shared by all visitors
$store  = new \ReflectionMethod(AirModel::class, 'store');
$cached = new \ReflectionMethod(AirModel::class, 'cached');
$pointKey = new \ReflectionMethod(AirModel::class, 'pointKey');
foreach ([$store, $cached, $pointKey] as $method) {
    $method->setAccessible(true);
}

$stored = ['us_aqi' => 42, 'pm2_5' => 7, 'pm10' => 9, 'time' => '2026-10-12T00:00'];
$key = $pointKey->invoke(null, 1.5, 103.7);
$store->invoke($air, $key, $stored);

$hit = $cached->invoke($air, $key, false);
check('a stored reading is served from the cache',
    is_array($hit) && $hit['fresh'] === true && $hit['current']['us_aqi'] === 42);
check('the cache does not answer for a different coordinate',
    $cached->invoke($air, $pointKey->invoke(null, 9.9, 9.9), false) === null);
check('two points inside one grid cell share an entry',
    $pointKey->invoke(null, 1.5001, 103.7002) === $pointKey->invoke(null, 1.5, 103.7));

// Age the entry past the TTL, then ask again.
$agedPath = $airDir . DIRECTORY_SEPARATOR . sha1($key) . '.json';
file_put_contents($agedPath, json_encode(['at' => time() - 9999, 'current' => $stored]));
check('an expired entry is not served as current', $cached->invoke($air, $key, false) === null);
$aged = $cached->invoke($air, $key, true);
check('an expired entry is still available as a stale fallback',
    is_array($aged) && $aged['fresh'] === false && $aged['current']['us_aqi'] === 42);

// -- the two failure paths, forced by pointing TLS at a file that is not a CA
$bogus = $airDir . DIRECTORY_SEPARATOR . 'not-a-ca.pem';
file_put_contents($bogus, "this is not a certificate\n");
$memo = new \ReflectionProperty(Http::class, 'caBundle');
$memo->setAccessible(true);
Config::set('HTTP_CA_BUNDLE', $bogus);
$memo->setValue(null, false);

$fallback = $air->current(1.5, 103.7);
check('a dead upstream still answers from the cache, marked stale',
    ($fallback['ok'] ?? false) === true
    && $fallback['data']['stale'] === true
    && $fallback['data']['aqi'] === 42
    && $fallback['data']['place']['name'] === '1.500, 103.700');

$dead = $air->current(9.9, 9.9);
check('with no cache and no upstream, the model reports unavailability',
    ($dead['ok'] ?? false) === false
    && ($dead['code'] ?? '') === 'air_unavailable'
    && ($dead['status'] ?? 0) === 502);
check('an unavailable reading carries a message the client can print',
    is_string($dead['message'] ?? null) && $dead['message'] !== '');

Config::set('HTTP_CA_BUNDLE', '');
$memo->setValue(null, false);

// -- the controller's coordinate guard, which is the only thing between a
//    request and a request to somebody else's API
$coordinate = new \ReflectionMethod(AirController::class, 'coordinate');
$coordinate->setAccessible(true);
$controller = new AirController();

$_GET['lat'] = '1.4927';
$_GET['lon'] = '103.7414';
Request::reset();
check('an in-range coordinate is accepted', $coordinate->invoke($controller, 'lat', -90.0, 90.0) === 1.4927);

$_GET['lat'] = '91';
Request::reset();
check('a latitude past the pole is refused', $coordinate->invoke($controller, 'lat', -90.0, 90.0) === null);

$_GET['lon'] = 'not-a-number';
Request::reset();
check('a non-numeric coordinate is refused', $coordinate->invoke($controller, 'lon', -180.0, 180.0) === null);

$_GET['lat'] = '';
$_GET['lon'] = '';
Request::reset();
check('an absent coordinate is refused', $coordinate->invoke($controller, 'lat', -90.0, 90.0) === null);

// -- the Haze Radar's grid. These are the two places it can fail *quietly*, which
//    is why they are tested directly rather than through the live endpoint.
group('Haze Radar grid — nineteen points, one upstream call');

$grid = new \ReflectionMethod(AirModel::class, 'grid');
$grid->setAccessible(true);
$rows = new \ReflectionMethod(AirModel::class, 'currentRows');
$rows->setAccessible(true);
$withCentre = new \ReflectionMethod(AirModel::class, 'withCentre');
$withCentre->setAccessible(true);

$ring = $grid->invoke(null, 3.139, 101.6869, 30.0);
check('the grid is the centre plus two rings', count($ring) === 19, 'got ' . count($ring));
check('the centre comes first and is the point asked about',
    $ring[0]['lat'] === 3.139 && $ring[0]['lon'] === 101.6869);
check('no point leaves the requested radius',
    count(array_filter($ring, static function (array $p) use ($ring) {
        $dLat = ($p['lat'] - $ring[0]['lat']) * 111.0;
        $dLon = ($p['lon'] - $ring[0]['lon']) * 111.0 * cos(deg2rad($ring[0]['lat']));

        return sqrt($dLat ** 2 + $dLon ** 2) > 30.5;
    })) === 0);
// A ring drawn by treating one degree of longitude as one degree of latitude
// collapses into an ellipse. At 3°N that is invisible — cos(3°) is 0.998 — so the
// check is made at 60°N, where a longitude degree is half a latitude degree and
// getting it wrong doubles the ring's width.
$arctic = $grid->invoke(null, 60.0, 10.0, 30.0);
$ringIsCircular = true;
foreach (array_slice($arctic, 1, 6) as $p) {
    $dLat = ($p['lat'] - 60.0) * 111.0;
    $dLon = ($p['lon'] - 10.0) * 111.0 * cos(deg2rad(60.0));
    if (abs(sqrt($dLat ** 2 + $dLon ** 2) - 10.0) > 0.15) {
        $ringIsCircular = false;
        break;
    }
}
check('a ring stays circular at 60N, where a longitude degree is half as long', $ringIsCircular);
check('every grid point is a usable coordinate',
    count(array_filter($ring, static function (array $p) {
        return $p['lat'] < -90.0 || $p['lat'] > 90.0 || $p['lon'] < -180.0 || $p['lon'] > 180.0;
    })) === 0);

// The two response shapes. Reading only the first is how a nineteen-point grid
// returns nothing — and it fails as an empty map, not as an error.
check('a many-coordinate response is read in request order',
    $rows->invoke(null, [
        ['current' => ['us_aqi' => 10]],
        ['current' => ['us_aqi' => 20]],
    ]) === [['us_aqi' => 10], ['us_aqi' => 20]]);
check('a one-coordinate response is read as a single row',
    $rows->invoke(null, ['current' => ['us_aqi' => 10]]) === [['us_aqi' => 10]]);
check('an entry with no reading stays null rather than shifting the rest',
    $rows->invoke(null, [
        ['current' => ['us_aqi' => 10]],
        ['nothing' => true],
        ['current' => ['us_aqi' => 30]],
    ]) === [['us_aqi' => 10], null, ['us_aqi' => 30]]);
check('an empty response yields no rows rather than a wrong one',
    $rows->invoke(null, []) === []);

// The centre's label belongs to the request, not the grid: the same grid is the
// default city with no coordinate and an explicit place with one, and they share
// a cache entry.
$labelled = $withCentre->invoke(null, ['points' => []], ['name' => 'Kuala Lumpur', 'lat' => 3.1412, 'lon' => 101.68653, 'source' => 'default']);
check('the centre carries its name and provenance',
    $labelled['centre']['name'] === 'Kuala Lumpur' && $labelled['centre']['source'] === 'default');
$relabelled = $withCentre->invoke(null, $labelled, ['name' => null, 'lat' => 3.139, 'lon' => 101.6869, 'source' => 'device']);
check('a second request re-labels the same cached grid rather than inheriting the first',
    $relabelled['centre']['source'] === 'device' && $relabelled['centre']['name'] === null);

// The band index travels with the reading so the browser does not re-derive the
// boundary table PHP already holds.
check('the AQI band travels with its index for the map pins',
    $band->invoke(null, 42)['index'] === 1 && $band->invoke(null, 99)['index'] === 2
    && $band->invoke(null, 400)['index'] === 5);

$radius = new \ReflectionMethod(AirController::class, 'radius');
$radius->setAccessible(true);

$_GET['radiusKm'] = '9999';
Request::reset();
check('an absurd radius is clamped rather than refused',
    $radius->invoke($controller) === 120.0);

$_GET['radiusKm'] = '1';
Request::reset();
check('a radius too small to draw a ring in is clamped up',
    $radius->invoke($controller) === 5.0);

$_GET['radiusKm'] = 'not-a-number';
Request::reset();
check('a non-numeric radius falls back to the default', $radius->invoke($controller) === 30.0);

unset($_GET['radiusKm']);
Request::reset();
check('an absent radius falls back to the default', $radius->invoke($controller) === 30.0);

foreach (glob($airDir . '/*') ?: [] as $f) {
    @unlink($f);
}
@rmdir($airDir);
unset($_GET['lat'], $_GET['lon']);
Request::reset();

group('Http transport — the same IPv4 rule as the mailer');

$targets = new \ReflectionMethod(Http::class, 'targets');
$targets->setAccessible(true);

$literal = $targets->invoke(null, 'https://141.101.90.104/v1/x');
check('Http: an address literal yields exactly one target',
    count($literal) === 1 && $literal[0]['ip'] === null);
check('Http: a non-https URL is left to the wrapper',
    count($targets->invoke(null, 'http://air-quality-api.open-meteo.com/v1/x')) === 1);

$url      = 'https://air-quality-api.open-meteo.com/v1/air-quality?latitude=1.49';
$resolved = $targets->invoke(null, $url);
check('Http: the hostname is always the last resort',
    $resolved[count($resolved) - 1]['url'] === $url
    && $resolved[count($resolved) - 1]['ip'] === null);
check('Http: every pinned target is a valid IPv4 literal', (static function () use ($resolved): bool {
    foreach ($resolved as $t) {
        if ($t['ip'] !== null && filter_var($t['ip'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }
    }
    return true;
})());
check('Http: a pinned target keeps the path, the query and the intended host',
    (static function () use ($resolved): bool {
        foreach ($resolved as $t) {
            if ($t['ip'] === null) {
                continue;
            }
            if ($t['url'] !== "https://{$t['ip']}/v1/air-quality?latitude=1.49"
                || $t['host'] !== 'air-quality-api.open-meteo.com'
            ) {
                return false;
            }
        }
        return true;
    })());

// --------------------------------------------------------------------- summary
$total = $GLOBALS['pass'] + $GLOBALS['fail'];
echo "\n" . str_repeat('=', 52) . "\n";
if ($GLOBALS['fail'] === 0) {
    echo "ALL {$total} ACCEPTANCE CHECKS PASS\n";
} else {
    echo "{$GLOBALS['pass']}/{$total} passed, {$GLOBALS['fail']} FAILED:\n";
    foreach ($GLOBALS['failures'] as $f) {
        echo "  - {$f}\n";
    }
}
echo str_repeat('=', 52) . "\n";

ob_end_flush();
exit($GLOBALS['fail'] === 0 ? 0 : 1);
