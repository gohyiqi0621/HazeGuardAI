<?php
declare(strict_types=1);

namespace HazeGuard\Mvc\Controller;

use HazeGuard\Response;

/**
 * Base controller — the Joomla `BaseController` idea in plain PHP.
 *
 * A controller holds one public method per "task". The front controller looks a
 * task up in its route table and calls execute(); Joomla does the same thing
 * through `index.php?option=com_x&task=controller.method`.
 */
abstract class BaseController
{
    /**
     * Run a task method on this controller.
     *
     * The name is validated before it is called: only plain lowercase
     * identifiers are accepted, so request input can never reach a magic method
     * such as __construct, and execute() itself cannot recurse.
     */
    public function execute(string $task): void
    {
        if ($task === 'execute'
            || !preg_match('/^[a-z][a-zA-Z0-9]*$/', $task)
            || !method_exists($this, $task)
            || !(new \ReflectionMethod($this, $task))->isPublic()
        ) {
            Response::error('not_found', 'No such task.', 404);
        }

        $this->{$task}();
    }
}
