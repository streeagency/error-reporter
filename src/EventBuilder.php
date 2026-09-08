<?php

declare(strict_types=1);

namespace Stree\ErrorReporter;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

/**
 * Turns a Throwable into the frozen v1 wire format.
 *
 * The contract is additive-only and rejects unknown properties, so every key emitted
 * here must exist in contracts/event.schema.json — the schema-validation test holds
 * this class to that.
 */
final class EventBuilder
{
    public function __construct(
        private readonly Sanitizer $sanitizer,
        private readonly string $environment,
        private readonly ?string $release,
        private readonly string $basePath,
        private readonly string $sdkVersion,
    ) {}

    /** @return array<string, mixed> */
    public function fromThrowable(Throwable $e, Scope $scope, ?Request $request): array
    {
        return $this->finalize([
            'type' => 'exception',
            'title' => mb_substr($e::class, 0, 512),
            'message' => $e->getMessage() !== '' ? mb_substr($e->getMessage(), 0, 8192) : null,
            'severity' => 'error',
            'exception' => [
                'type' => mb_substr($e::class, 0, 256),
                'value' => $e->getMessage() !== '' ? mb_substr($e->getMessage(), 0, 4096) : null,
                'stacktrace' => $this->stacktrace($e),
            ],
        ], $scope, $request, $e);
    }

    /** @return array<string, mixed> */
    public function fromMessage(string $message, string $severity, Scope $scope, ?Request $request): array
    {
        return $this->finalize([
            'type' => 'message',
            'title' => mb_substr($message, 0, 512),
            'severity' => in_array($severity, ['debug', 'info', 'warning', 'error', 'critical'], true)
                ? $severity
                : 'info',
        ], $scope, $request, null);
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private function finalize(array $event, Scope $scope, ?Request $request, ?Throwable $e): array
    {
        // Client-generated UUIDv4 — the idempotency key. A retry after a timed-out
        // request carries the same id and cannot double-count (invariant 9).
        $event['schema_version'] = 1;
        $event['event_id'] = (string) Str::uuid();
        $event['timestamp'] = now()->toIso8601String();
        $event['sdk'] = ['name' => 'laravel', 'version' => $this->sdkVersion];
        $event['environment'] = mb_substr($this->environment, 0, 64);

        if ($this->release !== null && $this->release !== '') {
            $event['release'] = mb_substr($this->release, 0, 64);
        }

        $event['contexts'] = [
            'runtime' => ['name' => 'php', 'version' => mb_substr(PHP_VERSION, 0, 32)],
            'device' => ['type' => 'server'],
        ];

        if ($request !== null) {
            $route = $request->route();
            $pattern = $route && method_exists($route, 'uri') ? $route->uri() : null;

            $event['request'] = [
                // Query string redacted HERE, before it leaves the host.
                'url' => mb_substr($this->sanitizer->cleanUrl($request->fullUrl()), 0, 2048),
                // The route PATTERN, not the concrete path — fingerprinting depends on
                // it, or every /orders/{id} becomes its own issue.
                'route' => $pattern !== null ? mb_substr('/'.ltrim($pattern, '/'), 0, 256) : null,
                'method' => mb_substr($request->method(), 0, 10),
            ];
        }

        if (($user = $scope->user()) !== null) {
            $clean = $this->sanitizer->clean($user);
            $event['user'] = array_intersect_key($clean, array_flip(['id', 'email', 'username', 'ip']));

            if (isset($event['user']['id'])) {
                $event['user']['id'] = mb_substr((string) $event['user']['id'], 0, 128);
            }
        }

        if ($scope->tags() !== []) {
            $event['tags'] = $this->sanitizer->clean($scope->tags());
        }

        $extra = $scope->context();

        if ($e?->getPrevious() !== null) {
            $extra['previous_exception'] = mb_substr(
                $e->getPrevious()::class.': '.$e->getPrevious()->getMessage(), 0, 1024
            );
        }

        if ($extra !== []) {
            $event['extra'] = $this->sanitizer->clean($extra);
        }

        return $event;
    }

    /** @return array<int, array<string, mixed>> */
    private function stacktrace(Throwable $e): array
    {
        $frames = [[
            'filename' => $this->relative($e->getFile()),
            'function' => null,
            'lineno' => $e->getLine(),
            'in_app' => $this->inApp($e->getFile()),
        ]];

        foreach (array_slice($e->getTrace(), 0, 99) as $frame) {
            $frames[] = [
                'filename' => isset($frame['file']) ? $this->relative($frame['file']) : null,
                'function' => isset($frame['function'])
                    ? mb_substr(($frame['class'] ?? '').($frame['type'] ?? '').$frame['function'], 0, 256)
                    : null,
                'lineno' => $frame['line'] ?? null,
                'in_app' => isset($frame['file']) && $this->inApp($frame['file']),
            ];
        }

        return $frames;
    }

    /**
     * Vendor and framework frames are marked in_app=false. Fingerprinting uses in_app
     * frames only, so a dependency upgrade does not regroup every issue.
     */
    public function inApp(string $file): bool
    {
        $base = rtrim($this->basePath, '/');

        return str_starts_with($file, $base.'/')
            && ! str_starts_with($file, $base.'/vendor/')
            && ! str_starts_with($file, $base.'/storage/');
    }

    private function relative(string $file): string
    {
        $base = rtrim($this->basePath, '/').'/';

        return mb_substr(str_starts_with($file, $base) ? substr($file, strlen($base)) : $file, 0, 512);
    }
}
