<?php

declare(strict_types=1);

namespace Stree\ErrorReporter;

/**
 * Per-request context attached to every event: who was signed in, which tenant, what
 * the app knows about the moment. Reset between requests by virtue of living on a
 * request-scoped singleton — Octane users get a fresh one per request the same way.
 */
final class Scope
{
    /** @var array<string, mixed>|null */
    private ?array $user = null;

    /** @var array<string, string> */
    private array $tags = [];

    /** @var array<string, mixed> */
    private array $context = [];

    /** @param array<string, mixed>|null $user */
    public function setUser(?array $user): void
    {
        $this->user = $user;
    }

    public function setTag(string $key, string $value): void
    {
        // Tags are indexed server-side and capped at 30; shedding the newest here beats
        // a rejected payload there.
        if (count($this->tags) < 30 || isset($this->tags[$key])) {
            $this->tags[$key] = mb_substr($value, 0, 200);
        }
    }

    /** @param array<string, mixed> $context */
    public function setContext(array $context): void
    {
        $this->context = array_merge($this->context, $context);
    }

    /** @return array<string, mixed>|null */
    public function user(): ?array
    {
        return $this->user;
    }

    /** @return array<string, string> */
    public function tags(): array
    {
        return $this->tags;
    }

    /** @return array<string, mixed> */
    public function context(): array
    {
        return $this->context;
    }
}
