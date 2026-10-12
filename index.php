<?php
declare(strict_types=1);

/**
 * HazeGuard AI — site front controller (Joomla: the root /index.php).
 *
 * Dev:  php -S localhost:8000 index.php
 *   /api/*        -> JSON API, dispatched to a component controller task
 *   /             -> the single-page app shell (templates/hazeguard/index.php)
 *   /media/*      -> static component assets (css, js, fonts)
 *   anything else -> 404, so .env and the source files stay private
 *
 * Production: Nginx + PHP-FPM sends /api/* here and serves /media/ statically.
 */

require __DIR__ . '/bootstrap.php';

use HazeGuard\Component\Hazeguard\Site\Controller\AirController;
use HazeGuard\Component\Hazeguard\Site\Controller\AuthController;
use HazeGuard\Component\Hazeguard\Site\Controller\PlacesController;
use HazeGuard\Component\Hazeguard\Site\Controller\ProfileController;
use HazeGuard\Csrf;
use HazeGuard\Request;
use HazeGuard\Response;

// The app can be mounted at the web root (php -S) or inside a subdirectory
// (Apache serving http://localhost/haze2/). Work out that prefix once and strip
// it, so every route below is written relative to the app itself.
$docRoot = str_replace('\\', '/', rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/\\'));
$appDir  = str_replace('\\', '/', __DIR__);
$base    = ($docRoot !== '' && str_starts_with($appDir, $docRoot))
    ? rtrim(substr($appDir, strlen($docRoot)), '/')
    : '';

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$uri = is_string($uri) ? rawurldecode($uri) : '/';

if ($base !== '' && ($uri === $base || str_starts_with($uri, $base . '/'))) {
    $uri = substr($uri, strlen($base));
}
if ($uri === '') {
    $uri = '/';
}

// Refuse any path that climbs out of the document tree. Without this, a request
// like /media/../.env is passed to the built-in server, which resolves ".." and
// happily serves .env. Decode first, so %2e%2e is caught too.
if (preg_match('#(^|/)\.\.(/|$)#', $uri) === 1) {
    Response::error('not_found', 'No such endpoint.', 404);
}

// Non-API paths: the app shell, a static asset, or nothing.
if (!str_starts_with($uri, '/api/')) {
    if ($uri === '/' || $uri === '/index.php') {
        require __DIR__ . '/templates/hazeguard/index.php';
        exit;
    }
    // Returning false lets PHP's built-in server handle the request itself:
    // static assets are streamed, and the administrator entry point is
    // executed. Every other path 404s, so source files and .env can never be
    // downloaded.
    if (PHP_SAPI === 'cli-server'
        && (str_starts_with($uri, '/media/') || $uri === '/administrator/index.php')) {
        return false;
    }
    Response::error('not_found', 'No such endpoint.', 404);
}

$method = Request::method();
$path = rtrim($uri, '/');
if ($path === '') {
    $path = '/';
}

/** @var array<string, array{0:class-string, 1:string}> route key => [controller class, task] */
$routes = [
    'GET /api/auth/session'              => [AuthController::class, 'session'],
    'GET /api/auth/me'                   => [AuthController::class, 'me'],
    'POST /api/auth/register'            => [AuthController::class, 'register'],
    'POST /api/auth/verify-email'        => [AuthController::class, 'verifyEmail'],
    'POST /api/auth/resend-verification' => [AuthController::class, 'resendVerification'],
    'POST /api/auth/login'               => [AuthController::class, 'login'],
    'POST /api/auth/login/otp/request'   => [AuthController::class, 'requestOtp'],
    'POST /api/auth/login/otp/verify'    => [AuthController::class, 'verifyOtp'],
    'POST /api/auth/password/forgot'     => [AuthController::class, 'forgotPassword'],
    'POST /api/auth/password/reset'      => [AuthController::class, 'resetPassword'],
    'POST /api/auth/logout'              => [AuthController::class, 'logout'],

    // The air-quality reading. Public and keyless, like the two providers it
    // fronts; the point of routing it through PHP is that the upstream calls,
    // the banding and the wording happen once, server-side, not once per client.
    'GET /api/air/current'               => [AirController::class, 'current'],

    // The Haze Radar's grid: the same provider, asked about nineteen points in one
    // request instead of one, so the map costs the same single upstream call the
    // instrument already made. Centred on the coordinate given, else the saved
    // location, else the default city — identical precedence to /current.
    'GET /api/air/around'                => [AirController::class, 'around'],

    // The profile screen. Every one of these resolves the account from the
    // session, never from the request body, so none of them can be aimed at
    // another user's row.
    'GET /api/profile/preferences' => [ProfileController::class, 'preferences'],
    'PUT /api/profile/activities'  => [ProfileController::class, 'saveActivities'],
    'PUT /api/profile/location'    => [ProfileController::class, 'saveLocation'],
    'POST /api/profile/password'   => [ProfileController::class, 'changePassword'],
    'DELETE /api/profile'          => [ProfileController::class, 'deleteAccount'],

    // Place lookup, used by the profile screen: a typed name becomes candidate
    // places, and a detected position gets a name. Public and keyless, like the
    // two air-quality providers already in front of them.
    'GET /api/places/search'  => [PlacesController::class, 'search'],
    'GET /api/places/reverse' => [PlacesController::class, 'reverse'],
];

$key = $method . ' ' . $path;
if (!isset($routes[$key])) {
    Response::error('not_found', 'No such endpoint.', 404);
}

// CSRF on every state-changing request. GET endpoints are read-only.
if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
    Csrf::requireValid(Request::header('X-CSRF-Token'));
}

[$controllerClass, $task] = $routes[$key];
(new $controllerClass())->execute($task);
