<?php
declare(strict_types=1);

namespace HazeGuard\Component\Hazeguard\Site\Model;

use HazeGuard\Http;
use HazeGuard\RateLimiter;

/**
 * Turning what a visitor typed into a point on the map.
 *
 * Forward geocoding ("Kuala Lumpur" -> 3.139, 101.694) is what the profile
 * screen's Change button needs. It is a different provider from the reverse
 * lookup AirModel uses, because the keyless reverse-geocode endpoint returns 403
 * on its search path — see FORWARD below.
 *
 * Forward:  Photon (photon.komoot.io), keyless, OpenStreetMap data
 * Reverse:  BigDataCloud reverse-geocode-client, keyless — reused from AirModel
 *
 * Both are third-party geocoders serving Malaysian coordinates. That is the same
 * trade-off already accepted for the reverse lookup: the map-provider
 * compliance list covers mainland-China coordinates only, so there is no
 * compliant free geocoder for Malaysia, and geocoding is data rather than a
 * basemap.
 */
final class PlacesModel
{
    private const FORWARD = 'https://photon.komoot.io/api/';
    private const REVERSE = 'https://api.bigdatacloud.net/data/reverse-geocode-client';

    private const TIMEOUT = 5;
    private const RATE_WINDOW = 300; // 5 minutes

    /**
     * Places matching a search term, most relevant first.
     *
     * @return list<array{name:string,lat:float,lon:float,country:string}>
     */
    public function search(string $query, string $ip): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        // Two characters is where "KL" stops being a typo and starts being a
        // real abbreviation. Below that the result list is noise, and the upstream
        // bill is paid per keystroke.
        if (mb_strlen($query) < 2) {
            return [];
        }

        // A public endpoint that proxies a third party must be bounded, or it is
        // an open relay for someone else's quota.
        if (!RateLimiter::attempt("places:ip:{$ip}", 30, self::RATE_WINDOW)) {
            return [];
        }

        try {
            $json = Http::getJson(self::FORWARD, [
                'q'        => $query,
                'limit'    => 6,
                'lang'     => 'en',
                // Bias nothing and let the provider rank: a visitor typing a city
                // name wants that city, not a suburb in another country.
                'osm_tag'  => 'place',
            ], self::TIMEOUT);
        } catch (\RuntimeException $e) {
            error_log('[hazeguard] place search unavailable: ' . $e->getMessage());
            return [];
        }

        return self::flatten($json['features'] ?? []);
    }

    /**
     * The nearest named settlement for a coordinate, or null.
     *
     * The same call AirModel makes, exposed here so the profile screen can label
     * the position it just detected without paying for an air-quality reading to
     * do it.
     */
    public function reverse(float $lat, float $lon): ?string
    {
        if ($lat < -90.0 || $lat > 90.0 || $lon < -180.0 || $lon > 180.0) {
            return null;
        }

        try {
            $json = Http::getJson(self::REVERSE, [
                'latitude'         => $lat,
                'longitude'        => $lon,
                'localityLanguage' => 'en',
            ], self::TIMEOUT);
        } catch (\RuntimeException $e) {
            error_log('[hazeguard] reverse geocode unavailable: ' . $e->getMessage());
            return null;
        }

        $name = trim((string) ($json['city'] ?? ''));
        if ($name === '') {
            $name = trim((string) ($json['locality'] ?? ''));
        }

        return $name === '' ? null : $name;
    }

    /**
     * Photon's GeoJSON features into plain place records.
     *
     * Duplicates are dropped by name+coordinate: Photon returns several entries
     * for one city at slightly different points, and offering the same city three
     * times reads as three different answers.
     *
     * @return list<array{name:string,lat:float,lon:float,country:string}>
     */
    private static function flatten(mixed $features): array
    {
        if (!is_array($features)) {
            return [];
        }

        $out  = [];
        $seen = [];

        foreach ($features as $feature) {
            $props = $feature['properties'] ?? null;
            $coords = $feature['geometry']['coordinates'] ?? null;
            if (!is_array($props) || !is_array($coords) || count($coords) < 2) {
                continue;
            }

            $name = trim((string) ($props['name'] ?? ''));
            $lat = (float) $coords[1];
            $lon = (float) $coords[0];

            if ($name === '' || !is_finite($lat) || !is_finite($lon)) {
                continue;
            }
            // Same validity bound the coordinate endpoints use.
            if ($lat < -90.0 || $lat > 90.0 || $lon < -180.0 || $lon > 180.0) {
                continue;
            }

            $key = mb_strtolower($name) . '|' . round($lat, 2) . '|' . round($lon, 2);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $out[] = [
                'name'    => $name,
                'lat'     => round($lat, 4),
                'lon'     => round($lon, 4),
                'country' => trim((string) ($props['country'] ?? '')),
            ];
        }

        return $out;
    }
}