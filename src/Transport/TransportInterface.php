<?php

declare(strict_types=1);

namespace Stree\ErrorReporter\Transport;

interface TransportInterface
{
    /** @param array<string, mixed> $event */
    public function send(array $event): void;
}
