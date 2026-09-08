<?php

declare(strict_types=1);

namespace Stree\ErrorReporter;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Stree\ErrorReporter\Transport\HttpTransport;
use Stree\ErrorReporter\Transport\TransportInterface;

class ErrorReporterServiceProvider extends ServiceProvider
{
    /** Reported as sdk.version on every event, so a bad payload is attributable to a release. */
    public const VERSION = '1.0.0';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/error-reporter.php', 'error-reporter');

        $this->app->singleton(TransportInterface::class, function ($app): TransportInterface {
            $config = $app['config']['error-reporter'];

            return new HttpTransport(
                (string) ($config['api_url'] ?? ''),
                (string) ($config['api_key'] ?? ''),
                max(1, (int) ($config['timeout'] ?? 3)),
            );
        });

        $this->app->singleton(Reporter::class, function ($app): Reporter {
            $config = $app['config']['error-reporter'];

            return new Reporter(
                $app,
                new EventBuilder(
                    new Sanitizer($config['redact_keys'] ?? [], $config['redact_query'] ?? []),
                    (string) ($config['environment'] ?? 'production'),
                    $config['release'] ?? null,
                    $app->basePath(),
                    self::VERSION,
                ),
                $app->make(TransportInterface::class),
                $config,
            );
        });

        $this->app->alias(Reporter::class, 'error-reporter');
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'error-reporter');

        $this->publishes([
            __DIR__.'/../config/error-reporter.php' => config_path('error-reporter.php'),
        ], 'error-reporter-config');

        // Renders the inline stub + async bundle. A directive rather than response
        // middleware on purpose: rewriting response bodies breaks streamed and non-HTML
        // responses, fights full-page caches, and complicates CSP nonces.
        Blade::directive('errorReporter', fn (): string => "<?php echo app('error-reporter')->browserScripts(); ?>");
    }
}
