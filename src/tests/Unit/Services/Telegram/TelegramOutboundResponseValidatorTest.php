<?php

namespace Tests\Unit\Services\Telegram;

use App\Exceptions\TelegramAmbiguousResponseException;
use App\Services\Telegram\TelegramOutboundResponseValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class TelegramOutboundResponseValidatorTest extends TestCase
{
    #[DataProvider('invalidMessageIds')]
    public function test_message_id_must_be_a_positive_integer(mixed $messageId): void
    {
        $validator = app(TelegramOutboundResponseValidator::class);

        $this->assertFalse($validator->isValidMessageId($messageId));

        $this->expectException(RuntimeException::class);
        $validator->messageIdFromResponse([
            'ok' => true,
            'result' => ['message_id' => $messageId],
        ]);
    }

    public function test_successful_document_response_without_a_valid_message_id_is_ambiguous(): void
    {
        $this->expectException(TelegramAmbiguousResponseException::class);

        app(TelegramOutboundResponseValidator::class)->messageIdFromDocumentResponse([
            'ok' => true,
        ], 'Telegram sendDocument');
    }

    #[DataProvider('validMessageIds')]
    public function test_valid_message_id_is_normalized_to_a_string(mixed $messageId, string $expected): void
    {
        $validator = app(TelegramOutboundResponseValidator::class);

        $this->assertTrue($validator->isValidMessageId($messageId));
        $this->assertSame($expected, $validator->messageIdFromResponse([
            'ok' => true,
            'result' => ['message_id' => $messageId],
        ]));
    }

    public function test_successful_message_id_is_not_accepted_when_telegram_reports_failure(): void
    {
        $this->expectException(RuntimeException::class);

        app(TelegramOutboundResponseValidator::class)->messageIdFromResponse([
            'ok' => false,
            'result' => ['message_id' => 123],
        ]);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidMessageIds(): iterable
    {
        yield 'zero integer' => [0];
        yield 'zero string' => ['0'];
        yield 'negative integer' => [-1];
        yield 'negative string' => ['-1'];
        yield 'fractional float' => [1.5];
        yield 'fractional string' => ['1.5'];
        yield 'empty string' => [''];
        yield 'non numeric string' => ['message-id'];
        yield 'numeric exponent string' => ['1e3'];
        yield 'boolean' => [true];
        yield 'null' => [null];
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function validMessageIds(): iterable
    {
        yield 'positive integer' => [123, '123'];
        yield 'positive numeric string' => ['123', '123'];
        yield 'leading zero string' => ['00123', '00123'];
    }
}
