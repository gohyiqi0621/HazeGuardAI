<?php
declare(strict_types=1);

namespace HazeGuard\Component\Hazeguard\Site\Model;

use HazeGuard\Config;
use HazeGuard\Http;

/**
 * The air-quality reading, and the name of the place it belongs to.
 *
 * All of this used to run in the browser (`media/com_hazeguard/js/airquality.js`),
 * which meant the two upstream calls, the city lookup, the AQI band, the band
 * position and every user-facing string were decided by client JavaScript. It
 * runs here now: the client's only remaining jobs are the things a server
 * cannot do — asking the browser for a position, and writing the answer into
 * the DOM.
 *
 * What that buys, beyond moving code:
 *   - one upstream pair of calls per coordinate per TTL, shared by every
 *     visitor, instead of one per visitor;
 *   - a place to hold a last-good reading, so a flaky upstream degrades to a
 *     clearly-labelled stale reading rather than to an empty instrument;
 *   - the bands and their wording exist once, in PHP, not once per client.
 *
 * Sources (both keyless, which is why this was safe to run client-side before):
 *   Open-Meteo Air Quality API — https://open-meteo.com/en/docs/air-quality-api
 *   BigDataCloud reverse-geocode-client — https://www.bigdatacloud.com/
 *
 * The numbers are CAMS modelled fields on an 11–45 km grid, not a ground-station
 * measurement, and `sourceNote` says so wherever they are shown. A model output
 * presented as a measurement is the exact dishonesty this app exists to avoid.
 *
 * Why the city name is not taken from Open-Meteo's `timezone`: every state in
 * Peninsular Malaysia shares the single IANA zone `Asia/Kuala_Lumpur`, so Kuala
 * Lumpur, Johor Bahru, Penang, Ipoh and Kuantan all came out as "Kuala Lumpur"
 * and Kota Kinabalu came out as "Kuching". A timezone is a region-level signal
 * and cannot name a city. The map compliance whitelist (Tencent / AMap / Baidu
 * / Tianditu) covers mainland-China coordinates only, so there is no
 * whitelisted free reverse geocoder for Malaysia.
 */
final class AirModel
{
    private const ENDPOINT = 'https://air-quality-api.open-meteo.com/v1/air-quality';
    private const FIELDS   = 'us_aqi,pm2_5,pm10,european_aqi';
    private const GEOCODER = 'https://api.bigdatacloud.net/data/reverse-geocode-client';

    private const READ_TIMEOUT    = 6;
    private const GEOCODE_TIMEOUT = 4;

    /** Open-Meteo's `current` block advances hourly, so faster polling cannot help. */
    private const REFRESH_SECONDS = 3600;

    /**
     * Shown when the visitor will not share a location. A Malaysia haze app
     * defaulting to the Klang Valley is a better first paint than an empty
     * instrument — as long as nothing claims it is their location.
     */
    private const HOME = ['name' => 'Kuala Lumpur', 'lat' => 3.1412, 'lon' => 101.68653];

    /**
     * US AQI bands. `ribbon` is a CSS class, `label` is shown to the user, so
     * the wording lives here rather than being assembled in the view.
     *
     * @var list<array{max:int|null,label:string,ribbon:string}>
     */
    private const CATEGORIES = [
        ['max' => 50,   'label' => 'Good',                           'ribbon' => 'ribbon--good'],
        ['max' => 100,  'label' => 'Moderate',                       'ribbon' => 'ribbon--2'],
        ['max' => 150,  'label' => 'Unhealthy for sensitive groups', 'ribbon' => 'ribbon--3'],
        ['max' => 200,  'label' => 'Unhealthy',                      'ribbon' => 'ribbon--4'],
        ['max' => null, 'label' => 'Very unhealthy',                 'ribbon' => 'ribbon--4'],
    ];

    private const SOURCE_NOTE = 'Open-Meteo Air Quality API (CAMS model grid, a modelled estimate '
        . 'rather than a ground-station measurement). Refreshes hourly.';

    private const SOURCE_SHORT = 'Live reading · Open-Meteo Air Quality API '
        . '(modelled estimate, not a station measurement).';

    /**
     * The reading for a coordinate.
     *
     * Three sources, in this order:
     *   1. an explicit coordinate (a radar search, or the device position);
     *   2. the signed-in account's saved location;
     *   3. the default city, labelled as not being the visitor's.
     *
     * Saved outranks default but not an explicit coordinate, and the two never
     * actually compete: a signed-in client sends no coordinate at all when it
     * wants the saved row, because that row is already kept in step with the
     * device on each load. An explicit coordinate is someone pointing at a
     * place — the radar's search, or a browser reporting where it is while
     * signed out — and answering with a different place would answer the
     * wrong question.
     *
     * @param array{name?:string,lat:float,lon:float}|null $preferred
     * @return array{ok:true,data:array<string,mixed>}|array{ok:false,code:string,message:string,status:int}
     */
    public function current(?float $lat, ?float $lon, ?array $preferred = null): array
    {
        $point = $this->pointFor($lat, $lon, $preferred);

        $stale = false;

        try {
            $current = $this->reading($point['lat'], $point['lon']);
        } catch (\RuntimeException $e) {
            error_log('[hazeguard] air quality unavailable: ' . $e->getMessage());

            // A cached reading that is merely old still beats no reading, as
            // long as the client labels it stale. That is the contract.
            $fallback = $this->cached(self::pointKey($point['lat'], $point['lon']), true);
            if ($fallback === null) {
                return [
                    'ok'      => false,
                    'code'    => 'air_unavailable',
                    'status'  => 502,
                    'message' => 'The air-quality service could not be reached.',
                ];
            }

            $current = $fallback['current'];
            $stale   = true;
        }

        $data = self::present($current, $point, $this->nameFor($point));
        $data['stale'] = $stale;

        return ['ok' => true, 'data' => $data];
    }

    /**
     * Real readings for a spread of points around a coordinate, in one upstream call.
     *
     * Open-Meteo accepts comma-separated coordinates and answers with an array of
     * readings in the same order, so a whole ring of points costs the same single
     * request as one point. That is what makes the Haze Radar affordable at all:
     * nineteen separate calls would be nineteen cache entries, nineteen timeouts
     * and nineteen chances for one of them to fail and leave a hole in the map.
     *
     * The grid is deliberately coarse — two rings, six and twelve points. CAMS
     * reads on an 11–45 km grid, so two pins a kilometre apart come back
     * byte-identical; sampling finer would print the same number nineteen times
     * and imply a precision the data does not have. `provenance` says exactly how
     * many points were sampled, and the client repeats it, so the map cannot be
     * mistaken for per-neighbourhood truth.
     *
     * @param array{name?:string,lat:float,lon:float}|null $preferred
     * @return array{ok:true,data:array<string,mixed>}|array{ok:false,code:string,message:string,status:int}
     */
    public function around(?float $lat, ?float $lon, ?array $preferred, float $radiusKm): array
    {
        // The same precedence as current(), and for the same reason: asking the
        // radar "around here" must not mean something different from asking the
        // instrument "here". With no coordinate the grid centres on the saved
        // location, or on the default city.
        $point = $this->pointFor($lat, $lon, $preferred);
        $lat   = $point['lat'];
        $lon   = $point['lon'];

        $key  = sprintf('grid:%.2f,%.2f,%.1f', $lat, $lon, $radiusKm);
        $grid = self::grid($lat, $lon, $radiusKm);

        $cached = $this->cached($key, false);
        if ($cached !== null) {
            $data          = $cached['current'];
            $data['stale'] = false;
            $data['cached'] = true;

            return ['ok' => true, 'data' => self::withCentre($data, $point)];
        }

        try {
            $json = Http::getJson(self::ENDPOINT, [
                'latitude'  => implode(',', array_map(self::coord(...), array_column($grid, 'lat'))),
                'longitude' => implode(',', array_map(self::coord(...), array_column($grid, 'lon'))),
                'current'   => self::FIELDS,
                'timezone'  => 'auto',
            ], self::READ_TIMEOUT);
        } catch (\RuntimeException $e) {
            error_log('[hazeguard] air quality unavailable for the radar grid: ' . $e->getMessage());

            $fallback = $this->cached($key, true);
            if ($fallback === null) {
                return [
                    'ok'      => false,
                    'code'    => 'air_unavailable',
                    'status'  => 502,
                    'message' => 'The air-quality service could not be reached.',
                ];
            }

            $data          = $fallback['current'];
            $data['stale'] = true;
            $data['cached'] = true;

            return ['ok' => true, 'data' => self::withCentre($data, $point)];
        }

        // One coordinate in gives one object back; many in give an array of those
        // objects, in the order they were asked for. That positional contract is
        // the whole reason this method is a single request, so a short array must
        // not silently shift every reading onto the wrong pin.
        $rows = self::currentRows($json);

        $points = [];
        foreach ($grid as $index => $spot) {
            $current = $rows[$index] ?? null;
            if (!is_array($current) || !is_numeric($current['us_aqi'] ?? null)) {
                continue; // Missing is missing. A dropped pin, never a painted zero.
            }

            $aqi  = (int) round((float) $current['us_aqi']);
            $band = self::category($aqi);

            $points[] = [
                'lat'    => $spot['lat'],
                'lon'    => $spot['lon'],
                'aqi'    => $aqi,
                'pm25'   => self::whole($current['pm2_5'] ?? null),
                'pm10'   => self::whole($current['pm10'] ?? null),
                'label'  => $band['label'],
                'ribbon' => $band['ribbon'],
                'band'   => $band['index'],
            ];
        }

        if ($points === []) {
            return [
                'ok'      => false,
                'code'    => 'air_unavailable',
                'status'  => 502,
                'message' => 'The air-quality service returned no readings for that area.',
            ];
        }

        $data = [
            'radiusKm'      => $radiusKm,
            'points'        => $points,
            'sampled'       => count($grid),
            'returned'      => count($points),
            'observedLabel' => self::stamp((string) ($rows[0]['time'] ?? '')),
            'provenance'    => sprintf(
                '%d sample points over %s km, from one reading of a %s model grid. '
                . 'Nearby pins can share a value because the grid is coarser than the map.',
                count($points),
                rtrim(rtrim(number_format($radiusKm, 1), '0'), '.'),
                // Double quotes on purpose: PHP does not read \u{} escapes in a
                // single-quoted string, so the single-quoted form silently ships
                // the literal characters "u{2013}" to the browser.
                "11\u{2013}45 km CAMS"
            ),
            'sourceNote'    => self::SOURCE_NOTE,
            'sourceShort'   => self::SOURCE_SHORT,
            'stale'         => false,
            'cached'        => false,
        ];

        $this->store($key, $data);

        return ['ok' => true, 'data' => self::withCentre($data, $point)];
    }

    /**
     * Stamp the centre's name and provenance onto a grid result.
     *
     * Those belong to the *request*, not to the grid: "around here" with no
     * coordinates is the default city, while the same grid asked about by an
     * explicit coordinate is a place someone pointed at. The two share one cache
     * entry — the readings really are identical — so the label is applied after
     * the cache is read. Storing it instead would let whichever request happened
     * to arrive first freeze its own wording onto everyone else's, and a
     * coordinate the visitor did not choose would be labelled as if they had.
     */
    private static function withCentre(array $data, array $point): array
    {
        $data['centre'] = [
            'name'   => $point['name'] ?? null,
            'lat'    => round((float) $point['lat'], 4),
            'lon'    => round((float) $point['lon'], 4),
            'source' => $point['source'],
        ];

        return $data;
    }

    /**
     * The centre plus two rings of points: 1 + 6 + 12 = 19.
     *
     * Rings sit at a third and two thirds of the radius so the pins form readable
     * bands instead of a random cloud, and the count doubles outward because a ring
     * is longer the further out it is.
     *
     * @return list<array{lat:float,lon:float}>
     */
    private static function grid(float $lat, float $lon, float $radiusKm): array
    {
        $points = [['lat' => round($lat, 4), 'lon' => round($lon, 4)]];

        // Longitude degrees shrink towards the poles, so a degree of east is not
        // the same distance as a degree of north. Without this the rings flatten
        // into ellipses, which is obvious the moment anyone uses the map in
        // KL rather than on the equator.
        $perDegreeLon = 111.0 * max(0.05, cos(deg2rad($lat)));

        foreach ([[1.0 / 3.0, 6], [2.0 / 3.0, 12]] as [$fraction, $count]) {
            $radius = $radiusKm * $fraction;

            for ($i = 0; $i < $count; $i++) {
                $bearing = deg2rad(360.0 * $i / $count);

                $points[] = [
                    'lat' => round(min(89.9, max(-89.9, $lat + ($radius * cos($bearing)) / 111.0)), 4),
                    'lon' => round(min(179.9, max(-179.9, $lon + ($radius * sin($bearing)) / $perDegreeLon)), 4),
                ];
            }
        }

        return $points;
    }

    /**
     * The `current` blocks from an Open-Meteo air-quality response, in request order.
     *
     * The provider answers **two different shapes** depending on how many
     * coordinates were asked for: one coordinate gives `{"current": {...}}`, while
     * several give a top-level **array**, one entry per point. Reading only the
     * first shape is how a nineteen-point grid silently returns nothing — it fails
     * as an empty map rather than as an error, which is the worst way to fail.
     *
     * Entries that carry no reading become null, not a shift: the grid index is
     * what pairs a reading with a pin, so a gap must stay a gap.
     *
     * @return list<array<string,mixed>|null>
     */
    private static function currentRows(array $json): array
    {
        if (array_is_list($json)) {
            return array_values(array_map(
                static fn ($entry): ?array => is_array($entry['current'] ?? null) ? $entry['current'] : null,
                $json
            ));
        }

        $current = $json['current'] ?? null;

        return is_array($current) ? [$current] : [];
    }

    /** Enough precision to be found again, few enough digits to keep the URL short. */
    private static function coord(float $value): string
    {
        return rtrim(rtrim(sprintf('%.4F', $value), '0'), '.');
    }

    /**
     * Decide which point to read, before any network call.
     *
     * Split out from current() so the choice can be tested on its own: it is the
     * whole of the "which city is this?" decision, and testing it through
     * current() would mean calling the live API to check an array.
     *
     * @param array{name?:string,lat:float,lon:float}|null $preferred
     * @return array{name:?string,lat:float,lon:float,source:string}
     */
    private function pointFor(?float $lat, ?float $lon, ?array $preferred): array
    {
        if ($lat !== null && $lon !== null) {
            // An explicit coordinate never borrows the saved location's name: the
            // two are different places and a borrowed label would misread.
            return ['name' => null, 'lat' => $lat, 'lon' => $lon, 'source' => 'device'];
        }

        if ($preferred !== null) {
            $name = trim((string) ($preferred['name'] ?? ''));

            return [
                'name'   => $name === '' ? null : $name,
                'lat'    => (float) $preferred['lat'],
                'lon'    => (float) $preferred['lon'],
                'source' => 'saved',
            ];
        }

        return self::HOME + ['source' => 'default'];
    }

    /**
     * The current block from Open-Meteo, cache first.
     *
     * @return array<string,mixed>
     */
    private function reading(float $lat, float $lon): array
    {
        $cached = $this->cached(self::pointKey($lat, $lon), false);
        if ($cached !== null) {
            return $cached['current'];
        }

        $json = Http::getJson(self::ENDPOINT, [
            'latitude'  => $lat,
            'longitude' => $lon,
            'current'   => self::FIELDS,
            'timezone'  => 'auto',
        ], self::READ_TIMEOUT);

        $current = $json['current'] ?? null;
        if (!is_array($current) || !is_numeric($current['us_aqi'] ?? null)) {
            throw new \RuntimeException('the air-quality response carried no current reading');
        }

        $this->store(self::pointKey($lat, $lon), $current);

        return $current;
    }

    /**
     * The nearest named settlement for a coordinate, or null.
     *
     * Public because the profile screen needs it too: a saved location must be
     * stored with the city it actually is, and the client's own idea of the name
     * cannot be trusted for that — it may have been a fallback string.
     *
     * Never throws: the geocoder is a nicety, not the product, so an outage costs
     * a place name and must not cost the operation that asked for it.
     */
    public function city(float $lat, float $lon): ?string
    {
        try {
            $json = Http::getJson(self::GEOCODER, [
                'latitude'         => $lat,
                'longitude'        => $lon,
                'localityLanguage' => 'en',
            ], self::GEOCODE_TIMEOUT);
        } catch (\RuntimeException $e) {
            error_log('[hazeguard] reverse geocode unavailable: ' . $e->getMessage());
            return null;
        }

        // `city` is the settlement; `locality` covers the places it leaves blank.
        $name = trim((string) ($json['city'] ?? ''));
        if ($name === '') {
            $name = trim((string) ($json['locality'] ?? ''));
        }

        return $name === '' ? null : $name;
    }

    /** The default city already knows its own name — do not ask the geocoder. */
    private function nameFor(array $point): ?string
    {
        $known = $point['name'] ?? null;
        if (is_string($known) && $known !== '') {
            return $known;
        }

        return $this->city($point['lat'], $point['lon']);
    }

    /**
     * The wire payload. Everything the instrument prints is decided here, so
     * the client only has to place strings.
     *
     * @param array<string,mixed> $current
     * @param array{name:?string,lat:float,lon:float,source:string} $point
     * @return array<string,mixed>
     */
    private static function present(array $current, array $point, ?string $name): array
    {
        $aqi   = (int) round((float) $current['us_aqi']);
        $band  = self::category($aqi);
        $place = $name ?? self::coordinates($point['lat'], $point['lon']);
        $time  = (string) ($current['time'] ?? '');

        return [
            'place' => [
                'name'   => $place,
                'lat'    => $point['lat'],
                'lon'    => $point['lon'],
                'source' => $point['source'],
            ],
            'aqi'           => $aqi,
            'pm25'          => self::whole($current['pm2_5'] ?? null),
            'pm10'          => self::whole($current['pm10'] ?? null),
            'category'      => ['label' => $band['label'], 'ribbon' => $band['ribbon']],
            'bandPos'       => round(min($aqi, 300) / 300 * 100, 1),
            'bandLabel'     => sprintf('Air quality index %d of 300, in the %s band', $aqi, $band['label']),
            'observedAt'    => $time,
            'observedLabel' => self::stamp($time),
            'provenance'    => match ($point['source']) {
                'device' => 'For your location',
                // The place is the one the account chose, and saying only
                // "your location" would claim it is the phone's position.
                'saved'  => sprintf('For your saved location — %s', $place),
                default  => sprintf('For %s — your location was not shared', $place),
            },
            'sourceNote'    => self::SOURCE_NOTE,
            'sourceShort'   => self::SOURCE_SHORT,
            'refreshMs'     => self::REFRESH_SECONDS * 1000,
        ];
    }

    /**
     * The band an AQI falls in, with its 1-based position.
     *
     * The index travels with it because the map pins are coloured by it: sending
     * the label alone would make the browser re-derive the same boundary table
     * this file already holds, which is how two copies drift.
     *
     * @return array{max:int|null,label:string,ribbon:string,index:int}
     */
    private static function category(int $aqi): array
    {
        foreach (self::CATEGORIES as $index => $band) {
            if ($band['max'] === null || $aqi <= $band['max']) {
                return $band + ['index' => $index + 1];
            }
        }

        $last = count(self::CATEGORIES) - 1;

        return self::CATEGORIES[$last] + ['index' => $last + 1];
    }

    /** "1.493, 103.741" — an honest coordinate beats a city we did not resolve. */
    private static function coordinates(float $lat, float $lon): string
    {
        return sprintf('%.3F, %.3F', $lat, $lon);
    }

    /** A rounded integer, or null when the field is missing. Never a stand-in. */
    private static function whole(mixed $value): ?int
    {
        return is_numeric($value) ? (int) round((float) $value) : null;
    }

    /**
     * "12 Oct, 00:00" in the reading's own local time.
     *
     * Open-Meteo returns `current.time` as a local wall-clock string with no
     * offset (timezone=auto), so it is parsed and re-formatted without ever
     * being converted: the visitor sees the hour where the air is, not the hour
     * where their laptop is.
     */
    private static function stamp(string $iso): string
    {
        if ($iso === '') {
            return 'the latest model run';
        }

        try {
            $when = new \DateTimeImmutable($iso);
        } catch (\Exception) {
            return 'the latest model run';
        }

        return $when->format('j M, H:i');
    }

    /* ------------------------------------------------------------------ cache

       A file cache, not a table: one server, one directory, no migration, and
       the whole thing is disposable — a cache we cannot read or write is a
       performance loss and never a failure. A multi-server deploy would want
       the database or Redis instead; this is the smallest thing that works. */

    /**
     * @return array{current:array<string,mixed>,fresh:bool}|null
     */
    private function cached(string $key, bool $allowStale): ?array
    {
        $raw   = @file_get_contents($this->cacheDir() . DIRECTORY_SEPARATOR . sha1($key) . '.json');
        $entry = $raw === false ? null : json_decode($raw, true);

        if (!is_array($entry) || !is_array($entry['current'] ?? null) || !isset($entry['at'])) {
            return null;
        }

        if (time() - (int) $entry['at'] > self::cacheTtl()) {
            return $allowStale ? ['current' => $entry['current'], 'fresh' => false] : null;
        }

        return ['current' => $entry['current'], 'fresh' => true];
    }

    /**
     * @param array<string,mixed> $current
     * @param string               $key     A caller-chosen namespace, so the
     *                                         radar's whole ring of points shares
     *                                         one cache entry instead of one per
     *                                         pin.
     */
    private function store(string $key, array $current): void
    {
        $dir = $this->cacheDir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }

        $file = $dir . DIRECTORY_SEPARATOR . sha1($key) . '.json';
        $tmp  = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $json = json_encode(['at' => time(), 'current' => $current], JSON_UNESCAPED_SLASHES);

        if ($json !== false && @file_put_contents($tmp, $json, LOCK_EX) !== false && @rename($tmp, $file)) {
            return;
        }

        @unlink($tmp);
    }

    /**
     * Two decimals is about 1.1 km — finer than the model grid, so rounding can
     * never merge two points that would report different numbers.
     */
    private static function pointKey(float $lat, float $lon): string
    {
        return sprintf('point:%.2f,%.2f', $lat, $lon);
    }

    private function cacheDir(): string
    {
        $dir = trim((string) Config::get('AIR_CACHE_DIR', 'storage/cache/air'));
        if (!str_starts_with($dir, '/') && preg_match('#^[A-Za-z]:[\\\\/]#', $dir) !== 1) {
            $dir = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . $dir;
        }

        return rtrim($dir, '/\\');
    }

    private static function cacheTtl(): int
    {
        // An hour, matching the model: `current` cannot change within the hour,
        // so a longer TTL cannot serve a wrong number. It also means the slow
        // first upstream call is paid once per coordinate per hour, not once
        // per visitor.
        $ttl = Config::int('AIR_CACHE_TTL', 3600);

        return $ttl > 0 ? $ttl : 3600;
    }
}
