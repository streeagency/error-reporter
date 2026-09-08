# stree/error-reporter

Laravel client for ErrorReporter. Reports exceptions to the central API and never, under
any circumstances, breaks the application it is installed in.

## Install

```bash
composer require stree/error-reporter
```

Until the package is on Packagist, add the monorepo as a VCS repository first:

```json
"repositories": [{ "type": "vcs", "url": "https://github.com/streeagency/error-reporter" }]
```

```dotenv
ERROR_REPORTER_ENABLED=true
ERROR_REPORTER_API_URL=https://www.....
ERROR_REPORTER_API_KEY=sk_...        # secret key — server-side only, never a pk_
ERROR_REPORTER_ENVIRONMENT=production
ERROR_REPORTER_RELEASE=1.4.2
```

```php
// bootstrap/app.php — reportable() ADDS a listener; Laravel's own logging is untouched.
->withExceptions(function (Exceptions $exceptions) {
    $exceptions->reportable(fn (Throwable $e) => ErrorReporter::captureException($e));
})
```

## Context

```php
ErrorReporter::setUser(['id' => $user->id]);
ErrorReporter::setTag('tenant', $tenant->slug);          // indexed — low cardinality only
ErrorReporter::setContext(['order_id' => $order->id]);   // not indexed — anything goes
ErrorReporter::captureMessage('Deploy finished', 'info');
```

## Browser widget

```blade
{{-- before </body> in your layout --}}
@errorReporter
```

Renders the inline stub (catches errors thrown before the async bundle loads) plus the
bundle tag. Uses `ERROR_REPORTER_PUBLIC_KEY` (a `pk_` key) — the directive refuses to
render at all if it is handed a secret key.

## Guarantees, in order

1. **Never breaks the host.** Every public method is exception-proof, delivery has a hard
   3s timeout with no synchronous retry, and failures are logged to `debug` and dropped.
2. **Sanitizes before sending.** Credentials are redacted client-side; the server's
   sanitizer is the second line, not the first.
3. **Never blocks a request.** Delivery goes to the host's queue when a real one is
   configured, otherwise to an after-response `terminating()` callback.
4. **Contract-bound.** The test suite validates every emitted payload against the frozen
   `contracts/event.schema.json` — a field added here without the contract fails CI.

## Tests

```bash
composer install
vendor/bin/phpunit    # 13 tests
```

`resources/stub.js` is copied from `packages/js-sdk/dist/stub.js` — re-copy after any
stub change there.
