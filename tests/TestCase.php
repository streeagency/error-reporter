<?php

declare(strict_types=1);

namespace Stree\ErrorReporter\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Stree\ErrorReporter\ErrorReporterServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [ErrorReporterServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('error-reporter.enabled', true);
        $app['config']->set('error-reporter.api_url', 'https://errors.stree.agency');
        $app['config']->set('error-reporter.api_key', 'sk_test_key_1234567890');
        $app['config']->set('error-reporter.environment', 'testing');
        $app['config']->set('error-reporter.release', '2.4.1');
    }
}
