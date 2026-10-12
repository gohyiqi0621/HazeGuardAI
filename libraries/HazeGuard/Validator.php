<?php
declare(strict_types=1);

namespace HazeGuard;

/**
 * Input validation at the trust boundary. Each method returns an error message
 * or null when the value is acceptable. Messages are user-facing and never
 * echo back sensitive values.
 */
final class Validator
{
    public const USERNAME_RE = '/^[a-zA-Z0-9_]{3,20}$/';

    public static function required(?string $value, string $label): ?string
    {
        return ($value === null || trim($value) === '') ? "{$label} is required." : null;
    }

    public static function username(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return 'Username is required.';
        }
        if (!preg_match(self::USERNAME_RE, $value)) {
            return 'Username must be 3–20 letters, numbers or underscores.';
        }
        return null;
    }

    public static function email(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return 'Email is required.';
        }
        if (strlen($value) > 254 || !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return 'Enter a valid email address, like name@example.com.';
        }
        return null;
    }

    public static function password(?string $value): ?string
    {
        $value = (string) $value;
        if ($value === '') {
            return 'Password is required.';
        }
        if (strlen($value) < 8) {
            return 'Use at least 8 characters.';
        }
        if (strlen($value) > 100) {
            return 'Password must be 100 characters or fewer.';
        }
        return null;
    }

    public static function otpCode(?string $value): ?string
    {
        $value = trim((string) $value);
        return preg_match('/^\d{6}$/', $value) ? null : 'Enter the 6-digit code.';
    }

    /**
     * Collect the first error per field.
     *
     * @param array<string,?string> $rules map of field => error-or-null
     * @return array<string,string>
     */
    public static function collect(array $rules): array
    {
        $errors = [];
        foreach ($rules as $field => $error) {
            if ($error !== null) {
                $errors[$field] = $error;
            }
        }
        return $errors;
    }
}
