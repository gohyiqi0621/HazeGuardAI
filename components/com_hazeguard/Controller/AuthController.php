<?php
declare(strict_types=1);

namespace HazeGuard\Component\Hazeguard\Site\Controller;

use HazeGuard\Component\Hazeguard\Site\Model\AuthModel;
use HazeGuard\Component\Hazeguard\Site\View\Auth\JsonView;
use HazeGuard\Csrf;
use HazeGuard\Mvc\Controller\BaseController;
use HazeGuard\Request;
use HazeGuard\Session;

/**
 * The site's authentication controller.
 *
 * One public method per API route. The route table in index.php maps
 * "METHOD /path" onto the matching task, which execute() then runs — the same
 * shape as Joomla's `index.php?option=com_x&task=auth.login`.
 *
 * The controller only reads input and hands the model's result to the view; the
 * business rules live in AuthModel, the wire format in JsonView.
 */
final class AuthController extends BaseController
{
    private AuthModel $model;
    private JsonView $view;

    public function __construct()
    {
        $this->model = new AuthModel();
        $this->view = new JsonView();
    }

    /** GET /api/auth/session — client bootstrap: CSRF token + signed-in user. */
    public function session(): void
    {
        $user = Session::user();

        // PHP stores sessions in files, so a password change cannot reach in and
        // delete them. This is where that gap is closed instead: a session records
        // when it was granted, and if the row's password_changed_at is newer than
        // that, the session predates the change and is ended here.
        //
        // One extra query per page load, not per request, because the client asks
        // this once at boot. An account that has never changed its password has
        // password_changed_at = NULL, which can never be newer than anything.
        if ($user !== null && $this->sessionOutlivedPasswordChange((int) $user['id'], Session::issuedAt())) {
            Session::logout();
            $user = null;
        }

        $this->view->render([
            'ok'   => true,
            'data' => ['csrf_token' => Csrf::token(), 'user' => $user],
        ]);
    }

    /**
     * Is this session older than the account's last password change?
     *
     * A session with no issue time predates this feature entirely; it is left
     * alone rather than destroyed, because there is nothing to compare it to.
     * Same for an account that has never changed its password — NULL can never be
     * newer than anything, and logging those people out on every page load would
     * be worse than the gap it closes.
     */
    private function sessionOutlivedPasswordChange(int $userId, ?int $issuedAt): bool
    {
        if ($issuedAt === null) {
            return false;
        }

        $changedAt = \HazeGuard\Database::run(
            'SELECT password_changed_at FROM users WHERE id = :id',
            ['id' => $userId]
        )->fetchColumn();

        return is_string($changedAt) && strtotime($changedAt) > $issuedAt;
    }

    /** GET /api/auth/me — protected route: 401 when nobody is signed in. */
    public function me(): void
    {
        $user = Session::requireAuth();
        $this->view->render(['ok' => true, 'data' => ['user' => $user]]);
    }

    /** POST /api/auth/register — create a pending account and email a code. */
    public function register(): void
    {
        $this->view->render($this->model->register(
            Request::str('username'),
            Request::str('email'),
            Request::str('password')
        ), 201);
    }

    /** POST /api/auth/verify-email — activate a pending account. */
    public function verifyEmail(): void
    {
        $this->view->render($this->model->verifyEmail(
            Request::str('email'),
            Request::str('code')
        ));
    }

    /** POST /api/auth/resend-verification — re-send the verification code. */
    public function resendVerification(): void
    {
        $this->view->render($this->model->resendVerification(
            Request::str('email'),
            Request::ip()
        ));
    }

    /** POST /api/auth/login — password login by email or username. */
    public function login(): void
    {
        $this->view->renderSignedIn($this->model->login(
            Request::str('identifier'),
            Request::str('password'),
            Request::ip()
        ));
    }

    /** POST /api/auth/login/otp/request — email a one-time sign-in code. */
    public function requestOtp(): void
    {
        $this->view->render($this->model->requestLoginOtp(
            Request::str('email'),
            Request::ip()
        ));
    }

    /** POST /api/auth/login/otp/verify — verify the sign-in code. */
    public function verifyOtp(): void
    {
        $this->view->renderSignedIn($this->model->verifyLoginOtp(
            Request::str('email'),
            Request::str('code'),
            Request::ip()
        ));
    }

    /** POST /api/auth/password/forgot — email a reset code. Always reports success. */
    public function forgotPassword(): void
    {
        $this->view->render($this->model->requestReset(
            Request::str('email'),
            Request::ip()
        ));
    }

    /** POST /api/auth/password/reset — redeem a code and set the new password. */
    public function resetPassword(): void
    {
        $this->view->render($this->model->resetPassword(
            Request::str('email'),
            Request::str('code'),
            Request::str('password'),
            Request::ip()
        ));
    }

    /** POST /api/auth/logout — destroy the session and clear the cookie. */
    public function logout(): void
    {
        Session::logout();
        $this->view->render(['ok' => true, 'data' => ['logged_out' => true]]);
    }
}
