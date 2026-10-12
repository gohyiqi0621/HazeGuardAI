<?php
declare(strict_types=1);

namespace HazeGuard\Component\Hazeguard\Administrator\Controller;

use HazeGuard\Component\Hazeguard\Administrator\Model\DashboardModel;
use HazeGuard\Component\Hazeguard\Administrator\View\Dashboard\HtmlView;
use HazeGuard\Mvc\Controller\BaseController;

/**
 * The administrator dashboard controller.
 *
 * It checks access, asks the model for the data, and hands it to the view — the
 * three MVC roles in one short method.
 */
final class DashboardController extends BaseController
{
    /** administrator/index.php?option=com_hazeguard&view=dashboard&task=display */
    public function display(): void
    {
        $model = new DashboardModel();
        $view = new HtmlView(__DIR__ . '/../tmpl');

        if (!$model->isAdministrator()) {
            $view->setLayout('forbidden');
            $view->render();
            return;
        }

        $view->assign('stats', $model->stats());
        $view->assign('users', $model->recentUsers());
        $view->render();
    }
}
