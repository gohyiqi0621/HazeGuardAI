<?php
declare(strict_types=1);

namespace HazeGuard\Component\Hazeguard\Site\Controller;

use HazeGuard\Component\Hazeguard\Site\Model\PlanModel;
use HazeGuard\Mvc\Controller\BaseController;
use HazeGuard\Mvc\View\JsonView;
use HazeGuard\Request;
use HazeGuard\Session;

/**
 * The planner screen's API — the saved plans behind "Save plan".
 *
 * Every task is protected and every one of them takes the user id from the
 * session rather than the request, exactly as ProfileController does. The one id
 * that *is* read from the request is the plan id on DELETE, and that is safe
 * because PlanModel::delete() puts it in the WHERE clause beside user_id: an id
 * belonging to another account matches no row.
 */
final class PlanController extends BaseController
{
    private PlanModel $model;
    private JsonView $view;

    public function __construct()
    {
        $this->model = new PlanModel();
        $this->view = new JsonView();
    }

    /** GET /api/plans — the account's saved plans, newest first. */
    public function index(): void
    {
        $user = Session::requireAuth();
        $this->view->render($this->model->result($this->model->all((int) $user['id'])));
    }

    /** POST /api/plans — save the verdict the planner is currently showing. */
    public function create(): void
    {
        $user = Session::requireAuth();

        $this->view->render($this->model->create((int) $user['id'], [
            'activity'    => Request::str('activity'),
            'intensity'   => Request::str('intensity'),
            'place_name'  => Request::str('place_name'),
            'place_lat'   => Request::str('place_lat'),
            'place_lon'   => Request::str('place_lon'),
            'hour'        => Request::str('hour'),
            'day_offset'  => Request::str('day_offset'),
            'verdict_aqi' => Request::str('verdict_aqi'),
            'prefs'       => Request::arr('prefs'),
        ], Request::ip()));
    }

    /** DELETE /api/plans/{id} — remove one of the account's own plans. */
    public function delete(): void
    {
        $user = Session::requireAuth();

        // The id arrives as the last path segment. The router strips a trailing
        // slash, so "/api/plans/7" is what reaches here.
        $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        $id = (int) substr(strrchr(rtrim($path, '/'), '/') ?: '', 1);

        $this->view->render($this->model->delete((int) $user['id'], $id));
    }
}
