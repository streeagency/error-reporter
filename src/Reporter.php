<?php

declare(strict_types=1);

namespace Stree\ErrorReporter;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Stree\ErrorReporter\Jobs\SendEvent;
use Stree\ErrorReporter\Transport\TransportInterface;
use Throwable;

/**
 * The public surface behind the ErrorReporter facade.
 *
 * Invariant 1 is enforced HERE, once: every public method body is wrapped so that no
 * bug in this package — or in the API it talks to — can ever take the host application
 * down with it. Capturing the error is the job; it is never worth breaking the page.
 */
final class Reporter
{
    private Scope $scope;

    /** Events built this request, awaiting the after-response flush. @var array<int, array<string, mixed>> */
    private array $pending = [];

    private bool $terminatingRegistered = false;

    /** Object ids already captured this request, so handler + manual capture do not double-report. @var array<int, true> */
    private array $seen = [];

    public function __construct(
        private readonly Application $app,
        private readonly EventBuilder $builder,
        private readonly TransportInterface $transport,
        /** @var array<string, mixed> */
        private readonly array $config,
    ) {
        $this->scope = new Scope;
    }

    /** Returns the event id, or null when the event was not (or could not be) captured. */
    public function captureException(Throwable $e): ?string
    {
        try {
            if (! $this->enabled() || $this->ignored($e)) {
                return null;
            }

            // The same Throwable often reaches both the reportable() hook and a manual
            // capture in a catch block. One object, one event.
            $id = spl_object_id($e);

            if (isset($this->seen[$id])) {
                return null;
            }

            $this->seen[$id] = true;

            $event = $this->builder->fromThrowable($e, $this->scope, $this->currentRequest());
            $this->deliver($event);

            return $event['event_id'];
        } catch (Throwable) {
            return null;    // reporting must never throw into the host
        }
    }

    public function captureMessage(string $message, string $severity = 'info'): ?string
    {
        try {
            if (! $this->enabled() || trim($message) === '') {
                return null;
            }

            $event = $this->builder->fromMessage($message, $severity, $this->scope, $this->currentRequest());
            $this->deliver($event);

            return $event['event_id'];
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string, mixed>|null $user */
    public function setUser(?array $user): void
    {
        try {
            $this->scope->setUser($user);
        } catch (Throwable) {
        }
    }

    public function setTag(string $key, string $value): void
    {
        try {
            $this->scope->setTag($key, $value);
        } catch (Throwable) {
        }
    }

    /** @param array<string, mixed> $context */
    public function setContext(array $context): void
    {
        try {
            $this->scope->setContext($context);
        } catch (Throwable) {
        }
    }

    /**
     * The @errorReporter directive body: inline stub plus the async bundle, configured
     * with the PUBLIC key. Renders to an empty string on any problem — a broken
     * reporting snippet must never break the host's page (invariant 1 again).
     */
    public function browserScripts(): string
    {
        try {
            $browser = $this->config['browser'] ?? [];

            if (! ($browser['enabled'] ?? false)
                || blank($browser['public_key'] ?? null)
                || blank($this->config['api_url'] ?? null)) {
                return '';
            }

            // A secret key in a page would be a credential leak to every visitor.
            // Refusing to render beats rendering something catastrophic.
            if (str_starts_with((string) $browser['public_key'], 'sk_')) {
                return '';
            }

            return view('error-reporter::scripts', [
                // captureExceptions and captureRejections default to FALSE in the SDK,
                // so they are sent explicitly: enabling browser reporting here IS the
                // opt-in. Without them the bundle initialises and listens for nothing.
                'config' => [
                    'apiUrl' => rtrim((string) $this->config['api_url'], '/'),
                    'publicKey' => (string) $browser['public_key'],
                    'environment' => (string) ($this->config['environment'] ?? 'production'),
                    'release' => $this->config['release'] ?? null,
                    'captureExceptions' => true,
                    'captureRejections' => true,
                ],
                'stub' => file_get_contents(__DIR__.'/../resources/stub.js') ?: '',
                'sdkUrl' => $this->sdkUrl(),
            ])->render();
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * Where the browser bundle lives.
     *
     * A configured URL always wins, so a site pinned to a version stays pinned. Otherwise
     * the platform is asked which version is current and the answer is cached for half a
     * day. What comes back is still an immutable /sdk/x.y.z/sdk.js — only the discovery is
     * dynamic, so a browser-side fix reaches every host app without each one being edited.
     */
    private function sdkUrl(): ?string
    {
        $configured = $this->config['browser']['sdk_url'] ?? null;

        if (filled($configured)) {
            return (string) $configured;
        }

        // A failed lookup is cached too, briefly. Without that an unreachable platform
        // means an outbound request on every page render of the host application.
        $url = Cache::remember('error-reporter.sdk-url', now()->addHours(12), function (): string {
            try {
                $response = Http::withHeaders(['X-Er-Key' => (string) ($this->config['api_key'] ?? '')])
                    ->timeout((int) ($this->config['timeout'] ?? 3))
                    ->acceptJson()
                    ->get(rtrim((string) $this->config['api_url'], '/').'/api/v1/sdk');

                $url = $response->successful() ? (string) $response->json('url') : '';

                // Only ever a versioned bundle path.
                return preg_match('#^https?://\S+/sdk/\d+\.\d+\.\d+/sdk\.js$#', $url) === 1 ? $url : '';
            } catch (Throwable) {
                return '';
            }
        });

        return $url === '' ? null : $url;
    }

    /** Sends everything deferred to after-response delivery. Called by terminating(). */
    public function flush(): void
    {
        $pending = $this->pending;
        $this->pending = [];

        foreach ($pending as $event) {
            $this->transport->send($event);
        }
    }

    /** @param array<string, mixed> $event */
    private function deliver(array $event): void
    {
        if ($this->useQueue()) {
            SendEvent::dispatch($event)
                ->onConnection($this->config['queue']['connection'] ?? null)
                ->onQueue($this->config['queue']['queue'] ?? null);

            return;
        }

        // No real queue: hold the event and send after the response has gone out, so
        // the user never waits on the reporting API.
        $this->pending[] = $event;

        if (! $this->terminatingRegistered) {
            $this->terminatingRegistered = true;
            $this->app->terminating(fn () => $this->flush());
        }
    }

    private function useQueue(): bool
    {
        $mode = $this->config['queue']['mode'] ?? 'auto';

        if ($mode === 'terminating') {
            return false;
        }

        if ($mode === 'queue') {
            return true;
        }

        // auto: a sync queue would make delivery block the request — the exact thing
        // this SDK promises never to do — so it falls back to terminating.
        $default = (string) config('queue.default');
        $driver = (string) config("queue.connections.{$default}.driver");

        return ! in_array($driver, ['sync', 'null', ''], true);
    }

    private function enabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? false)
            && filled($this->config['api_url'] ?? null)
            && filled($this->config['api_key'] ?? null);
    }

    private function ignored(Throwable $e): bool
    {
        foreach ($this->config['ignore_exceptions'] ?? [] as $class) {
            if ($e instanceof $class) {
                return true;
            }
        }

        return false;
    }

    private function currentRequest(): ?\Illuminate\Http\Request
    {
        try {
            $request = $this->app->bound('request') ? $this->app->make('request') : null;

            if (! $request instanceof \Illuminate\Http\Request) {
                return null;
            }

            // runningInConsole() alone is the wrong signal: it is also true inside HTTP
            // kernel tests, where a real routed request exists and its context is exactly
            // what we want. A routed request is trustworthy wherever it came from; an
            // unrouted one in a console process is just the placeholder Laravel binds
            // at boot, whose URL is meaningless.
            if ($request->route() === null && $this->app->runningInConsole()) {
                return null;
            }

            return $request;
        } catch (Throwable) {
            return null;
        }
    }
}
