<?php

declare(strict_types=1);

namespace Pionne\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Pionne\Pionne;
use Throwable;

/**
 * Auto-discovered by Laravel via composer.json `extra.laravel.providers`.
 *
 * Reads config from env (`PIONNE_TOKEN` etc.), boots the SDK, and registers
 * a reportable hook so any exception that reaches the global handler is
 * forwarded to Pionne.
 *
 * To opt out, set `PIONNE_AUTO_INSTALL=false` in `.env`.
 */
class PionneServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // No-op — config is environment-driven.
    }

    public function boot(Application $app): void
    {
        $token = env('PIONNE_TOKEN');
        $autoInstall = filter_var(env('PIONNE_AUTO_INSTALL', true), FILTER_VALIDATE_BOOLEAN);
        if (! $autoInstall || ! is_string($token) || $token === '') {
            return;
        }

        Pionne::init([
            'token' => $token,
            'release' => env('PIONNE_RELEASE') ?: env('APP_VERSION'),
            'environment' => $app->environment(),
            // Laravel already has its own exception handler, so we don't need
            // set_exception_handler — we plug into the framework reportable
            // hook below instead.
            'captureUncaughtExceptions' => false,
            'captureFatals' => true,
        ]);

        $handler = $app->make(\Illuminate\Contracts\Debug\ExceptionHandler::class);
        if (method_exists($handler, 'reportable')) {
            $handler->reportable(static function (Throwable $e): void {
                Pionne::captureException($e);
            });
        }
    }
}
