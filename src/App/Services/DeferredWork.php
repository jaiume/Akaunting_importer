<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Runs work after the HTTP response has been handed back.
 * The web server stops waiting once that response is finished.
 */
final class DeferredWork
{
    /** @var list<callable():void> */
    private static array $tasks = [];

    public static function afterResponse(callable $task): void
    {
        self::$tasks[] = $task;
    }

    public static function run(): void
    {
        if (self::$tasks === []) {
            return;
        }

        ignore_user_abort(true);
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        set_time_limit(300);

        foreach (self::$tasks as $task) {
            try {
                $task();
            } catch (\Throwable $e) {
                error_log('Deferred work failed: ' . $e->getMessage());
            }
        }
    }
}
