<?php
declare(strict_types=1);

namespace HazeGuard\Component\Hazeguard\Site\View\Auth;

use HazeGuard\Csrf;
use HazeGuard\Mvc\View\JsonView as BaseJsonView;

/**
 * The view for the auth API.
 *
 * It extends the base JSON view with the one piece of presentation the sign-in
 * endpoints share: signing in regenerates the session id and rotates the CSRF
 * token, so the client must receive the fresh token in the same response to
 * keep making state-changing requests.
 */
final class JsonView extends BaseJsonView
{
    /** @param array{ok?:bool,data?:mixed,code?:string,message?:string,status?:int} $result */
    public function renderSignedIn(array $result): never
    {
        if (($result['ok'] ?? false) === true && is_array($result['data'] ?? null)) {
            $result['data']['csrf_token'] = Csrf::token();
        }
        $this->render($result, 200, 401);
    }
}
