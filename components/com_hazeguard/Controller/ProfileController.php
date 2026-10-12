<?php
declare(strict_types=1);

namespace HazeGuard\Component\Hazeguard\Site\Controller;

use HazeGuard\Component\Hazeguard\Site\Model\ProfileModel;
use HazeGuard\Mvc\Controller\BaseController;
use HazeGuard\Mvc\View\JsonView;
use HazeGuard\Request;
use HazeGuard\Session;

/**
 * The profile screen's API.
 *
 * Every task is protected and every one of them takes the user id from the
 * session rather than the request, so no route here can be pointed at another
 * account's row by changing an id. That is why there is no `?user_id=` anywhere in
 * this file — there is nothing to miss.
 */
final class ProfileController extends BaseController
{
    private ProfileModel $model;
    private JsonView $view;

    public function __construct()
    {
        $this->model = new ProfileModel();
        $this->view = new JsonView();
    }

    /** GET /api/profile/preferences — activity chips and the saved location. */
    public function preferences(): void
    {
        $user = Session::requireAuth();
        // read() returns the preferences themselves, not a result envelope, so it
        // is wrapped here. Handing the bare array to render() would find no 'ok'
        // key and answer with a null payload.
        $this->view->render($this->model->result($this->model->read((int) $user['id'])));
    }

    /** PUT /api/profile/activities — replace the chosen activities. */
    public function saveActivities(): void
    {
        $user = Session::requireAuth();
        $this->view->render($this->model->saveActivities(
            (int) $user['id'],
            Request::arr('activities')
        ));
    }

    /** PUT /api/profile/location — save or clear the preferred location. */
    public function saveLocation(): void
    {
        $user = Session::requireAuth();

        // Both or neither, exactly as AirController treats a query coordinate: half
        // a coordinate is not a place, and defaulting one half to a real location
        // would silently point the account somewhere its owner never chose.
        $lat = self::number('lat');
        $lon = self::number('lon');
        if ($lat === null || $lon === null) {
            $lat = null;
            $lon = null;
        }

        // No name is read from the request: the model resolves the city from the
        // coordinates it is about to store, so what is saved is what the geocoder
        // says the place is.
        $this->view->render($this->model->saveLocation(
            (int) $user['id'],
            $lat,
            $lon
        ));
    }

    /** POST /api/profile/password — change the password of the signed-in account. */
    public function changePassword(): void
    {
        $user = Session::requireAuth();
        $this->view->render($this->model->changePassword(
            (int) $user['id'],
            Request::str('current_password'),
            Request::str('password'),
            Request::ip()
        ));
    }

    /** DELETE /api/profile — delete the signed-in account and its data. */
    public function deleteAccount(): void
    {
        $user = Session::requireAuth();
        $result = $this->model->deleteAccount((int) $user['id'], Request::str('password'), Request::ip());

        // The row is gone either way once the model reports success, so the local
        // session must not survive it — otherwise the next request would find a
        // session pointing at an account that no longer exists.
        if ($result['ok'] === true) {
            Session::logout();
        }

        $this->view->render($result);
    }

    /** A finite number from the body, or null when absent or not numeric. */
    private static function number(string $key): ?float
    {
        $raw = Request::str($key);
        return ($raw !== '' && is_numeric($raw)) ? (float) $raw : null;
    }
}