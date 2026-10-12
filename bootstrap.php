<?php
declare(strict_types=1);

/**
 * HazeGuard AI — application bootstrap.
 *
 * The plain-PHP equivalent of Joomla's boot sequence:
 *   1. register the class autoloader   (Joomla: libraries/autoload.php)
 *   2. load configuration              (Joomla: configuration.php)
 *   3. set the timezone and error handling
 *
 * Required once by each front controller:
 *   index.php                 — the site (JSON API + single-page app)
 *   administrator/index.php   — the administrator dashboard
 */

$root = __DIR__;

// ---- 1. autoloader -------------------------------------------------------
// Namespace prefix -> directory, longest prefix first. The same idea as the
// `<namespace>` element in a Joomla component manifest, resolved by hand.
$prefixes = [
    'HazeGuard\\Component\\Hazeguard\\Site\\'          => $root . '/components/com_hazeguard/',
    'HazeGuard\\Component\\Hazeguard\\Administrator\\' => $root . '/administrator/components/com_hazeguard/',
    'HazeGuard\\'                                       => $root . '/libraries/HazeGuard/',
];

spl_autoload_register(static function (string $class) use ($prefixes): void {
    foreach ($prefixes as $prefix => $directory) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }
        $file = $directory . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
        return; // the first (longest) matching prefix wins
    }
});

// ---- 2. configuration ----------------------------------------------------
HazeGuard\Config::load($root . '/.env');

date_default_timezone_set('UTC'); // store and compare in UTC; format for display later

// ---- 3. error handling ---------------------------------------------------
// In development, surface problems; in production, log and stay quiet to the client.
$debug = HazeGuard\Config::bool('APP_DEBUG', false);
ini_set('display_errors', '0'); // never leak PHP notices into the response body
error_reporting(E_ALL);

set_exception_handler(static function (Throwable $e) use ($debug): void {
    error_log('[hazeguard] ' . $e::class . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    HazeGuard\Response::error(
        'server_error',
        $debug ? $e->getMessage() : 'Something went wrong on our side. Please try again.',
        500
    );
});
