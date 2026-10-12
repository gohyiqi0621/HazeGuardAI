<?php
declare(strict_types=1);

namespace HazeGuard\Component\Hazeguard\Administrator\Model;

use HazeGuard\Database;

/**
 * The user panel's model. It differs from the generic one in a single rule:
 * signing in requires users.email_verified_at, so an account an administrator
 * creates as "active" is marked verified straight away — otherwise the operator
 * would create an account nobody could log into.
 */
final class UserAdminModel extends AdminModel
{
    /** @param array<string,mixed> $values */
    protected function beforeSave(array $values, ?int $id): array
    {
        if (($values['status'] ?? '') !== 'active') {
            return $values;
        }

        $alreadyVerified = $id !== null && Database::run(
            'SELECT email_verified_at FROM users WHERE id = :id',
            ['id' => $id]
        )->fetchColumn() !== null;

        // Set the date once; editing an account must not rewrite it.
        if (!$alreadyVerified) {
            $values['email_verified_at'] = Database::now();
        }

        return $values;
    }
}
