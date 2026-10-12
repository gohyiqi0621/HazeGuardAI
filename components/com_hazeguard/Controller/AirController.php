<?php
declare(strict_types=1);

namespace HazeGuard\Component\Hazeguard\Site\Controller;

use HazeGuard\Component\Hazeguard\Site\Model\AirModel;
use HazeGuard\Mvc\Controller\BaseController;
use HazeGuard\Mvc\View\JsonView;
use HazeGuard\Request;

/**
 * The site's air-quality controller.
 *
 * This is the endpoint the browser used to reach around: until now the landing
 * instrument called Open-Meteo and BigDataCloud itself, from JavaScript, and
 * decided the band and the wording on the client. It now calls us, and this
 * controller is the only thing that talks to those providers.
 *
 * The base JSON view is used directly — there is no auth-specific presentation
 * to add here, and a subclass with no body would be noise.
 */
final class AirController extends BaseController
{
    private AirModel $model;
    private JsonView $view;

    public function __construct()
    {
        $this->model = new AirModel();
        $this->view = new JsonView();
    }

    /**
     * GET /api/air/current — the reading for a coordinate, for the signed-in
     * account's saved location, or for the default city.
     *
     * A read, so no CSRF token: index.php only requires one for state-changing
     * methods, and this endpoint changes nothing.
     */
    public function current(): void
    {
        $lat = $this->coordinate('lat', -90.0, 90.0);
        $lon = $this->coordinate('lon', -180.0, 180.0);

        // Both or neither: half a coordinate is not a place, and silently
        // defaulting a stray latitude to Kuala Lumpur would mislabel the air.
        $explicit = $lat !== null && $lon !== null;
        if (!$explicit) {
            $lat = null;
            $lon = null;
        }

        // Only when no coordinate was asked for. An explicit one is the caller
        // saying "this exact point" and must not be second-guessed.
        $this->view->render($this->model->current(
            $lat,
            $lon,
            $explicit ? null : $this->savedLocation()
        ));
    }

    /**
     * GET /api/air/around — readings for a spread of points, for the Haze Radar.
     *
     * The whole grid is one upstream call, so this is no more expensive than
     * /current even though it returns nineteen readings. No CSRF token: it is a
     * read, and index.php only requires one for state-changing methods.
     */
    public function around(): void
    {
        $lat = $this->coordinate('lat', -90.0, 90.0);
        $lon = $this->coordinate('lon', -180.0, 180.0);

        // Both or neither, exactly as in current(): half a coordinate is not a
        // place, and defaulting one half would centre the map somewhere nobody is.
        $explicit = $lat !== null && $lon !== null;

        // Clamped rather than refused. A radius is a zoom preference, not a
        // correctness question, so an absurd one is quietly made sensible — but
        // bounded, because the ring spacing is what keeps this at nineteen points
        // instead of becoming a scan of the planet.
        $this->view->render($this->model->around(
            $explicit ? $lat : null,
            $explicit ? $lon : null,
            $explicit ? null : $this->savedLocation(),
            $this->radius()
        ));
    }

    /**
     * The grid radius in km, clamped to something sane.
     *
     * @param float $min The narrowest ring worth drawing.
     * @param float $max The widest. Above this the nineteen points cover so much
     *                    ground that each pin's value stops being about anywhere
     *                    the user is looking.
     */
    private function radius(float $min = 5.0, float $max = 120.0): float
    {
        return max($min, min($max, $this->number('radiusKm', 30.0)));
    }

    /**
     * The signed-in account's preferred location, or null.
     *
     * Anonymous visitors get null and are answered for the default city. This is
     * one indexed primary-key read, only for someone actually signed in.
     */
    private function savedLocation(): ?array
    {
        $user = \HazeGuard\Session::user();
        if ($user === null) {
            return null;
        }

        $prefs = new \HazeGuard\Component\Hazeguard\Site\Model\ProfileModel();

        return $prefs->read((int) $user['id'])['location'];
    }

    /** A coordinate from the query string, or null when absent or out of range. */
    private function coordinate(string $key, float $min, float $max): ?float
    {
        $raw = Request::str($key);
        if ($raw === '' || !is_numeric($raw)) {
            return null;
        }

        $value = (float) $raw;

        return ($value >= $min && $value <= $max) ? $value : null;
    }

    /** A plain number from the query string, or the default. */
    private function number(string $key, float $default): float
    {
        $raw = Request::str($key);

        return ($raw !== '' && is_numeric($raw)) ? (float) $raw : $default;
    }
}
