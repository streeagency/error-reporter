<?php

declare(strict_types=1);

namespace Stree\ErrorReporter;

/**
 * Redacts before the payload leaves the host — invariant 6. The server sanitizes again
 * on arrival, but that pass exists to catch OLD SDK versions; this pass is the one that
 * keeps a credential from ever crossing the network at all.
 *
 * Key-based only. Value-pattern matching (Luhn, JWTs) runs server-side where a false
 * positive costs one stored field rather than a developer's ability to read their own
 * error message locally.
 */
final class Sanitizer
{
    public const REDACTED = '[REDACTED]';

    /** Mirrors the server's key list. Matched as whole words within a key. */
    private const SENSITIVE_KEYS = [
        'password', 'passwd', 'pwd', 'passphrase',
        'secret', 'token', 'apikey', 'api_key', 'access_token', 'refresh_token',
        'authorization', 'auth_token', 'bearer', 'credentials',
        'cookie', 'session', 'sessionid', 'phpsessid', 'csrf', 'xsrf', 'nonce',
        'privatekey', 'private_key', 'client_secret',
        'creditcard', 'credit_card', 'cardnumber', 'card_number', 'cvv', 'cvc', 'ccv',
        'ssn', 'pin', 'otp', 'mfa',
    ];

    /** @var string[] */
    private array $extraKeys;

    /** @var string[] */
    private array $redactQuery;

    /**
     * @param  string[]  $extraKeys
     * @param  string[]  $redactQuery
     */
    public function __construct(array $extraKeys = [], array $redactQuery = [])
    {
        $this->extraKeys = array_map('strtolower', $extraKeys);
        $this->redactQuery = array_map('strtolower', $redactQuery);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function clean(array $data): array
    {
        $out = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && $this->sensitiveKey($key)) {
                $out[$key] = self::REDACTED;

                continue;
            }

            $out[$key] = is_array($value) ? $this->clean($value) : $value;
        }

        return $out;
    }

    /** Strips configured parameters from a query string, leaving the rest readable. */
    public function cleanUrl(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['query'])) {
            return $url;
        }

        parse_str($parts['query'], $params);

        foreach ($params as $key => $value) {
            if (in_array(strtolower((string) $key), $this->redactQuery, true)) {
                $params[$key] = self::REDACTED;
            }
        }

        $rebuilt = ($parts['scheme'] ?? '') !== '' ? $parts['scheme'].'://' : '';
        $rebuilt .= $parts['host'] ?? '';
        $rebuilt .= isset($parts['port']) ? ':'.$parts['port'] : '';
        $rebuilt .= $parts['path'] ?? '';
        $rebuilt .= $params === [] ? '' : '?'.http_build_query($params);

        return $rebuilt;
    }

    private function sensitiveKey(string $key): bool
    {
        $normalized = strtolower($key);

        if (in_array($normalized, self::SENSITIVE_KEYS, true) || in_array($normalized, $this->extraKeys, true)) {
            return true;
        }

        foreach (preg_split('/[^a-z0-9]+/', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            if (in_array($word, self::SENSITIVE_KEYS, true) || in_array($word, $this->extraKeys, true)) {
                return true;
            }
        }

        return false;
    }
}
