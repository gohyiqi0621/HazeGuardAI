<?php
declare(strict_types=1);

namespace HazeGuard\Mvc\View;

/**
 * Base HTML view — the Joomla `HtmlView` idea in plain PHP.
 *
 * A view owns its template files and renders them with the data the controller
 * assigned. Templates are plain PHP under the component's tmpl/ folder:
 *
 *   <component>/tmpl/<view>/<layout>.php
 *
 * Inside a template, $this is the view, so the escape helper and the assigned
 * values are available: <?= $this->escape($this->item['username']) ?>
 */
class HtmlView
{
    protected string $templateBase;
    protected string $view;
    protected string $layout = 'default';

    /** @var array<string,mixed> */
    protected array $data = [];

    public function __construct(string $templateBase, string $view)
    {
        $this->templateBase = rtrim($templateBase, '/');
        $this->view = $view;
    }

    public function setLayout(string $layout): void
    {
        $this->layout = $layout;
    }

    public function assign(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    /** Assign a whole array of variables at once. */
    public function assignAll(array $data): void
    {
        foreach ($data as $key => $value) {
            $this->data[$key] = $value;
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /** Magic read so templates can use $this->item instead of $this->get('item'). */
    public function __get(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    public function __isset(string $key): bool
    {
        return isset($this->data[$key]);
    }

    public function escape(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public function render(): void
    {
        $file = $this->templatePath($this->layout);
        if (!is_file($file)) {
            throw new \RuntimeException("Missing template: {$file}");
        }
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
        }
        require $file;
    }

    /** Render a partial from inside a template (header, footer, table rows). */
    protected function partial(string $name, array $vars = []): void
    {
        extract($vars, EXTR_SKIP);
        require $this->templatePath($name);
    }

    protected function templatePath(string $layout): string
    {
        return $this->templateBase . '/' . $this->view . '/' . $layout . '.php';
    }
}
