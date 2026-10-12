<?php
declare(strict_types=1);

namespace HazeGuard\Component\Hazeguard\Administrator\View\Dashboard;

use HazeGuard\Mvc\View\HtmlView as BaseHtmlView;

/**
 * The dashboard view. It pins down which component the templates belong to; the
 * controller picks the layout (default, or forbidden when access is refused).
 */
final class HtmlView extends BaseHtmlView
{
    public function __construct(string $templateBase)
    {
        parent::__construct($templateBase, 'dashboard');
    }
}
