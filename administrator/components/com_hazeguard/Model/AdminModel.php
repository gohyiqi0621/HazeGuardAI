<?php
declare(strict_types=1);

namespace HazeGuard\Component\Hazeguard\Administrator\Model;

use HazeGuard\Crypto;
use HazeGuard\Database;
use HazeGuard\Validator;
use PDOException;

/**
 * Create, list, view, edit and delete for any entity described by Entities.php.
 *
 * Every statement is built from the spec, never from request input: the table,
 * the columns and the referenced tables are literals written in this codebase.
 * Values always travel as bound parameters.
 *
 * Foreign keys are enforced twice over, on purpose:
 *   - here, so the operator gets a sentence ("Choose an existing Usergroup")
 *     instead of a raw SQL error;
 *   - in the database, where the FK constraints are the real guarantee.
 *
 * Two field flags change a value on the way in or out:
 *   - `hash`      — passwords, one way. The plaintext is never read back.
 *   - `encrypted` — API keys, reversible. Ciphertext in the column, plaintext
 *                   for the form and for whatever calls the provider.
 */
class AdminModel
{
    /** MySQL: cannot add or update a child row — the referenced row is missing. */
    private const FK_CHILD_ROW = 1452;

    /** MySQL: cannot delete or update a parent row — children still point at it. */
    private const FK_PARENT_ROW = 1451;

    /** @var array<string,mixed> */
    private array $spec;

    /** @param array<string,mixed> $spec one entry from Entities::all() */
    public function __construct(array $spec)
    {
        $this->spec = $spec;
    }

    /**
     * Every row, newest first, with each foreign key resolved to its label so
     * the list can show "Super User" rather than "2".
     *
     * @return list<array<string,mixed>>
     */
    public function all(): array
    {
        $select = ['t.*'];
        $joins  = '';

        foreach ($this->spec['fields'] as $name => $field) {
            if (($field['type'] ?? '') !== 'fk') {
                continue;
            }

            $ref   = $field['ref'];
            $alias = 'r_' . $name;
            $value = 't.`' . ($field['column'] ?? $name) . '`';

            $select[] = "{$alias}.`{$ref['label']}` AS `{$name}_label`";
            $joins   .= " LEFT JOIN `{$ref['table']}` {$alias} ON {$alias}.id = {$value}";
        }

        $sql = 'SELECT ' . implode(', ', $select)
             . " FROM `{$this->spec['table']}` t"
             . $joins
             . ' ORDER BY t.id DESC';

        return array_map([$this, 'decryptRow'], Database::run($sql)->fetchAll());
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $row = Database::run(
            "SELECT * FROM `{$this->spec['table']}` WHERE id = :id",
            ['id' => $id]
        )->fetch();

        return $row === false ? null : $this->decryptRow($row);
    }

    /**
     * Options for a foreign-key dropdown.
     *
     * @param array<string,mixed> $field
     * @return list<array{id:int,label:string}>
     */
    public function options(array $field): array
    {
        $ref = $field['ref'];

        $rows = Database::run(
            "SELECT l.id AS id, l.`{$ref['label']}` AS label
               FROM `{$ref['table']}` l
              ORDER BY " . ($ref['order'] ?? 'label ASC')
        )->fetchAll();

        return array_map(
            static fn (array $row): array => [
                'id'    => (int) $row['id'],
                'label' => (string) $row['label'],
            ],
            $rows
        );
    }

    /** @return array<string,mixed> a blank form, with each field's default */
    public function defaults(): array
    {
        $values = [];
        foreach ($this->spec['fields'] as $name => $field) {
            $values[$name] = (string) ($field['default'] ?? '');
        }

        return $values;
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,string> field => message; empty means valid
     */
    public function validate(array $input, ?int $id = null): array
    {
        $errors = [];

        foreach ($this->spec['fields'] as $name => $field) {
            $label = (string) $field['label'];
            $value = trim((string) ($input[$name] ?? ''));

            // A blank password on edit means "keep the current one".
            if ($value === '' && $id !== null && ($field['hash'] ?? false)) {
                continue;
            }
            if (($field['required'] ?? false) && $value === '') {
                $errors[$name] = "{$label} is required.";
                continue;
            }
            if ($value === '') {
                continue;
            }
            if (isset($field['max']) && mb_strlen($value) > (int) $field['max']) {
                $errors[$name] = "{$label} must be {$field['max']} characters or fewer.";
                continue;
            }
            if (($message = $this->ruleError($field, $value)) !== null) {
                $errors[$name] = $message;
                continue;
            }
            if (($message = $this->referenceError($field, $value)) !== null) {
                $errors[$name] = $message;
                continue;
            }
            if (($message = $this->uniqueError($field, $name, $value, $id)) !== null) {
                $errors[$name] = $message;
            }
        }

        return $errors;
    }

    /**
     * @param array<string,mixed> $input
     * @return array{ok:bool,code:string,message:string,fields:array<string,string>,id:int|null}
     */
    public function create(array $input): array
    {
        $errors = $this->validate($input, null);
        if ($errors !== []) {
            return $this->fail('validation_error', 'Please fix the highlighted fields.', $errors);
        }

        $values = $this->beforeSave($this->valuesFor($input), null);
        $values['created_at'] = Database::now();
        $values['updated_at'] = Database::now();

        $columns = array_keys($values);
        $sql = 'INSERT INTO `' . $this->spec['table'] . '` (`' . implode('`, `', $columns) . '`)'
             . ' VALUES (:' . implode(', :', $columns) . ')';

        try {
            Database::run($sql, $values);
            $id = (int) Database::pdo()->lastInsertId();
        } catch (PDOException $e) {
            return $this->foreignKeyFailure($e);
        }

        return $this->pass($id);
    }

    /**
     * @param array<string,mixed> $input
     * @return array{ok:bool,code:string,message:string,fields:array<string,string>,id:int|null}
     */
    public function update(int $id, array $input): array
    {
        $errors = $this->validate($input, $id);
        if ($errors !== []) {
            return $this->fail('validation_error', 'Please fix the highlighted fields.', $errors);
        }

        $values = $this->beforeSave($this->valuesFor($input), $id);
        $values['updated_at'] = Database::now();

        $assignments = [];
        foreach (array_keys($values) as $column) {
            $assignments[] = "`{$column}` = :{$column}";
        }

        $values['id'] = $id;
        $sql = 'UPDATE `' . $this->spec['table'] . '` SET ' . implode(', ', $assignments) . ' WHERE id = :id';

        try {
            Database::run($sql, $values);
        } catch (PDOException $e) {
            return $this->foreignKeyFailure($e);
        }

        return $this->pass($id);
    }

    /**
     * @return array{ok:bool,code:string,message:string,fields:array<string,string>,id:int|null}
     */
    public function delete(int $id): array
    {
        $blockers = $this->blockers($id);
        if ($blockers !== []) {
            return $this->fail('in_use', $this->blockerMessage($blockers));
        }

        try {
            $stmt = Database::run(
                "DELETE FROM `{$this->spec['table']}` WHERE id = :id",
                ['id' => $id]
            );
        } catch (PDOException $e) {
            return $this->foreignKeyFailure($e);
        }

        if ($stmt->rowCount() === 0) {
            return $this->fail('not_found', 'That record no longer exists.');
        }

        return $this->pass($id);
    }

    /**
     * Child rows that would stop this row being deleted.
     *
     * The foreign keys are ON DELETE RESTRICT, so the database would refuse the
     * delete anyway; counting first turns a raw SQL error into a sentence the
     * operator can act on.
     *
     * @return list<array{label:string,count:int}>
     */
    public function blockers(int $id): array
    {
        $blockers = [];

        foreach ($this->spec['referenced'] as $ref) {
            $count = (int) Database::run(
                "SELECT COUNT(*) FROM `{$ref['table']}` WHERE `{$ref['column']}` = :id",
                ['id' => $id]
            )->fetchColumn();

            if ($count > 0) {
                $blockers[] = ['label' => (string) $ref['label'], 'count' => $count];
            }
        }

        return $blockers;
    }

    /**
     * Hook for an entity that has to adjust the row before it is written.
     * See UserAdminModel, which marks an active account as email-verified.
     *
     * @param array<string,mixed> $values column => value
     * @return array<string,mixed>
     */
    protected function beforeSave(array $values, ?int $id): array
    {
        return $values;
    }

    /** @return array<string,mixed> column => bound value */
    private function valuesFor(array $input): array
    {
        $values = [];

        foreach ($this->spec['fields'] as $name => $field) {
            $column = $field['column'] ?? $name;
            $value  = trim((string) ($input[$name] ?? ''));

            if (($field['hash'] ?? false)) {
                // Blank on edit: leave the column out so the old hash survives.
                if ($value !== '') {
                    $values[$column] = password_hash($value, PASSWORD_DEFAULT);
                }
                continue;
            }

            if (($field['type'] ?? '') === 'fk') {
                $values[$column] = (int) $value;
                continue;
            }

            if ($value !== '' && ($field['encrypted'] ?? false)) {
                $values[$column] = Crypto::encrypt($value);
                continue;
            }

            // Optional text columns store NULL rather than an empty string.
            $values[$column] = $value === '' ? null : $value;
        }

        return $values;
    }

    /**
     * Swap every encrypted column back to plaintext.
     *
     * The list masks the tail of the real key and the form shows the whole
     * thing, so both need the plaintext rather than the ciphertext that is
     * actually stored.
     *
     * A value written under a different CREDENTIAL_KEY cannot be read. That is
     * logged and the field comes back empty rather than taking the whole panel
     * down; pasting the key again re-encrypts it with the current key.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function decryptRow(array $row): array
    {
        foreach ($this->spec['fields'] as $name => $field) {
            if (!($field['encrypted'] ?? false)) {
                continue;
            }

            $column = $field['column'] ?? $name;
            if (!isset($row[$column]) || (string) $row[$column] === '') {
                continue;
            }

            try {
                $row[$column] = Crypto::decrypt((string) $row[$column]);
            } catch (\RuntimeException $e) {
                error_log(
                    "HazeGuard: could not decrypt {$this->spec['table']}.{$column}"
                    . " (id {$row['id']}): {$e->getMessage()}"
                );
                $row[$column] = '';
            }
        }

        return $row;
    }

    /** @param array<string,mixed> $field */
    private function ruleError(array $field, string $value): ?string
    {
        return match ($field['validate'] ?? null) {
            'username' => Validator::username($value),
            'email'    => Validator::email($value),
            'password' => Validator::password($value),
            'slug'     => preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value) === 1
                ? null
                : 'Use lowercase letters, numbers and single hyphens, like super-user.',
            default    => null,
        };
    }

    /** A select must be one of its own options; a foreign key must exist. */
    private function referenceError(array $field, string $value): ?string
    {
        $label = (string) $field['label'];

        if (($field['type'] ?? '') === 'select') {
            return isset($field['options'][$value]) ? null : "Choose one of the listed {$label} options.";
        }

        if (($field['type'] ?? '') !== 'fk') {
            return null;
        }

        $found = Database::run(
            "SELECT 1 FROM `{$field['ref']['table']}` WHERE id = :id",
            ['id' => (int) $value]
        )->fetchColumn();

        return $found === false ? "Choose an existing {$label}." : null;
    }

    /** The database has a UNIQUE key on this column, so check it before INSERT. */
    private function uniqueError(array $field, string $name, string $value, ?int $id): ?string
    {
        if (!($field['unique'] ?? false)) {
            return null;
        }

        $column = $field['column'] ?? $name;
        $sql    = "SELECT 1 FROM `{$this->spec['table']}` WHERE `{$column}` = :value";
        $params = ['value' => $value];

        if ($id !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $id;
        }

        return Database::run($sql, $params)->fetchColumn() === false
            ? null
            : "That {$field['label']} is already in use.";
    }

    /** @param list<array{label:string,count:int}> $blockers */
    private function blockerMessage(array $blockers): string
    {
        $parts = [];
        foreach ($blockers as $blocker) {
            $parts[] = $blocker['count'] . ' ' . $blocker['label'] . ($blocker['count'] === 1 ? '' : 's');
        }

        return 'Still in use by ' . implode(' and ', $parts) . '. Remove or reassign those first.';
    }

    /**
     * @return array{ok:bool,code:string,message:string,fields:array<string,string>,id:int|null}
     */
    private function foreignKeyFailure(PDOException $e): array
    {
        return match ((int) ($e->errorInfo[1] ?? 0)) {
            self::FK_CHILD_ROW  => $this->fail(
                'invalid_reference',
                'That record points at something that no longer exists. Reload the page and try again.'
            ),
            self::FK_PARENT_ROW => $this->fail(
                'in_use',
                'Other records still refer to this one, so it cannot be deleted yet.'
            ),
            default => throw $e,
        };
    }

    /**
     * @return array{ok:bool,code:string,message:string,fields:array<string,string>,id:int|null}
     */
    private function pass(int $id): array
    {
        return ['ok' => true, 'code' => 'ok', 'message' => 'Saved.', 'fields' => [], 'id' => $id];
    }

    /**
     * @param array<string,string> $fields
     * @return array{ok:bool,code:string,message:string,fields:array<string,string>,id:int|null}
     */
    private function fail(string $code, string $message, array $fields = []): array
    {
        return ['ok' => false, 'code' => $code, 'message' => $message, 'fields' => $fields, 'id' => null];
    }
}
