<?php
declare(strict_types=1);

namespace HazeGuard;

/**
 * Configuration store.
 *
 * Joomla keeps site configuration in `configuration.php`; this project keeps the
 * same idea in a `.env` file that is read once at boot. Missing keys fall back
 * to environment variables, then to a caller-supplied default.
 */
final class Config
{
    /** @var array<string,string> */
    private static array $values = [];
    private static bool $loaded = false;

    public static function load(string $path): void
    {
        self::$loaded = true;
        if (!is_file($path)) {
            return; // .env is optional; missing keys fall back to defaults
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $eq = strpos($line, '=');
            if ($eq === false) {
                continue;
            }
            $key = trim(substr($line, 0, $eq));
            $value = trim(substr($line, $eq + 1));
            // strip a single pair of surrounding quotes, keep everything else verbatim
            if (strlen($value) >= 2
                && (($value[0] === '"' && $value[-1] === '"') || ($value[0] === "'" && $value[-1] === "'"))) {
                $value = substr($value, 1, -1);
            }
            self::$values[$key] = $value;
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        if (array_key_exists($key, self::$values)) {
            return self::$values[$key];
        }
        $env = getenv($key);
        return $env === false ? $default : $env;
    }

    public static function require(string $key): string
    {
        $value = self::get($key);
        if ($value === null || $value === '') {
            throw new \RuntimeException("Missing required config key: {$key}");
        }
        return $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        if ($value === null) {
            return $default;
        }
        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key);
        return ($value === null || $value === '') ? $default : (int) $value;
    }

    public static function isProduction(): bool
    {
        return strtolower((string) self::get('APP_ENV', 'production')) === 'production';
    }

    /** Override a value at runtime (used by tests to target an isolated database). */
    public static function set(string $key, string $value): void
    {
        self::$values[$key] = $value;
    }

    public static function isLoaded(): bool
    {
        return self::$loaded;
    }

    /** Test helper: reset loaded state. */
    public static function reset(): void
    {
        self::$values = [];
        self::$loaded = false;
    }
}
