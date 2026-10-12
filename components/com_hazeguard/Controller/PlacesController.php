<?php
declare(strict_types=1);

namespace HazeGuard\Component\Hazeguard\Site\Controller;

use HazeGuard\Component\Hazeguard\Site\Model\PlacesModel;
use HazeGuard\Mvc\Controller\BaseController;
use HazeGuard\Mvc\View\JsonView;
use HazeGuard\Request;

/**
 * Place lookup for the profile screen.
 *
 * Two reads, both public and both keyless: `search` turns what someone typed
 * into candidate places, and `reverse` names the position the browser just
 * reported. Neither changes anything, so neither needs a CSRF token — the
 * router only requires one for POST/PUT/PATCH/DELETE.
 */
final class PlacesController extends BaseController
{
    private PlacesModel $model;
    private JsonView $view;

    public function __construct()
    {
        $this->model = new PlacesModel();
        $this->view = new JsonView();
    }

    /** GET /api/places/search?q= — candidate places for a typed name. */
    public function search(): void
    {
        $places = $this->model->search(Request::str('q'), Request::ip());

        // An empty list is a successful answer, not an error: a provider that is
        // down and a name nobody has heard of look the same to the caller, and
        // distinguishing them would leak nothing useful while helping nobody.
        $this->view->render([
            'ok'   => true,
            'data' => ['places' => $places],
        ]);
    }

    /** GET /api/places/reverse?lat=&lon= — the name of a detected position. */
    public function reverse(): void
    {
        $lat = Request::str('lat');
        $lon = Request::str('lon');

        if ($lat === '' || $lon === '' || !is_numeric($lat) || !is_numeric($lon)) {
            $this->view->render(['ok' => true, 'data' => ['name' => null]]);
            return;
        }

        $name = $this->model->reverse((float) $lat, (float) $lon);

        // Never an error: the reading does not depend on the name, so a geocoder
        // outage must not surface as a failure on a screen that still works.
        $this->view->render(['ok' => true, 'data' => ['name' => $name]]);
    }
}