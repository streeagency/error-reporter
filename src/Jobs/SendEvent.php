<?php

declare(strict_types=1);

namespace Stree\ErrorReporter\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Stree\ErrorReporter\Transport\TransportInterface;

/**
 * Queued delivery when the host has a real queue. Carries the already-built payload,
 * never the Throwable — exceptions do not serialize, and building at capture time means
 * the request context is still alive.
 */
final class SendEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    // One try. The event_id makes a retry SAFE server-side, but a failing reporting API
    // must not accumulate a backlog inside the host's queue — their queue exists for
    // their jobs.
    public int $tries = 1;

    /** @param array<string, mixed> $event */
    public function __construct(public readonly array $event) {}

    public function handle(TransportInterface $transport): void
    {
        $transport->send($this->event);
    }
}
