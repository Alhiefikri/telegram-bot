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
    public function test_invalid_message_ids_are_never_valid(mixed $messageId): void
    {
        $this->assertFalse(app(TelegramOutboundResponseValidator::class)->isValidMessageId($messageId));
    }

    #[DataProvider('invalidMessageIds')]
    public function test_successful_response_without_a_proven_message_id_is_ambiguous(mixed $messageId): void
    {
        $this->expectException(TelegramAmbiguousResponseException::class);

        app(TelegramOutboundResponseValidator::class)->messageIdFromResponse([
            'ok' => true,
            'result' => ['message_id' => $messageId],
        ]);
    }

    public function test_successful_response_without_a_result_is_ambiguous(): void
    {
        $this->expectException(TelegramAmbiguousResponseException::class);

        app(TelegramOutboundResponseValidator::class)->messageIdFromResponse([
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

    public function test_message_id_is_not_accepted_when_telegram_reports_failure(): void
    {
        try {
            app(TelegramOutboundResponseValidator::class)->messageIdFromResponse([
                'ok' => false,
                'result' => ['message_id' => 123],
            ]);

            $this->fail('A definite Telegram rejection must not produce a message ID.');
        } catch (TelegramAmbiguousResponseException $exception) {
            $this->fail('A definite Telegram rejection must not be ambiguous: '.$exception->getMessage());
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('successful message ID', $exception->getMessage());
        }
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
