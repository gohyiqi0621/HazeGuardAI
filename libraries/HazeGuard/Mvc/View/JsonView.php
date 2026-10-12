<?php
declare(strict_types=1);

namespace HazeGuard\Mvc\View;

use HazeGuard\Response;

/**
 * Base JSON view — the Joomla `JsonView` idea in plain PHP.
 *
 * A model returns a plain result array; the view is the single place that knows
 * how that result is presented on the wire. Keeping it here means every API
 * response has exactly one shape, documented in progress.md §8:
 *
 *   { "ok": bool, "data": mixed|null, "error": {code, message, fields?}|null }
 */
class JsonView
{
    /**
     * @param array{ok?:bool,code?:string,message?:string,fields?:array<string,string>,data?:mixed,status?:int} $result
     * @param int $okStatus  HTTP status for a successful result (register uses 201)
     * @param int $errStatus HTTP status when the result carries no explicit one
     */
    public function render(array $result, int $okStatus = 200, int $errStatus = 400): never
    {
        if (($result['ok'] ?? false) === true) {
            Response::ok($result['data'] ?? null, $okStatus);
        }

        Response::error(
            (string) ($result['code'] ?? 'error'),
            (string) ($result['message'] ?? 'Something went wrong. Please try again.'),
            (int) ($result['status'] ?? $errStatus),
            ($result['fields'] ?? []) ?: null
        );
    }
}
