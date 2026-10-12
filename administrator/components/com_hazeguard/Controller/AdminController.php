<?php
declare(strict_types=1);

namespace HazeGuard\Component\Hazeguard\Administrator\Controller;

use HazeGuard\Component\Hazeguard\Administrator\Model\AdminModel;
use HazeGuard\Component\Hazeguard\Administrator\Model\DashboardModel;
use HazeGuard\Component\Hazeguard\Administrator\Model\Entities;
use HazeGuard\Component\Hazeguard\Administrator\Model\UserAdminModel;
use HazeGuard\Component\Hazeguard\Administrator\View\Dashboard\HtmlView;
use HazeGuard\Csrf;
use HazeGuard\Mvc\Controller\BaseController;
use HazeGuard\Request;

/**
 * One management panel: Credentials, User or Usergroup.
 *
 * The panels do exactly the same five things, so this controller is written
 * once and told which entity it is working on. The entity itself comes from
 * Entities.php; the controller never learns a table or column name.
 *
 * Every task passes through authorise() (administrator only) and every write
 * additionally requires a valid CSRF token, because these forms change data.
 */
final class AdminController extends BaseController
{
    /** Notice codes that may appear in the URL, mapped to what the operator sees. */
    private const NOTICES = [
        'saved'   => 'Saved.',
        'deleted' => 'Deleted.',
    ];

    private string $key;

    /** @var array<string,mixed> */
    private array $spec;

    private AdminModel $model;
    private HtmlView $view;

    public function __construct(string $key)
    {
        $this->key  = $key;
        $this->spec = Entities::get($key);

        // The user panel adds one rule (see UserAdminModel); the rest are generic.
        $this->model = $key === 'user' ? new UserAdminModel($this->spec) : new AdminModel($this->spec);
        $this->view  = new HtmlView(__DIR__ . '/../tmpl');
    }

    /** The panel: an empty form to create a record, then the list. */
    public function display(): void
    {
        $this->render('create');
    }

    /** The panel with the form filled from an existing record. */
    public function edit(): void
    {
        $this->render('edit', $this->row());
    }

    /** The panel with one record shown read-only. */
    public function view(): void
    {
        $this->render('view', $this->row());
    }

    /** POST: create a record, or update the one named by `id`. */
    public function save(): void
    {
        $this->authorise();
        Csrf::requireValid(Request::str('csrf_token'));

        $id    = (int) Request::str('id');
        $input = $this->input();

        $result = $id > 0 ? $this->model->update($id, $input) : $this->model->create($input);

        if ($result['ok']) {
            $this->redirect('saved');
        }

        // Show the form again with the errors and the values that were typed.
        $this->render($id > 0 ? 'edit' : 'create', null, $result, $input);
    }

    /** POST: delete the record named by `id`. */
    public function delete(): void
    {
        $this->authorise();
        Csrf::requireValid(Request::str('csrf_token'));

        $result = $this->model->delete((int) Request::str('id'));

        if ($result['ok']) {
            $this->redirect('deleted');
        }

        // Blocked by a foreign key: stay on the panel and explain why.
        $this->render('create', null, $result);
    }

    /** @return array<string,string> field => what the operator typed */
    private function input(): array
    {
        $input = [];
        foreach ($this->spec['fields'] as $name => $field) {
            $input[$name] = Request::str($name);
        }

        return $input;
    }

    /** @return array<string,mixed> */
    private function row(): array
    {
        $this->authorise();

        $row = $this->model->find((int) Request::str('id'));
        if ($row === null) {
            // Render the panel with the error rather than a bare JSON 404.
            $this->render('create', null, [
                'ok' => false, 'code' => 'not_found',
                'message' => 'That record no longer exists.', 'fields' => [], 'id' => null,
            ]);
            exit;
        }

        return $row;
    }

    /**
     * @param array<string,mixed>|null $row
     * @param array<string,mixed>|null $result a failed save/delete, if any
     * @param array<string,string>     $input  what the operator typed
     */
    private function render(string $mode, ?array $row = null, ?array $result = null, array $input = []): void
    {
        $this->authorise();

        if ($input !== []) {
            $values = $input;                                  // redisplay the attempt
        } elseif ($row !== null) {
            $values = $this->formValues($row);
        } else {
            $values = $this->model->defaults();
        }

        $this->view->setLayout('panel');
        $this->view->assignAll([
            'entityKey' => $this->key,
            'entity'    => $this->spec,
            'mode'      => $mode,
            'id'        => (int) Request::str('id'),
            'values'    => $values,
            'row'       => $row,
            'rows'      => $this->model->all(),
            'options'   => $this->fkOptions(),
            'errors'    => $result['fields'] ?? [],
            'errorText' => ($result !== null && ($result['ok'] ?? true) === false) ? (string) $result['message'] : '',
            // Resolved through a fixed map, so nothing from the URL reaches the page.
            'notice'    => self::NOTICES[Request::str('notice')] ?? '',
            'csrfToken' => Csrf::token(),
        ]);
        $this->view->render();
    }

    /**
     * Turn a database row into the shape the form expects. A password hash is
     * never sent back to the browser: the field comes back blank instead, which
     * on save means "keep the current one".
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function formValues(array $row): array
    {
        $values = [];

        foreach ($this->spec['fields'] as $name => $field) {
            $values[$name] = ($field['hash'] ?? false)
                ? ''
                : (string) ($row[$field['column'] ?? $name] ?? '');
        }

        return $values;
    }

    /** @return array<string,array<int,string>> field => (id => label) for foreign keys */
    private function fkOptions(): array
    {
        $options = [];

        foreach ($this->spec['fields'] as $name => $field) {
            if (($field['type'] ?? '') === 'fk') {
                $options[$name] = $this->model->options($field);
            }
        }

        return $options;
    }

    /**
     * The panels are for administrators only.
     *
     * A signed-out or ordinary member gets the same page the dashboard shows
     * them.
     */
    private function authorise(): void
    {
        if ((new DashboardModel())->isAdministrator()) {
            return;
        }

        $this->view->setLayout('forbidden');
        $this->view->render();
        exit;
    }

    /** Post/Redirect/Get, so a refresh cannot repeat the write. */
    private function redirect(string $notice): never
    {
        header(
            'Location: index.php?option=com_hazeguard&view=' . urlencode($this->key)
            . '&notice=' . urlencode($notice),
            true,
            303
        );
        exit;
    }
}
