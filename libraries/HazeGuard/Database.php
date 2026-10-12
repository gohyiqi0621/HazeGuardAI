<?php
declare(strict_types=1);

namespace HazeGuard;

use PDO;

/**
 * The single shared PDO connection — the one place that knows how to reach
 * MySQL. Every query in the application goes through run(), so parameterised
 * binding is the only way SQL is ever executed.
 *
 * Joomla's equivalent is `Joomla\Database\DatabaseDriver` obtained from the
 * DI container; here it is a small static helper so the code stays readable.
 */
final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $host = Config::get('DB_HOST', '127.0.0.1');
        $port = Config::get('DB_PORT', '3306');
        $name = Config::get('DB_NAME', 'hazeguard');
        $user = Config::get('DB_USER', 'root');
        $pass = Config::get('DB_PASS', '') ?? '';

        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
        self::$pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ]);

        return self::$pdo;
    }

    /** Convenience: run a prepared statement and return the statement. */
    public static function run(string $sql, array $params = []): \PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /** Test helper: drop the cached connection so a new env can be picked up. */
    public static function reset(): void
    {
        self::$pdo = null;
    }

    /** Current UTC time as a MySQL DATETIME string. */
    public static function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }

    /** UTC DATETIME string `$seconds` in the future (or past, if negative). */
    public static function future(int $seconds): string
    {
        // sprintf('%+d') yields "+900" / "-900"; a literal "+-900" would be
        // mis-parsed by DateTime::modify as a positive offset.
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify(sprintf('%+d seconds', $seconds))
            ->format('Y-m-d H:i:s');
    }

    /** UTC DATETIME string `$seconds` in the past. */
    public static function past(int $seconds): string
    {
        return self::future(-abs($seconds));
    }
}
