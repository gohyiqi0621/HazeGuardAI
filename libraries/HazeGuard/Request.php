<?php
declare(strict_types=1);

namespace HazeGuard;

/**
 * Thin request accessor: JSON body, form fallback, headers, client IP.
 * Keeps controllers free of superglobal spelunking.
 *
 * Joomla's equivalent is `Joomla\CMS\Input\Input` / `$app->getInput()`.
 */
final class Request
{
    /** @var array<string,mixed>|null */
    private static ?array $json = null;

    /** @return array<string,mixed> */
    public static function json(): array
    {
        if (self::$json === null) {
            $raw = file_get_contents('php://input');
            $decoded = json_decode($raw === false ? '' : $raw, true);
            self::$json = is_array($decoded) ? $decoded : [];
        }
        return self::$json;
    }

    /**
     * Read a string field: JSON body first, then form data, then the query
     * string. Later sources lose to earlier ones, so a posted field always
     * beats a value smuggled into the URL.
     */
    public static function str(string $key, string $default = ''): string
    {
        $value = self::json()[$key] ?? $_POST[$key] ?? $_GET[$key] ?? $default;
        return is_scalar($value) ? trim((string) $value) : $default;
    }

    /**
     * Read a list field (a checkbox group): JSON array first, then form data,
     * then the query string. Blanks and non-scalars are dropped.
     *
     * @return list<string>
     */
    public static function arr(string $key): array
    {
        $value = self::json()[$key] ?? $_POST[$key] ?? $_GET[$key] ?? [];
        if (!is_array($value)) {
            return [];
        }

        $items = array_map(
            static fn (mixed $item): string => is_scalar($item) ? trim((string) $item) : '',
            $value
        );

        return array_values(array_filter($items, static fn (string $item): bool => $item !== ''));
    }

    public static function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $value = $_SERVER[$key] ?? null;
        return is_string($value) ? $value : null;
    }

    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public static function ip(): string
    {
        return RateLimiter::clientIp();
    }

    /** Test helper: clear the cached body so a new payload can be read. */
    public static function reset(): void
    {
        self::$json = null;
    }
}
