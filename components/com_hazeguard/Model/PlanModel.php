<?php
declare(strict_types=1);

namespace HazeGuard\Component\Hazeguard\Site\Model;

use HazeGuard\Database;
use HazeGuard\RateLimiter;

/**
 * The planner screen's saved plans.
 *
 * Every method takes the user id from the session, never from the request, so
 * one account can never list, read or delete another's plans. Result shape
 * matches ProfileModel/AuthModel, so the same JsonView renders all three.
 *
 * There is no update(): a plan is a decision about a moment ("6 PM today"), and
 * editing it in place would let the stored verdict drift away from the plan it
 * describes. Saving the same activity at a new time writes a second row, which
 * is what a planner is for.
 */
final class PlanModel
{
    /** How many plans one account may hold. Bounded so the list stays usable. */
    public const MAX_PLANS = 60;

    /** The intensities a plan may carry; the allowlist, mirrored by the ENUM. */
    public const INTENSITIES = ['light', 'moderate', 'intense'];

    /** The preference slugs the planner's collapsed block offers. */
    public const PREFS = ['lower-aqi', 'shade', 'nearby', 'short'];

    private const RATE_WINDOW = 900; // 15 minutes
    private const MAX_PER_WINDOW = 40;

    /**
     * The account's plans, newest first.
     *
     * @return array{plans:list<array<string,mixed>>}
     */
    public function all(int $userId): array
    {
        $rows = Database::run(
            'SELECT id, activity, intensity, place_name, place_lat, place_lon,
                    hours_offset, starts_at, verdict_aqi, prefs, created_at
               FROM activity_plans
              WHERE user_id = :id
              ORDER BY created_at DESC, id DESC',
            ['id' => $userId]
        )->fetchAll();

        return ['plans' => array_map(self::present(...), $rows)];
    }

    /** Wrap a payload in the standard result envelope (see JsonView). */
    public function result(array $data): array
    {
        return $this->pass($data);
    }

    /**
     * Save one plan for the signed-in account.
     *
     * Every field the verdict depends on is echoed back and stored, so the row
     * is a record of the decision rather than a pointer to a recomputation:
     * the activity's own intensity, the hour it was planned for, and the AQI the
     * verdict was read from.
     *
     * @param list<string> $prefs
     * @return array{ok:bool,code:string,message:string,fields:array<string,string>,data:mixed,status?:int}
     */
    public function create(int $userId, array $input, string $ip): array
    {
        $activity = trim((string) ($input['activity'] ?? ''));
        $intensity = strtolower(trim((string) ($input['intensity'] ?? 'moderate')));
        $place = trim((string) ($input['place_name'] ?? ''));
        $hour = (int) ($input['hour'] ?? 0);
        $dayOffset = (int) ($input['day_offset'] ?? 0);
        $aqi = (int) ($input['verdict_aqi'] ?? 0);
        $prefs = array_values(array_unique(array_filter(
            array_map(static fn (mixed $p): string => is_scalar($p) ? trim((string) $p) : '', $input['prefs'] ?? []),
            static fn (string $p): bool => $p !== ''
        )));

        $fields = [];

        if ($activity === '') {
            $fields['activity'] = 'Choose or type an activity first.';
        } elseif (mb_strlen($activity) > 60) {
            $fields['activity'] = 'Keep the activity name to 60 characters.';
        }

        if (!in_array($intensity, self::INTENSITIES, true)) {
            $fields['intensity'] = 'That effort level is not one of the options.';
        }

        if ($place === '') {
            $fields['place_name'] = 'Pick a location first.';
        } elseif (mb_strlen($place) > 120) {
            $fields['place_name'] = 'Keep the place name to 120 characters.';
        }

        if ($hour < 0 || $hour > 23) {
            $fields['hour'] = 'Pick an hour between 0 and 23.';
        }
        // Five days ahead is what the day strip offers; anything else is a client
        // that has drifted out of step with the screen.
        if ($dayOffset < 0 || $dayOffset > 4) {
            $fields['day_offset'] = 'That day is outside the five-day window.';
        }
        if ($aqi < 0 || $aqi > 500) {
            $fields['verdict_aqi'] = 'That reading is not a valid AQI.';
        }

        $unknownPrefs = array_diff($prefs, self::PREFS);
        if ($unknownPrefs !== []) {
            $fields['prefs'] = 'One of those preferences is not an option.';
        }

        if ($fields !== []) {
            return $this->fail('validation_error', 'Please fix the highlighted fields.', $fields);
        }

        if (!RateLimiter::attempt("plan:ip:{$ip}", self::MAX_PER_WINDOW, self::RATE_WINDOW)) {
            return $this->fail('rate_limited', 'Too many plans saved. Please wait a few minutes.', [], 429);
        }

        $held = (int) Database::run(
            'SELECT COUNT(*) FROM activity_plans WHERE user_id = :id',
            ['id' => $userId]
        )->fetchColumn();

        if ($held >= self::MAX_PLANS) {
            return $this->fail(
                'plan_limit',
                'You have reached ' . self::MAX_PLANS . ' saved plans. Remove one to save another.',
                [],
                409
            );
        }

        // The offset is resolved to an absolute UTC timestamp *here*, once, from
        // the server clock — the browser's idea of "now" is not trusted for a
        // value that is stored and later printed as a date.
        $startsAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify(sprintf('+%d days', $dayOffset))
            ->setTime($hour, 0)
            ->format('Y-m-d H:i:s');

        // Rounded to 4dp before storage, matching ProfileModel::saveLocation: the
        // model grid has no finer precision, so storing more would imply it does.
        $lat = isset($input['place_lat']) && is_numeric($input['place_lat']) ? round((float) $input['place_lat'], 4) : null;
        $lon = isset($input['place_lon']) && is_numeric($input['place_lon']) ? round((float) $input['place_lon'], 4) : null;
        if ($lat !== null && ($lat < -90.0 || $lat > 90.0)) {
            $lat = $lon = null;
        }
        if ($lon !== null && ($lon < -180.0 || $lon > 180.0)) {
            $lat = $lon = null;
        }

        Database::run(
            'INSERT INTO activity_plans
                (user_id, activity, intensity, place_name, place_lat, place_lon,
                 hours_offset, starts_at, verdict_aqi, prefs, created_at)
             VALUES (:u, :a, :i, :p, :lat, :lon, :off, :starts, :aqi, :prefs, :now)',
            [
                'u'      => $userId,
                'a'      => $activity,
                'i'      => $intensity,
                'p'      => $place,
                'lat'    => $lat,
                'lon'    => $lon,
                'off'    => $dayOffset * 24,
                'starts' => $startsAt,
                'aqi'    => $aqi,
                'prefs'  => implode(',', $prefs),
                'now'    => Database::now(),
            ]
        );

        $plan = $this->find($userId, (int) Database::pdo()->lastInsertId());

        return $this->pass(['plan' => $plan]);
    }

    /**
     * Remove one of the account's plans.
     *
     * Scoped by user_id in the WHERE clause rather than checked afterwards, so a
     * guessed id belonging to somebody else matches no row and is reported as
     * "not found" — the same answer as an id that never existed.
     *
     * @return array{ok:bool,code:string,message:string,fields:array<string,string>,data:mixed,status?:int}
     */
    public function delete(int $userId, int $planId): array
    {
        if ($planId <= 0) {
            return $this->fail('not_found', 'That plan could not be found.', [], 404);
        }

        $stmt = Database::run(
            'DELETE FROM activity_plans WHERE id = :id AND user_id = :u',
            ['id' => $planId, 'u' => $userId]
        );

        if ($stmt->rowCount() === 0) {
            return $this->fail('not_found', 'That plan could not be found.', [], 404);
        }

        return $this->pass(['deleted' => true, 'id' => $planId]);
    }

    /** One of the account's plans, or null when it is not theirs to read. */
    public function find(int $userId, int $planId): ?array
    {
        $row = Database::run(
            'SELECT id, activity, intensity, place_name, place_lat, place_lon,
                    hours_offset, starts_at, verdict_aqi, prefs, created_at
               FROM activity_plans
              WHERE id = :id AND user_id = :u',
            ['id' => $planId, 'u' => $userId]
        )->fetch();

        return $row === false ? null : self::present($row);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * A stored row as the client's shape.
     *
     * Types are cast here rather than left as PDO strings: the client compares
     * `plan.id` and prints `plan.verdict_aqi`, and a string id compared with
     * `===` against a number silently fails to match.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function present(array $row): array
    {
        return [
            'id'           => (int) $row['id'],
            'activity'     => (string) $row['activity'],
            'intensity'    => (string) $row['intensity'],
            'place'        => (string) $row['place_name'],
            'lat'          => $row['place_lat'] === null ? null : (float) $row['place_lat'],
            'lon'          => $row['place_lon'] === null ? null : (float) $row['place_lon'],
            'hours_offset' => (int) $row['hours_offset'],
            'starts_at'    => (string) $row['starts_at'],
            'verdict_aqi'  => (int) $row['verdict_aqi'],
            'prefs'        => $row['prefs'] === '' ? [] : explode(',', (string) $row['prefs']),
            'created_at'   => (string) $row['created_at'],
        ];
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
