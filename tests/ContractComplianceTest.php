<?php

declare(strict_types=1);

namespace Stree\ErrorReporter\Tests;

use Illuminate\Support\Facades\Http;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\Test;
use Stree\ErrorReporter\Facades\ErrorReporter;

/**
 * The one guarantee that outranks all the others: every payload this SDK emits
 * validates against the FROZEN v1 contract. The schema rejects unknown properties, so
 * this fails the moment anyone adds a field here without adding it to the contract —
 * which is exactly the order of operations the one-way door demands.
 */
final class ContractComplianceTest extends TestCase
{
    private const SCHEMA = __DIR__.'/../../../contracts/event.schema.json';

    #[Test]
    public function an_exception_payload_validates_against_the_frozen_contract(): void
    {
        if (! is_file(self::SCHEMA)) {
            $this->markTestSkipped('contracts/event.schema.json not present outside the monorepo');
        }

        Http::fake(['*' => Http::response(['accepted' => true], 202)]);

        ErrorReporter::setUser(['id' => '42', 'email' => 'blerim@example.com']);
        ErrorReporter::setTag('tenant', 'northbound');
        ErrorReporter::setContext(['order_id' => 482094, 'cart_total' => 303.92]);

        $id = ErrorReporter::captureException(new \RuntimeException('Gateway timeout after 30 seconds'));
        $this->assertNotNull($id);
        ErrorReporter::flush();

        $payload = $this->sentPayload();
        $result = (new Validator)->validate(
            json_decode(json_encode($payload, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR),
            json_decode((string) file_get_contents(self::SCHEMA), false, 512, JSON_THROW_ON_ERROR),
        );

        $this->assertTrue(
            $result->isValid(),
            'Payload violates the v1 contract: '.json_encode($result->error()?->args() ?? [], JSON_PRETTY_PRINT)
            .' at '.json_encode($result->error()?->keyword())
        );
    }

    #[Test]
    public function a_message_payload_validates_too(): void
    {
        if (! is_file(self::SCHEMA)) {
            $this->markTestSkipped('contracts/event.schema.json not present outside the monorepo');
        }

        Http::fake(['*' => Http::response(status: 202)]);

        ErrorReporter::captureMessage('Deploy finished', 'info');
        ErrorReporter::flush();

        $result = (new Validator)->validate(
            json_decode(json_encode($this->sentPayload(), JSON_THROW_ON_ERROR), false),
            json_decode((string) file_get_contents(self::SCHEMA), false),
        );

        $this->assertTrue($result->isValid());
    }

    /** @return array<string, mixed> */
    private function sentPayload(): array
    {
        $payload = null;
        Http::assertSent(function ($request) use (&$payload): bool {
            $payload = $request->data();

            return true;
        });

        $this->assertIsArray($payload);

        return $payload;
    }
}
