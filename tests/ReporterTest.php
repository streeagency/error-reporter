<?php

declare(strict_types=1);

namespace Stree\ErrorReporter\Tests;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Stree\ErrorReporter\Facades\ErrorReporter;
use Stree\ErrorReporter\Jobs\SendEvent;
use Stree\ErrorReporter\Sanitizer;

final class ReporterTest extends TestCase
{
    /**
     * The @errorReporter directive renders the config the bundle initialises itself from,
     * and both capture flags default to FALSE inside the SDK. Rendering the config without
     * them produced a page that contained the stub, the config and the bundle — and still
     * captured nothing. The public key must be there and the secret must not.
     */
    #[Test]
    public function the_browser_snippet_turns_capture_on_and_never_carries_the_secret(): void
    {
        config()->set('error-reporter.browser.enabled', true);
        config()->set('error-reporter.browser.public_key', 'pk_browser_key');
        config()->set('error-reporter.browser.sdk_url', 'https://errors.stree.agency/sdk/1.0.0/sdk.js');

        $html = app('error-reporter')->browserScripts();

        preg_match('/window\\.__erConfig = (\\{.*?\\});/s', $html, $m);
        $config = json_decode(html_entity_decode($m[1] ?? '', ENT_QUOTES), true);

        $this->assertIsArray($config, 'the config must be valid JSON the bundle can read');
        $this->assertTrue($config['captureExceptions'] ?? false);
        $this->assertTrue($config['captureRejections'] ?? false);
        $this->assertSame('pk_browser_key', $config['publicKey'] ?? null);

        $this->assertStringNotContainsString('sk_test_key_1234567890', $html);
        $this->assertStringContainsString('__erq', $html, 'the stub is inlined ahead of the bundle');
        $this->assertStringContainsString('sdk/1.0.0/sdk.js', $html);
    }

    /**
     * A browser-side fix has to reach installed sites. Each one pins its own immutable
     * bundle URL, so without discovery a fix only lands where somebody remembered to edit
     * the config — which, across client sites, is nowhere.
     */
    #[Test]
    public function the_bundle_url_is_discovered_from_the_platform_when_none_is_configured(): void
    {
        Http::fake(['*/api/v1/sdk' => Http::response([
            'version' => '1.0.2',
            'url' => 'https://errors.stree.agency/sdk/1.0.2/sdk.js',
        ])]);

        config()->set('error-reporter.browser.enabled', true);
        config()->set('error-reporter.browser.public_key', 'pk_browser_key');
        config()->set('error-reporter.browser.sdk_url', null);

        $html = app('error-reporter')->browserScripts();

        $this->assertStringContainsString('sdk/1.0.2/sdk.js', $html);

        // Still an immutable versioned URL. Discovery is dynamic; the URL never is.
        $this->assertStringNotContainsString('latest.js', $html);

        Http::assertSent(fn ($request): bool => $request->hasHeader('X-Er-Key', 'sk_test_key_1234567890'));
    }

    #[Test]
    public function a_configured_bundle_url_is_never_overridden(): void
    {
        Http::fake(['*' => Http::response(['url' => 'https://errors.stree.agency/sdk/9.9.9/sdk.js'])]);

        config()->set('error-reporter.browser.enabled', true);
        config()->set('error-reporter.browser.public_key', 'pk_browser_key');
        config()->set('error-reporter.browser.sdk_url', 'https://errors.stree.agency/sdk/1.0.0/sdk.js');

        // A site pinned to a version stays pinned.
        $this->assertStringContainsString('sdk/1.0.0/sdk.js', app('error-reporter')->browserScripts());
    }

    #[Test]
    public function an_unreachable_platform_never_breaks_the_page(): void
    {
        Http::fake(fn () => throw new ConnectionException('platform down'));

        config()->set('error-reporter.browser.enabled', true);
        config()->set('error-reporter.browser.public_key', 'pk_browser_key');
        config()->set('error-reporter.browser.sdk_url', null);

        // Invariant 1: the stub and config still render, just without the bundle tag.
        $html = app('error-reporter')->browserScripts();

        $this->assertStringContainsString('__erConfig', $html);
        $this->assertStringNotContainsString('<script src', $html);
    }

    #[Test]
    public function the_secret_key_travels_in_the_header_never_the_body(): void
    {
        Http::fake(['*' => Http::response(status: 202)]);

        ErrorReporter::captureException(new \RuntimeException('boom'));
        ErrorReporter::flush();

        Http::assertSent(function ($request): bool {
            $this->assertSame('sk_test_key_1234567890', $request->header('X-Er-Key')[0] ?? null);
            $this->assertStringNotContainsString('sk_test_key', (string) $request->body());
            $this->assertStringEndsWith('/api/v1/events', parse_url($request->url(), PHP_URL_PATH));

            return true;
        });
    }

    #[Test]
    public function credentials_are_redacted_before_the_payload_leaves_the_host(): void
    {
        Http::fake(['*' => Http::response(status: 202)]);

        // Invariant 6: this must happen client-side. Server-side sanitization exists to
        // catch OLD SDKs, not to excuse new ones.
        ErrorReporter::setContext([
            'api_key' => 'sk_live_supersecret',
            'card_number' => '4242424242424242',
            'order_id' => '482094',
        ]);
        ErrorReporter::captureException(new \RuntimeException('boom'));
        ErrorReporter::flush();

        Http::assertSent(function ($request): bool {
            $body = (string) $request->body();
            $this->assertStringNotContainsString('sk_live_supersecret', $body);
            $this->assertStringNotContainsString('4242424242424242', $body);
            $this->assertStringContainsString('482094', $body);   // ordinary data survives

            return true;
        });
    }

    #[Test]
    public function query_strings_are_redacted_in_the_reported_url(): void
    {
        Http::fake(['*' => Http::response(status: 202)]);

        Route::get('/orders/{id}', function () {
            ErrorReporter::captureException(new \RuntimeException('boom'));

            return 'ok';
        });

        $this->get('/orders/42?token=leaked-token&page=3');
        ErrorReporter::flush();

        Http::assertSent(function ($request): bool {
            $url = $request->data()['request']['url'] ?? '';
            $this->assertStringNotContainsString('leaked-token', $url);
            $this->assertStringContainsString('page=3', $url);
            // The PATTERN, not the concrete path — fingerprinting depends on it.
            $this->assertSame('/orders/{id}', $request->data()['request']['route'] ?? null);

            return true;
        });
    }

    #[Test]
    public function a_dead_api_never_throws_into_the_host(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        // Invariant 1. If this throws, the SDK took the host down with it.
        $id = ErrorReporter::captureException(new \RuntimeException('boom'));
        ErrorReporter::flush();

        $this->assertNotNull($id);
    }

    #[Test]
    public function the_kill_switch_makes_capture_a_no_op(): void
    {
        config(['error-reporter.enabled' => false]);
        Http::fake();

        $this->assertNull(ErrorReporter::captureException(new \RuntimeException('boom')));
        ErrorReporter::flush();

        Http::assertNothingSent();
    }

    #[Test]
    public function missing_credentials_disable_reporting_rather_than_erroring(): void
    {
        config(['error-reporter.api_key' => null]);
        Http::fake();

        $this->assertNull(ErrorReporter::captureException(new \RuntimeException('boom')));
        Http::assertNothingSent();
    }

    #[Test]
    public function ignored_exception_types_are_dropped(): void
    {
        Http::fake();

        $this->assertNull(ErrorReporter::captureException(
            ValidationException::withMessages(['email' => 'The email field is required.'])
        ));
        ErrorReporter::flush();

        Http::assertNothingSent();
    }

    #[Test]
    public function the_same_throwable_is_reported_once_per_request(): void
    {
        Http::fake(['*' => Http::response(status: 202)]);

        $e = new \RuntimeException('boom');

        // The handler's reportable() hook and a manual capture in a catch block often
        // both see the same object. One object, one event, one occurrence.
        $this->assertNotNull(ErrorReporter::captureException($e));
        $this->assertNull(ErrorReporter::captureException($e));
        ErrorReporter::flush();

        Http::assertSentCount(1);
    }

    #[Test]
    public function delivery_uses_the_queue_when_a_real_one_is_configured(): void
    {
        config(['queue.default' => 'redis']);
        Queue::fake();
        Http::fake();

        ErrorReporter::captureException(new \RuntimeException('boom'));

        Queue::assertPushed(SendEvent::class);
        Http::assertNothingSent();   // nothing synchronous either way
    }

    #[Test]
    public function the_sync_queue_defers_to_after_the_response_instead(): void
    {
        // queue.default is sync in testbench. A sync queue would block the request on
        // the reporting API — the exact thing this SDK promises never to do.
        Queue::fake();
        Http::fake(['*' => Http::response(status: 202)]);

        ErrorReporter::captureException(new \RuntimeException('boom'));

        Queue::assertNotPushed(SendEvent::class);
        Http::assertNothingSent();       // not during the request...

        $this->app->terminate();         // ...only after the response has gone out

        Http::assertSentCount(1);
    }

    #[Test]
    public function vendor_frames_are_marked_out_of_app(): void
    {
        $builder = new \Stree\ErrorReporter\EventBuilder(
            new Sanitizer, 'testing', null, '/srv/app', '1.0.0'
        );

        $this->assertTrue($builder->inApp('/srv/app/app/Services/Gateway.php'));
        $this->assertFalse($builder->inApp('/srv/app/vendor/laravel/framework/src/Foo.php'));
        $this->assertFalse($builder->inApp('/srv/app/storage/framework/views/abc.php'));
        $this->assertFalse($builder->inApp('/usr/lib/php/somewhere.php'));
    }
}
