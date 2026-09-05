<?php

namespace Tests\Unit\Services\Telegram;

use App\Services\Telegram\TelegramDocumentPayload;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TelegramDocumentPayloadTest extends TestCase
{
    public function test_it_exposes_document_bytes_and_safe_filename_for_one_send_attempt(): void
    {
        $payload = new TelegramDocumentPayload(
            documentContents: "LHIE STORE\nemail : buyer@example.test",
            filename: 'lhie-store-INV-421.txt',
        );

        $this->assertSame("LHIE STORE\nemail : buyer@example.test", $payload->documentContents());
        $this->assertSame('lhie-store-INV-421.txt', $payload->filename());
        $this->assertSame(37, $payload->byteLength());
    }

    #[DataProvider('invalidPayloads')]
    public function test_it_rejects_invalid_document_payloads(string $contents, string $filename): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TelegramDocumentPayload($contents, $filename);
    }

    public function test_it_enforces_the_one_megabyte_bound_without_truncating(): void
    {
        $valid = new TelegramDocumentPayload(
            str_repeat('x', TelegramDocumentPayload::MAX_BYTES),
            'lhie-store-maximum.txt',
        );
        $this->assertSame(TelegramDocumentPayload::MAX_BYTES, $valid->byteLength());

        $this->expectException(InvalidArgumentException::class);
        new TelegramDocumentPayload(
            str_repeat('x', TelegramDocumentPayload::MAX_BYTES + 1),
            'lhie-store-too-large.txt',
        );
    }

    public function test_debug_json_and_serialization_do_not_expose_document_bytes_or_filename(): void
    {
        $secret = 'payload-secret@example.test|private-password';
        $filename = 'lhie-store-private-order.txt';
        $payload = new TelegramDocumentPayload($secret, $filename);

        $debug = $payload->__debugInfo();
        $this->assertSame([
            'byte_length' => strlen($secret),
            'format' => 'txt',
        ], $debug);
        $this->assertStringNotContainsString($secret, json_encode($debug, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($filename, json_encode($debug, JSON_THROW_ON_ERROR));
        $this->assertSame('{}', json_encode($payload, JSON_THROW_ON_ERROR));

        $this->expectException(LogicException::class);
        serialize($payload);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidPayloads(): iterable
    {
        yield 'empty document' => ['', 'lhie-store-order.txt'];
        yield 'invalid utf8' => ["\xFF", 'lhie-store-order.txt'];
        yield 'bom' => ["\xEF\xBB\xBFtext", 'lhie-store-order.txt'];
        yield 'crlf' => ["line\r\nline", 'lhie-store-order.txt'];
        yield 'unsafe filename' => ['text', '../order.txt'];
        yield 'wrong extension' => ['text', 'lhie-store-order.pdf'];
        yield 'dot segment' => ['text', 'lhie-store-..-order.txt'];
    }
}
