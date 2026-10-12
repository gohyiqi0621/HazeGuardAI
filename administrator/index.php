<?php
declare(strict_types=1);

/**
 * HazeGuard AI — administrator front controller (Joomla: /administrator/index.php).
 *
 * URLs follow the Joomla shape, so a panel is reached with:
 *   administrator/index.php?option=com_hazeguard&view=usergroup&task=display
 *
 * `view` picks the page and `task` picks the action, exactly as Joomla's
 * dispatcher does. One view is the read-only overview; the others are the
 * management panels, named after the entity they edit.
 */

require dirname(__DIR__) . '/bootstrap.php';

use HazeGuard\Component\Hazeguard\Administrator\Controller\AdminController;
use HazeGuard\Component\Hazeguard\Administrator\Controller\DashboardController;
use HazeGuard\Component\Hazeguard\Administrator\Model\Entities;
use HazeGuard\Response;

$option = (string) ($_GET['option'] ?? 'com_hazeguard');
$view   = (string) ($_GET['view'] ?? 'dashboard');
$task   = (string) ($_GET['task'] ?? 'display');

if ($option !== 'com_hazeguard') {
    Response::error('not_found', 'No such administrator page.', 404);
}

// A controller renders its own page, so the request is over once it returns —
// without the exit, control would fall through and append a 404 to the HTML.
if ($view === 'dashboard') {
    (new DashboardController())->execute($task);
    exit;
}

if (Entities::has($view)) {
    (new AdminController($view))->execute($task);
    exit;
}

Response::error('not_found', 'No such administrator page.', 404);
