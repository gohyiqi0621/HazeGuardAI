<?php
declare(strict_types=1);

namespace HazeGuard\Component\Hazeguard\Site\Model;

use HazeGuard\Component\Hazeguard\Site\Model\AirModel;
use HazeGuard\Database;
use HazeGuard\RateLimiter;
use HazeGuard\Validator;

/**
 * The profile screen's stored state: activity preferences, a preferred location,
 * a password change and account deletion.
 *
 * Every method takes the user id from the session, never from the request, so one
 * account can never read or alter another's rows. Result shape matches
 * AuthModel, so the same JsonView renders both.
 */
final class ProfileModel
{
    /**
     * The activities a user may pick from, as slug => label. This is the
     * allowlist: a slug not in here is dropped rather than stored, so the column
     * can never accumulate arbitrary text. Adding an activity means adding a
     * chip to the template and an entry here — nothing else.
     *
     * @var array<string,string>
     */
    public const ACTIVITIES = [
        'running'        => 'Running',
        'cycling'        => 'Cycling',
        'outdoor_football' => 'Outdoor football',
        'evening_walks'  => 'Evening walks',
    ];

    private const RATE_WINDOW = 900; // 15 minutes

    /**
     * The stored preferences for a user, with defaults filled in.
     *
     * Returns a usable shape whether or not a row exists yet, so the client never
     * has to special-case "never saved" — the first visit and the hundredth read
     * the same way.
     *
     * @return array{activities:list<string>,location:array{name:string,lat:float|null,lon:float|null}|null}
     */
    public function read(int $userId): array
    {
        $row = Database::run(
            'SELECT activities, location_name, location_lat, location_lon FROM user_preferences WHERE user_id = :id',
            ['id' => $userId]
        )->fetch();

        if ($row === false) {
            return ['activities' => [], 'location' => null];
        }

        $hasCoords = $row['location_lat'] !== null && $row['location_lon'] !== null;

        return [
            'activities' => self::cleanActivities(explode(',', (string) $row['activities'])),
            'location'   => $hasCoords
                ? [
                    'name' => (string) ($row['location_name'] ?? ''),
                    'lat'  => (float) $row['location_lat'],
                    'lon'  => (float) $row['location_lon'],
                ]
                : null,
        ];
    }

    /**
     * Wrap a read() payload in the standard result envelope.
     *
     * read() answers with the preferences themselves rather than a result array,
     * because it has nothing to fail: there is one honest answer for any account,
     * and a missing row is "nothing saved", not an error. The view still needs an
     * envelope, so it is built here.
     *
     * @param array{activities:list<string>,location:array{name:string,lat:float,lon:float}|null} $prefs
     * @return array{ok:bool,code:string,message:string,fields:array<string,string>,data:mixed}
     */
    public function result(array $prefs): array
    {
        return $this->pass($prefs);
    }

    /**
     * Save the activity preferences. An empty list is a legitimate value — it is
     * how a user turns everything off — so it is stored, not treated as "no
     * change".
     *
     * @param list<string> $activities
     * @return array{ok:bool,code:string,message:string,fields:array<string,string>,data:mixed}
     */
    public function saveActivities(int $userId, array $activities): array
    {
        $kept = self::cleanActivities($activities);
        $dropped = count(array_filter($activities, static fn (string $a): bool => !isset(self::ACTIVITIES[$a])));

        // A typo'd slug is a client bug, not a user mistake: say so in the
        // response rather than silently persisting a subset.
        if ($dropped > 0) {
            return $this->fail('validation_error', 'That activity is not one of the options offered.', ['activities' => 'Unknown activity.']);
        }

        $row = $this->current($userId);
        $this->store($userId, implode(',', $kept), $row['name'], $row['lat'], $row['lon']);

        return $this->pass(['activities' => $kept]);
    }

    /**
     * Save, or clear, the preferred location.
     *
     * Null coordinates clear it.
     *
     * The city name is resolved **here**, from the coordinates just saved, and the
     * client's copy is ignored. Two reasons: the client's name may be a fallback
     * like "1.493, 103.741" rather than a place, and the saved location is what
     * labels every future reading — a wrong name there would mislabel the air.
     * This is the same reverse geocoder the reading already uses, so both name a
     * place the same way.
     *
     * @return array{ok:bool,code:string,message:string,fields:array<string,string>,data:mixed}
     */
    public function saveLocation(int $userId, ?float $lat, ?float $lon): array
    {
        $row = $this->current($userId);

        if ($lat === null || $lon === null) {
            $this->store($userId, $row['activities'], null, null, null);
            return $this->pass(['location' => null]);
        }

        if ($lat < -90.0 || $lat > 90.0 || $lon < -180.0 || $lon > 180.0) {
            return $this->fail('validation_error', 'That location is not on the map.', ['location' => 'Coordinates are out of range.']);
        }

        // Rounded to 4dp (~11 m) before geocoding. The provider is a 6dp echo of
        // what we sent, so storing it verbatim would imply a precision neither the
        // model grid nor the geocoder actually has.
        $lat = round($lat, 4);
        $lon = round($lon, 4);

        // Never fatal: an unreachable geocoder must not lose the coordinates the
        // user just gave us. The name falls back to the coordinate string, which is
        // what the reading falls back to too.
        $name = (new AirModel())->city($lat, $lon) ?? self::coordinateLabel($lat, $lon);

        $this->store($userId, $row['activities'], mb_substr($name, 0, 120), $lat, $lon);

        return $this->pass(['location' => ['name' => $name, 'lat' => $lat, 'lon' => $lon]]);
    }

    /**
     * Change the password of a signed-in account.
     *
     * The current password is required even though the session already proves who
     * this is: it is the check that stops a borrowed or unattended session from
     * locking the real owner out, and it is what the reset flow cannot offer.
     *
     * @return array{ok:bool,code:string,message:string,fields:array<string,string>,data:mixed}
     */
    public function changePassword(int $userId, string $current, string $new, string $ip): array
    {
        // The strength rule applies to the NEW password only. Applying it to the
        // current one is wrong twice over: an account whose existing password was
        // set under older rules would be locked out of changing it, and a mistyped
        // short password would be told "use at least 8 characters" when the real
        // answer is simply "that is not your password".
        $fields = Validator::collect([
            'current_password' => Validator::required($current, 'Your current password'),
            'password'         => Validator::password($new),
        ]);
        if ($fields !== []) {
            return $this->fail('validation_error', 'Please fix the highlighted fields.', $fields);
        }

        if (!RateLimiter::attempt("pwchange:ip:{$ip}", 5, self::RATE_WINDOW)) {
            return $this->fail('rate_limited', 'Too many attempts. Please wait a few minutes and try again.', [], 429);
        }

        $hash = Database::run('SELECT password_hash FROM users WHERE id = :id', ['id' => $userId])->fetchColumn();
        if ($hash === false || !password_verify($current, (string) $hash)) {
            // One message for "wrong password" and "no such row": the session
            // already proves the account exists, so there is nothing to protect.
            return $this->fail('invalid_credentials', 'Your current password is not correct.', ['current_password' => 'Not correct.'], 401);
        }

        if (password_verify($new, (string) $hash)) {
            return $this->fail('validation_error', 'Choose a password you do not already use here.', ['password' => 'This is already your password.']);
        }

        $now = Database::now();
        Database::run(
            // password_changed_at is what lets a session minted before this
            // moment be recognised as stale — see ProfileModel/AuthController.
            'UPDATE users SET password_hash = :h, password_changed_at = :now, updated_at = :now2 WHERE id = :id',
            ['h' => password_hash($new, PASSWORD_DEFAULT), 'now' => $now, 'now2' => $now, 'id' => $userId]
        );

        RateLimiter::clear("pwchange:ip:{$ip}");

        return $this->pass(['password_changed' => true]);
    }

    /**
     * Delete the account and everything attached to it.
     *
     * The password is required for the same reason as the change: a session on an
     * unattended machine should not be able to destroy the account. The foreign
     * keys cascade, so `user_preferences`, `email_otps` and
     * `email_verification_tokens` go with the row.
     *
     * @return array{ok:bool,code:string,message:string,fields:array<string,string>,data:mixed}
     */
    public function deleteAccount(int $userId, string $password, string $ip): array
    {
        if (!RateLimiter::attempt("acctdelete:ip:{$ip}", 5, self::RATE_WINDOW)) {
            return $this->fail('rate_limited', 'Too many attempts. Please wait a few minutes and try again.', [], 429);
        }

        $hash = Database::run('SELECT password_hash FROM users WHERE id = :id', ['id' => $userId])->fetchColumn();
        if ($hash === false || !password_verify($password, (string) $hash)) {
            return $this->fail('invalid_credentials', 'Your password is not correct.', ['password' => 'Not correct.'], 401);
        }

        Database::run('DELETE FROM users WHERE id = :id', ['id' => $userId]);

        return $this->pass(['deleted' => true]);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Write the user's whole preferences row.
     *
     * Always all five columns, from the values the caller merged with what is
     * already stored. An earlier version took a partial set and used
     * `col = IF(:v IS NULL, col, :v)` to leave absent columns alone — which
     * quietly made it impossible to clear a location, because clearing means
     * writing NULL and that expression refuses to. Reading one row by primary key
     * and writing the whole thing is simpler and cannot get that wrong.
     */
    private function store(int $userId, string $activities, ?string $name, ?float $lat, ?float $lon): void
    {
        Database::run(
            'INSERT INTO user_preferences (user_id, activities, location_name, location_lat, location_lon, updated_at)
             VALUES (:id, :acts, :name, :lat, :lon, :now)
             ON DUPLICATE KEY UPDATE
                activities = VALUES(activities),
                location_name = VALUES(location_name),
                location_lat = VALUES(location_lat),
                location_lon = VALUES(location_lon),
                updated_at = VALUES(updated_at)',
            [
                'id'   => $userId,
                'acts' => $activities,
                'name' => $name,
                'lat'  => $lat,
                'lon'  => $lon,
                'now'  => Database::now(),
            ]
        );
    }

    /**
     * "1.4927, 103.7414" — an honest coordinate beats an invented city name.
     *
     * Matches the fallback AirModel uses for an unresolved place, so a saved
     * location with no name and a reading with no name look the same.
     */
    private static function coordinateLabel(float $lat, float $lon): string
    {
        return sprintf('%.4F, %.4F', $lat, $lon);
    }

    /** The stored row as the four writable values, defaults filled in. */
    private function current(int $userId): array
    {
        $prefs = $this->read($userId);
        $loc = $prefs['location'];

        return [
            'activities' => implode(',', $prefs['activities']),
            'name'       => $loc['name'] ?? null,
            'lat'        => $loc['lat'] ?? null,
            'lon'        => $loc['lon'] ?? null,
        ];
    }

    /**
     * Keep only known slugs, in the order they are declared, with no duplicates.
     *
     * @param list<string> $values
     * @return list<string>
     */
    private static function cleanActivities(array $values): array
    {
        $kept = [];
        foreach (array_keys(self::ACTIVITIES) as $slug) {
            if (in_array($slug, $values, true)) {
                $kept[] = $slug;
            }
        }
        return $kept;
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