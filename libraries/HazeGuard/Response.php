<?php
declare(strict_types=1);

namespace HazeGuard;

/**
 * JSON response envelope, as documented in progress.md §8:
 *   { "ok": bool, "data": mixed|null, "error": {code, message, fields?}|null }
 * Always terminates the request.
 *
 * Joomla's equivalent is `Joomla\CMS\Response\JsonResponse`; the view layer
 * (HazeGuard\Mvc\View\JsonView) decides which of these two to call.
 */
final class Response
{
    public static function ok(mixed $data = null, int $status = 200): never
    {
        self::send(['ok' => true, 'data' => $data, 'error' => null], $status);
    }

    /**
     * @param array<string,string>|null $fields per-field validation messages
     */
    public static function error(string $code, string $message, int $status = 400, ?array $fields = null): never
    {
        $error = ['code' => $code, 'message' => $message];
        if ($fields !== null && $fields !== []) {
            $error['fields'] = $fields;
        }
        self::send(['ok' => false, 'data' => null, 'error' => $error], $status);
    }

    private static function send(array $payload, int $status): never
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store');
        }
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}
