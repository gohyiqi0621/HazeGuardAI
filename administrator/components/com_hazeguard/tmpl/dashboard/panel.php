<?php
/**
 * One management panel: the tabs, the form, then the list of everything created.
 *
 * This single template serves every panel. Which entity it is rendering comes
 * from $this->entity (a spec from Entities.php), and $this->mode says whether
 * the form is for creating, editing or just reading.
 *
 * A `secret` field is masked in the list but shown in full in the form — an
 * operator has to be able to copy a key out, and the whole page is already
 * administrator-only.
 *
 * @var \HazeGuard\Component\Hazeguard\Administrator\View\Dashboard\HtmlView $this
 */

use HazeGuard\Component\Hazeguard\Administrator\Model\Entities;

$entity   = $this->entity;
$mode     = (string) $this->mode;          // create | edit | view
$readOnly = $mode === 'view';
$values   = $this->values;
$errors   = $this->errors;
$rows     = $this->rows;
$options  = $this->options;
$id       = (int) $this->id;

$panelUrl = 'index.php?option=com_hazeguard&amp;view=' . $this->escape($this->entityKey);
$tabs     = Entities::tabsFor($this->entityKey);

/**
 * What a list cell shows for one field, already escaped.
 *
 * Passwords are never sent to the page at all. A foreign key shows the label it
 * was joined to. A secret shows only its last four characters.
 */
$cell = function (array $row, string $name, array $field): string {
    if (($field['hash'] ?? false)) {
        return '••••••';
    }

    if (($field['type'] ?? '') === 'fk') {
        return $this->escape((string) ($row[$name . '_label'] ?? '—'));
    }

    $value = (string) ($row[$field['column'] ?? $name] ?? '');
    if ($value === '') {
        return '—';
    }
    if (($field['secret'] ?? false)) {
        return $this->escape('••••••••' . substr($value, -4));   // show only the last 4 characters
    }

    return $this->escape($value);
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $this->escape($entity['title']) ?> — HazeGuard AI Administrator</title>
<?php $this->partial('_style'); ?>
</head>
<body>
<div class="shell">

  <?php $this->partial('_nav', ['current' => $this->entityKey]); ?>

  <div class="main">

    <div class="page-head">
      <h1><?= $this->escape($entity['title']) ?></h1>
      <p class="lede"><?= $this->escape($entity['lede']) ?></p>
    </div>

    <?php if ($tabs !== []): ?>
      <nav class="tabs" aria-label="<?= $this->escape((string) $entity['group']) ?>">
        <?php foreach ($tabs as $tabKey => $tabLabel): ?>
          <a class="tab<?= $tabKey === $this->entityKey ? ' is-current' : '' ?>"
             href="index.php?option=com_hazeguard&amp;view=<?= $this->escape($tabKey) ?>&amp;task=display"
             <?= $tabKey === $this->entityKey ? 'aria-current="page"' : '' ?>><?= $this->escape($tabLabel) ?></a>
        <?php endforeach; ?>
      </nav>
    <?php endif; ?>

    <?php if ($this->notice !== ''): ?>
      <p class="alert alert--ok" role="status"><?= $this->escape($this->notice) ?></p>
    <?php endif; ?>
    <?php if ($this->errorText !== ''): ?>
      <p class="alert alert--error" role="alert"><?= $this->escape($this->errorText) ?></p>
    <?php endif; ?>

    <section class="panel">
      <h2 style="margin-top:0">
        <?php if ($readOnly): ?>
          Viewing <?= $this->escape($entity['singular']) ?> #<?= $id ?>
        <?php elseif ($mode === 'edit'): ?>
          Editing <?= $this->escape($entity['singular']) ?> #<?= $id ?>
        <?php else: ?>
          New <?= $this->escape($entity['singular']) ?>
        <?php endif; ?>
      </h2>

      <form method="post" action="<?= $panelUrl ?>&amp;task=save">
        <input type="hidden" name="csrf_token" value="<?= $this->escape($this->csrfToken) ?>">
        <input type="hidden" name="id" value="<?= $id ?>">

        <?php foreach ($entity['fields'] as $name => $field): ?>
          <?php
          $type     = (string) ($field['type'] ?? 'text');
          $label    = (string) $field['label'];
          $required = (bool) ($field['required'] ?? false);
          $value    = (string) ($values[$name] ?? '');
          $error    = (string) ($errors[$name] ?? '');
          $isSelect = $type === 'select' || $type === 'fk';

          $inputId = 'f-' . $name;
          $described = [];
          if ($error !== '') {
              $described[] = 'e-' . $name;
          } elseif (isset($field['hint'])) {
              $described[] = 'h-' . $name;
          }
          $describedBy = $described === [] ? '' : ' aria-describedby="' . implode(' ', $described) . '"';
          ?>
          <div class="field<?= $error !== '' ? ' has-error' : '' ?>">

            <label class="field__label" for="<?= $this->escape($inputId) ?>">
              <?= $this->escape($label) ?><?= $required ? ' <span aria-hidden="true">*</span>' : '' ?>
            </label>

            <?php if ($isSelect): ?>
              <?php // A `select` is a fixed value => label map; an `fk` is rows from another table. ?>
              <select id="<?= $this->escape($inputId) ?>"
                      name="<?= $this->escape($name) ?>"
                      <?= $required ? ' required' : '' ?>
                      <?= $readOnly ? ' disabled' : '' ?><?= $describedBy ?>>
                <option value=""><?= $required ? 'Choose…' : '— none —' ?></option>
                <?php if ($type === 'fk'): ?>
                  <?php foreach (($options[$name] ?? []) as $choice): ?>
                    <option value="<?= (int) $choice['id'] ?>"
                            <?= (string) $choice['id'] === $value ? ' selected' : '' ?>><?= $this->escape($choice['label']) ?></option>
                  <?php endforeach; ?>
                <?php else: ?>
                  <?php foreach (($field['options'] ?? []) as $optionValue => $optionLabel): ?>
                    <option value="<?= $this->escape($optionValue) ?>"
                            <?= (string) $optionValue === $value ? ' selected' : '' ?>><?= $this->escape($optionLabel) ?></option>
                  <?php endforeach; ?>
                <?php endif; ?>
              </select>
            <?php else: ?>
              <?php $htmlType = $type === 'password' ? 'password' : ($type === 'email' ? 'email' : 'text'); ?>
              <input id="<?= $this->escape($inputId) ?>"
                     type="<?= $htmlType ?>"
                     name="<?= $this->escape($name) ?>"
                     value="<?= $this->escape($value) ?>"
                     maxlength="<?= (int) ($field['max'] ?? 255) ?>"
                     <?= $required ? ' required' : '' ?>
                     <?= $readOnly ? ' disabled' : '' ?><?= $describedBy ?>
                     <?php if ($type === 'password'): ?>
                     autocomplete="new-password"
                     placeholder="<?= $mode === 'edit' ? 'Leave blank to keep the current one' : '' ?>"
                     <?php endif; ?>>
            <?php endif; ?>

            <?php if ($error !== ''): ?>
              <div class="err" id="e-<?= $this->escape($name) ?>"><?= $this->escape($error) ?></div>
            <?php elseif (isset($field['hint'])): ?>
              <div class="hint" id="h-<?= $this->escape($name) ?>"><?= $this->escape($field['hint']) ?></div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>

        <div class="actions">
          <?php if ($readOnly): ?>
            <a class="btn btn--primary" href="<?= $panelUrl ?>&amp;task=edit&amp;id=<?= $id ?>">Edit</a>
            <a class="btn" href="<?= $panelUrl ?>&amp;task=display">Back to the list</a>
          <?php else: ?>
            <button class="btn btn--primary" type="submit">
              <?= $mode === 'edit' ? 'Save changes' : 'Create' ?>
            </button>
            <?php if ($mode === 'edit'): ?>
              <a class="btn" href="<?= $panelUrl ?>&amp;task=display">Cancel</a>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </form>
    </section>

    <h2>All <?= $this->escape($entity['title']) ?> records (<?= count($rows) ?>)</h2>

    <?php if ($rows === []): ?>
      <div class="panel empty">Nothing here yet. Use the form above to create the first one.</div>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>ID</th>
              <?php foreach ($entity['fields'] as $field): ?>
                <th><?= $this->escape($field['label']) ?></th>
              <?php endforeach; ?>
              <th class="col-actions">Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($rows as $row): ?>
            <?php $rowId = (int) $row['id']; ?>
            <tr>
              <td class="num"><?= $rowId ?></td>
              <?php foreach ($entity['fields'] as $name => $field): ?>
                <td><?= $cell($row, $name, $field) ?></td>
              <?php endforeach; ?>
              <td class="col-actions">
                <a class="link" href="<?= $panelUrl ?>&amp;task=view&amp;id=<?= $rowId ?>">View</a>
                <a class="link" href="<?= $panelUrl ?>&amp;task=edit&amp;id=<?= $rowId ?>">Edit</a>
                <form method="post" action="<?= $panelUrl ?>&amp;task=delete"
                      onsubmit="return confirm('Delete this record? This cannot be undone.')">
                  <input type="hidden" name="csrf_token" value="<?= $this->escape($this->csrfToken) ?>">
                  <input type="hidden" name="id" value="<?= $rowId ?>">
                  <button class="link link--danger" type="submit">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <footer class="note">
      Every foreign key here is a real database constraint, not just a form check:
      a user cannot exist without an usergroup. An API key is encrypted with
      AES-256-GCM before it is written, so the column holds ciphertext.
    </footer>

  </div>
</div>
</body>
</html>
