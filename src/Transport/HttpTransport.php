<?php

declare(strict_types=1);

namespace Stree\ErrorReporter\Transport;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * One POST, one hard timeout, no synchronous retry, and failures are logged locally
 * and dropped. A lost event is recoverable — the next occurrence resends context — but
 * a checkout held hostage by a reporting API is exactly the failure this SDK exists to
 * report, and the one thing it must never cause (invariant 1).
 */
final class HttpTransport implements TransportInterface
{
    public function __construct(
        private readonly string $apiUrl,
        private readonly string $apiKey,
        private readonly int $timeout,
    ) {}

    /** @param array<string, mixed> $event */
    public function send(array $event): void
    {
        try {
            $response = Http::withHeaders(['X-Er-Key' => $this->apiKey])
                ->timeout($this->timeout)
                ->connectTimeout(min(2, $this->timeout))
                ->acceptJson()
                ->post(rtrim($this->apiUrl, '/').'/api/v1/events', $event);

            if ($response->status() >= 400) {
                Log::debug('error-reporter: API rejected an event', [
                    'status' => $response->status(),
                    'event_id' => $event['event_id'] ?? null,
                ]);
            }
        } catch (\Throwable $e) {
            // Never rethrown. The debug channel is deliberate: an unreachable API during
            // an incident would otherwise flood the host's own error log.
            Log::debug('error-reporter: could not deliver an event', [
                'reason' => $e->getMessage(),
                'event_id' => $event['event_id'] ?? null,
            ]);
        }
    }
}
