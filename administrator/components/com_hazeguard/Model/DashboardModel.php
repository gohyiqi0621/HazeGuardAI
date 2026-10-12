<?php
declare(strict_types=1);

namespace HazeGuard\Component\Hazeguard\Administrator\Model;

use HazeGuard\Database;
use HazeGuard\Session;

/**
 * Read-only queries for the administrator dashboard. Nothing here writes, so
 * the dashboard can never change application data.
 */
final class DashboardModel
{
    /** True when the signed-in user belongs to an usergroup with administrator access. */
    public function isAdministrator(): bool
    {
        $userId = Session::userId();
        if ($userId === null) {
            return false;
        }

        $access = Database::run(
            'SELECT g.access
               FROM users u
               JOIN usergroups g ON g.id = u.usergroup_id
              WHERE u.id = :id',
            ['id' => $userId]
        )->fetchColumn();

        return $access === 'administrator';
    }

    /** @return array<string,int> headline counts for the dashboard cards */
    public function stats(): array
    {
        return [
            'users'       => $this->count('users'),
            'pending'     => $this->count('users', "status = 'pending'"),
            'active'      => $this->count('users', "status = 'active'"),
            'suspended'   => $this->count('users', "status = 'suspended'"),
            'usergroups'  => $this->count('usergroups'),
            'credentials' => $this->count('credential_types'),
        ];
    }

    /** @return list<array<string,mixed>> the newest accounts, newest first */
    public function recentUsers(int $limit = 10): array
    {
        // $limit is an int and is clamped, so it is safe to inline (MySQL does
        // not accept a bound parameter for LIMIT under native prepares).
        $limit = max(1, min(100, $limit));

        return Database::run(
            "SELECT u.id, u.username, u.email, u.status, u.created_at, g.title AS group_title
               FROM users u
               LEFT JOIN usergroups g ON g.id = u.usergroup_id
              ORDER BY u.id DESC
              LIMIT {$limit}"
        )->fetchAll();
    }

    /**
     * Count rows in an internal table. $table and $where are literals written in
     * this file — request input never reaches this method.
     */
    private function count(string $table, string $where = ''): int
    {
        $sql = "SELECT COUNT(*) FROM `{$table}`" . ($where !== '' ? " WHERE {$where}" : '');
        return (int) Database::run($sql)->fetchColumn();
    }
}
