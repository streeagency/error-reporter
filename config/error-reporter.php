<?php

return [

    // The kill switch. False means every capture call becomes a no-op — the cheapest
    // possible way to turn reporting off on a site that is having a bad day.
    'enabled' => env('ERROR_REPORTER_ENABLED', true),

    // Base URL of the ErrorReporter install; the SDK appends /api/v1/events itself.
    'api_url' => env('ERROR_REPORTER_API_URL'),

    // A SECRET key (sk_...) because this runs server-side. Never a pk_ key, and never
    // anywhere a browser can read.
    'api_key' => env('ERROR_REPORTER_API_KEY'),

    'environment' => env('ERROR_REPORTER_ENVIRONMENT') ?: env('APP_ENV', 'production'),

    // Version string; enables regression detection on the server. Harmless to omit.
    'release' => env('ERROR_REPORTER_RELEASE'),

    // Hard ceiling on time spent talking to the API. The SDK must never make the host
    // slow, so this stays small and is never retried synchronously — a lost event is
    // recoverable, a slow checkout is not.
    'timeout' => 3,

    // Where delivery happens. 'auto' uses the host's queue when one is configured and
    // falls back to an after-response callback on the sync driver, so no request ever
    // waits on the API either way.
    'queue' => [
        'mode' => 'auto',            // auto | queue | terminating
        'connection' => null,        // null = the host's default
        'queue' => null,             // null = the connection's default queue
    ],

    // Never reported, even when captured manually. Laravel's handler already skips its
    // own dont-report list for the reportable() hook; this covers direct calls too.
    'ignore_exceptions' => [
        \Illuminate\Auth\AuthenticationException::class,
        \Illuminate\Auth\Access\AuthorizationException::class,
        \Illuminate\Validation\ValidationException::class,
        \Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
    ],

    // Redaction happens HERE, before the payload leaves the host. The server sanitizes
    // again on arrival, but that is the second line of defence — once a credential
    // crosses the network it has been stored, whatever happens next.
    'redact_keys' => [],             // merged with the built-in list in Sanitizer

    'redact_query' => [
        'token', 'access_token', 'api_key', 'apikey', 'key', 'secret',
        'password', 'auth', 'signature', 'sig',
    ],

    // The @errorReporter Blade directive: inline stub + async browser bundle. Uses the
    // PUBLIC key — the secret above must never reach a page.
    'browser' => [
        'enabled' => env('ERROR_REPORTER_BROWSER', false),
        'public_key' => env('ERROR_REPORTER_PUBLIC_KEY'),
        // Immutable versioned URL, never a mutable latest.js.
        'sdk_url' => env('ERROR_REPORTER_SDK_URL'),
    ],
];
