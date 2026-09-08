<?php

declare(strict_types=1);

namespace Stree\ErrorReporter\Facades;

use Illuminate\Support\Facades\Facade;
use Stree\ErrorReporter\Reporter;

/**
 * @method static string|null captureException(\Throwable $e)
 * @method static string|null captureMessage(string $message, string $severity = 'info')
 * @method static void setUser(?array $user)
 * @method static void setTag(string $key, string $value)
 * @method static void setContext(array $context)
 * @method static string browserScripts()
 * @method static void flush()
 *
 * @see Reporter
 */
final class ErrorReporter extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Reporter::class;
    }
}
