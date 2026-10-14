<?php

namespace Tests\Unit\Services\Telegram;

use App\Exceptions\TelegramApiException;
use App\Exceptions\TelegramTransportException;
use App\Services\Telegram\CustomEmojiCapability;
use App\Services\Telegram\FakeTelegramBotClient;
use App\Services\Telegram\TelegramBotClient;
use App\Services\Telegram\TelegramCallbackAckState;
use App\Services\Telegram\TelegramHtmlCaption;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Throwable;

class TelegramBotClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.telegram', [
            'token' => 'issue-114-token',
            'webhook_secret' => 'issue-114-webhook-secret',
            'api_base_url' => 'https://api.telegram.org',
            'http_timeout_seconds' => 7.25,
            'http_connect_timeout_seconds' => 2.5,
        ]);

        app(CustomEmojiCapability::class)->markAvailable();
    }

    #[DataProvider('copyBlockFallbackCases')]
    public function test_custom_emoji_fallback_preserves_literal_copy_block_and_utf16_range(bool $cachedUnavailable, bool $photo): void
    {
        if ($cachedUnavailable) {
            app(CustomEmojiCapability::class)->markUnavailable('Cached rejection');
        }
        $calls = 0;
        Http::fake(function () use (&$calls, $cachedUnavailable) {
            $calls++;
            if (! $cachedUnavailable && $calls === 1) {
                return Http::response(['ok' => false, 'description' => "Bad Request: can't use custom emoji in this chat"], 400);
            }

            return Http::response(['ok' => true, 'result' => ['message_id' => 801]]);
        });
        $prefix = '<tg-emoji emoji-id="5368324170671202286" data-omp-icon="1">📦</tg-emoji> Demo'."\n";
        $credential = 'buyer@example.test | a<tg-emoji emoji-id="123">🔐</tg-emoji>b';
        $entities = [[
            'type' => 'pre',
            'language' => 'copy',
            'offset' => intdiv(strlen(mb_convert_encoding($prefix, 'UTF-16LE', 'UTF-8')), 2),
            'length' => intdiv(strlen(mb_convert_encoding($credential, 'UTF-16LE', 'UTF-8')), 2),
        ]];
        if ($photo) {
            app(TelegramBotClient::class)->sendPhoto(12345, 'dummy-photo', 'receipt.png', $prefix.$credential, [], [
                'caption_entities' => json_encode($entities, JSON_THROW_ON_ERROR),
            ]);
        } else {
            app(TelegramBotClient::class)->sendMessage(12345, $prefix.$credential, [], ['entities' => $entities]);
        }
        $request = Http::recorded()->last()[0];
        $text = $photo ? $this->multipartField($request, 'caption') : $request['text'];
        $resultEntities = $photo ? $this->multipartField($request, 'caption_entities') : $request['entities'];
        if (is_string($resultEntities)) {
            $resultEntities = json_decode($resultEntities, true, flags: JSON_THROW_ON_ERROR);
        }
        $this->assertSame("📦 Demo\n".$credential, $text);
        $this->assertSame(['pre'], array_column($resultEntities, 'type'));
        $utf16 = mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');
        $entity = $resultEntities[0];
        $this->assertSame(intdiv(strlen($utf16), 2), $entity['offset'] + $entity['length']);
        $this->assertSame($credential, mb_convert_encoding(
            substr($utf16, $entity['offset'] * 2, $entity['length'] * 2),
            'UTF-8', 'UTF-16LE',
        ));
    }

    public static function copyBlockFallbackCases(): iterable
    {
        yield 'cached message' => [true, false];
        yield 'rejected message' => [false, false];
        yield 'cached JSON caption' => [true, true];
        yield 'rejected JSON caption' => [false, true];
    }

    public function test_inline_buttons_default_to_blue_without_overriding_sold_out_or_reply_keyboard_styles(): void
    {
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);
        $client = app(TelegramBotClient::class);
        $markup = ['inline_keyboard' => [[
            ['text' => 'Refresh', 'callback_data' => 'catalog:refresh'],
            ['text' => 'Sold out', 'style' => 'danger', 'disabled' => (object) []],
        ]]];
        $client->sendMessage(12345, 'Catalog', $markup);
        $client->editMessageReplyMarkup(12345, 777, [], [
            'reply_markup' => json_encode($markup, JSON_THROW_ON_ERROR),
        ]);
        $client->sendMessage(12345, 'Menu', ['keyboard' => [[['text' => 'Browse']]]]);

        $requests = Http::recorded();
        $buttons = $requests[0][0]['reply_markup']['inline_keyboard'][0];
        $this->assertSame('primary', $buttons[0]['style']);
        $this->assertSame('danger', $buttons[1]['style']);
        $this->assertEquals((object) [], $buttons[1]['disabled']);
        $encoded = json_decode($requests[1][0]['reply_markup'], flags: JSON_THROW_ON_ERROR);
        $this->assertSame('primary', $encoded->inline_keyboard[0][0]->style);
        $this->assertSame('danger', $encoded->inline_keyboard[0][1]->style);
        $this->assertEquals((object) [], $encoded->inline_keyboard[0][1]->disabled);
        $this->assertArrayNotHasKey('style', $requests[2][0]['reply_markup']['keyboard'][0][0]);
    }

    public function test_runtime_methods_apply_configured_http_timeouts_to_json_and_multipart_requests(): void
    {
        $requests = [];
        $optionsByOperation = [];

        Http::fake(function ($request, array $options) use (&$requests, &$optionsByOperation) {
            $operation = $this->operationForRequest($request);

            $requests[$operation] = $request;
            $optionsByOperation[$operation] = $options;

            return Http::response(['ok' => true, 'result' => ['id' => 12345, 'message_id' => 12345]], 200);
        });

        $client = app(TelegramBotClient::class);

        $client->getChat(12345);
        $client->getChatMember(12345, 67890);
        $client->sendMessage(12345, 'Timeout regression coverage');
        $client->editMessageText(12345, 777, 'Edited message', ['inline_keyboard' => [[['text' => 'Next', 'callback_data' => 'next']]]]);
        $client->sendPhoto(12345, 'binary-photo', 'receipt.png', 'Receipt');
        $client->sendPhotoReference(12345, 'telegram-file-id', 'Receipt reference');
        $client->sendDocument(12345, 'binary-document', 'download.txt');
        $client->answerCallbackQuery('callback-query-id', 'Done');
        $client->deleteMessage(12345, 777);

        $this->assertSame([
            'getChat',
            'getChatMember',
            'sendMessage',
            'editMessageText',
            'sendPhoto',
            'sendPhotoReference',
            'sendDocument',
            'answerCallbackQuery',
            'deleteMessage',
        ], array_keys($optionsByOperation));
        Http::assertSentCount(9);

        foreach ($optionsByOperation as $operation => $options) {
            $this->assertArrayHasKey('timeout', $options, "{$operation} did not configure an HTTP timeout.");
            $this->assertSame(7.25, $options['timeout'], "{$operation} used the wrong HTTP timeout.");
            $this->assertArrayHasKey('connect_timeout', $options, "{$operation} did not configure an HTTP connect timeout.");
            $this->assertSame(2.5, $options['connect_timeout'], "{$operation} used the wrong HTTP connect timeout.");
        }

        $this->assertTrue($requests['sendMessage']->isJson(), 'sendMessage should use the JSON post request path.');
        $this->assertSame('Timeout regression coverage', $requests['sendMessage']['text']);
        $this->assertTrue($requests['editMessageText']->isJson(), 'editMessageText should use the JSON post request path.');
        $this->assertSame(777, $requests['editMessageText']['message_id']);
        $this->assertSame('Edited message', $requests['editMessageText']['text']);
        $this->assertTrue($requests['sendPhoto']->isMultipart(), 'sendPhoto should use the multipart attach request path.');
        $this->assertTrue($requests['sendPhoto']->hasFile('photo', 'binary-photo', 'receipt.png'));
        $this->assertTrue($requests['sendDocument']->isMultipart(), 'sendDocument should use the multipart attach request path.');
        $this->assertTrue($requests['sendDocument']->hasFile('document', 'binary-document', 'download.txt'));
    }

    public function test_send_photo_encodes_caption_entities_and_keyboard_in_multipart_upload(): void
    {
        $contents = "\x89PNG\r\n\x1A\nphoto-bytes";
        $entities = [['type' => 'code', 'offset' => 0, 'length' => 6]];
        $replyMarkup = ['inline_keyboard' => [[['text' => 'Check', 'callback_data' => 'check']]]];

        Http::fake(['https://api.telegram.org/*/sendPhoto' => Http::response([
            'ok' => true,
            'result' => ['message_id' => 801],
        ])]);

        app(TelegramBotClient::class)->sendPhoto(
            12345,
            $contents,
            'receipt.png',
            'ABC123',
            $replyMarkup,
            [
                'caption_entities' => $entities,
                'protect_content' => true,
            ],
        );

        $request = Http::recorded()->sole()[0];
        $this->assertTrue($request->isMultipart());
        $this->assertTrue($request->hasFile('photo', $contents, 'receipt.png'));
        $this->assertSame('12345', (string) $this->multipartField($request, 'chat_id'));
        $this->assertSame('ABC123', $this->multipartField($request, 'caption'));
        $this->assertSame(
            $entities,
            json_decode((string) $this->multipartField($request, 'caption_entities'), true, 512, JSON_THROW_ON_ERROR),
        );
        $this->assertTrue(filter_var($this->multipartField($request, 'protect_content'), FILTER_VALIDATE_BOOLEAN));
    }

    public function test_not_modified_edit_is_idempotent_without_a_duplicate_send(): void
    {
        Http::fake([
            'https://api.telegram.org/*/editMessageText' => Http::response([
                'ok' => false,
                'description' => 'Bad Request: message is not modified',
            ], 400),
        ]);

        app(TelegramBotClient::class)->editMessageTextOrSend(
            12345,
            'Already rendered',
            ['inline_keyboard' => []],
            777,
        );

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/editMessageText'));
    }

    public function test_definite_edit_failure_falls_back_to_one_new_message(): void
    {
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/editMessageText')) {
                return Http::response([
                    'ok' => false,
                    'description' => 'Bad Request: message to edit not found',
                ], 400);
            }

            return Http::response(['ok' => true, 'result' => ['message_id' => 778]], 200);
        });

        app(TelegramBotClient::class)->editMessageTextOrSend(12345, 'Fallback text', [], 777);

        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/sendMessage')
            && $request['text'] === 'Fallback text');
    }

    public function test_edit_transport_ambiguity_never_falls_back_to_send(): void
    {
        Http::fake([
            'https://api.telegram.org/*/editMessageText' => Http::failedConnection('timed out'),
        ]);

        try {
            app(TelegramBotClient::class)->editMessageTextOrSend(12345, 'Ambiguous text', [], 777);
            $this->fail('Expected the edit transport failure to remain ambiguous.');
        } catch (TelegramTransportException $exception) {
            $this->assertStringContainsString('editMessageText', $exception->getMessage());
        }

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/editMessageText'));
    }

    public function test_custom_emoji_markup_becomes_a_custom_emoji_entity_in_plain_text(): void
    {
        Http::fake(['https://api.telegram.org/*/sendMessage' => Http::response([
            'ok' => true,
            'result' => ['message_id' => 779],
        ])]);

        app(TelegramBotClient::class)->sendMessage(
            12345,
            'A 😀 <tg-emoji emoji-id="5368324170671202286" data-omp-icon="1">🪙</tg-emoji> & B',
        );

        $request = Http::recorded()[0][0];
        $this->assertSame('A 😀 🪙 & B', $request['text']);
        $this->assertSame([[
            'type' => 'custom_emoji',
            'offset' => 5,
            'length' => 2,
            'custom_emoji_id' => '5368324170671202286',
        ]], $request['entities']);
        $this->assertArrayNotHasKey('parse_mode', $request->data());
    }

    public function test_admin_authored_custom_emoji_markup_becomes_an_entity_and_degrades_in_buttons(): void
    {
        Http::fake(['https://api.telegram.org/*/sendMessage' => Http::response([
            'ok' => true,
            'result' => ['message_id' => 781],
        ])]);

        $literal = '<tg-emoji emoji-id="5368324170671202286">🪙</tg-emoji>';
        app(TelegramBotClient::class)->sendMessage(12345, 'Admin text '.$literal, [
            'inline_keyboard' => [[[
                'text' => $literal,
                'callback_data' => 'literal',
            ]]],
        ]);

        $request = Http::recorded()[0][0];
        // A plain-text payload must never contain literal markup: the wrapper
        // becomes an entity, and button labels keep the glyph.
        $this->assertSame('Admin text 🪙', $request['text']);
        $this->assertSame('🪙', $request['reply_markup']['inline_keyboard'][0][0]['text']);
        $this->assertSame([[
            'type' => 'custom_emoji',
            'offset' => 11,
            'length' => 2,
            'custom_emoji_id' => '5368324170671202286',
        ]], $request['entities']);
    }

    public function test_catalog_custom_emoji_markup_becomes_a_custom_emoji_entity_in_plain_text(): void
    {
        Http::fake(['https://api.telegram.org/*/sendMessage' => Http::response([
            'ok' => true,
            'result' => ['message_id' => 782],
        ])]);

        app(TelegramBotClient::class)->sendMessage(
            12345,
            'Product <tg-emoji emoji-id="5420323339723881652">⚠️</tg-emoji> shipped',
        );

        $request = Http::recorded()[0][0];
        $this->assertSame('Product ⚠️ shipped', $request['text']);
        $this->assertSame([[
            'type' => 'custom_emoji',
            'offset' => 8,
            'length' => 2,
            'custom_emoji_id' => '5420323339723881652',
        ]], $request['entities']);
        $this->assertArrayNotHasKey('parse_mode', $request->data());
    }

    public function test_custom_emoji_markup_in_inline_button_text_becomes_the_button_icon(): void
    {
        Http::fake(['https://api.telegram.org/*/sendMessage' => Http::response([
            'ok' => true,
            'result' => ['message_id' => 780],
        ])]);

        app(TelegramBotClient::class)->sendMessage(12345, 'Choose', [
            'inline_keyboard' => [[[
                'text' => '<tg-emoji emoji-id="5368324170671202286" data-omp-icon="1">📌</tg-emoji> Status',
                'callback_data' => 'status',
            ]]],
        ]);

        $request = Http::recorded()[0][0];
        $button = $request['reply_markup']['inline_keyboard'][0][0];
        $this->assertSame('Status', $button['text']);
        $this->assertSame('5368324170671202286', $button['icon_custom_emoji_id']);
    }

    public function test_html_caption_preserves_static_custom_emoji_while_escaping_blockquote_text(): void
    {
        $caption = app(TelegramHtmlCaption::class)->htmlWithBlockquotes(
            'Static <tg-emoji emoji-id="5368324170671202286" data-omp-icon="1">📌</tg-emoji> Detail < value >',
            ['Detail < value >'],
        );

        $this->assertSame(
            'Static <tg-emoji emoji-id="5368324170671202286">📌</tg-emoji> <blockquote>Detail &lt; value &gt;</blockquote>',
            $caption,
        );
    }

    public function test_custom_emoji_rejection_retries_without_message_or_button_icons(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;

            if ($calls === 1) {
                return Http::response([
                    'ok' => false,
                    'description' => "Bad Request: can't use custom emoji in this chat",
                ], 400);
            }

            return Http::response(['ok' => true, 'result' => ['message_id' => 779]], 200);
        });

        $capability = app(CustomEmojiCapability::class);
        $this->assertTrue($capability->isAvailable());

        app(TelegramBotClient::class)->sendMessage(12345, 'Katalog <tg-emoji emoji-id="5368324170671202286" data-omp-icon="1">📩</tg-emoji>', [
            'inline_keyboard' => [
                [['text' => 'ChatGPT', 'icon_custom_emoji_id' => '5368324170671202286']],
                [['text' => 'Netflix', 'icon_custom_emoji_id' => '5368324170671202286']],
            ],
        ], ['parse_mode' => 'HTML']);

        Http::assertSentCount(2);
        $this->assertFalse($capability->isAvailable(), 'A rejected send must disable custom emoji for later sends.');
        $this->assertNotNull($capability->unavailableReason());

        $retry = Http::recorded()[1][0];
        $this->assertSame('Katalog 📩', $retry['text']);
        $this->assertSame('HTML', $retry['parse_mode']);
        foreach ($retry['reply_markup']['inline_keyboard'] as $row) {
            $this->assertArrayNotHasKey('icon_custom_emoji_id', $row[0]);
        }
    }

    public function test_custom_emoji_rejection_in_existing_photo_caption_retries_without_custom_markup(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;

            if ($calls === 1) {
                return Http::response([
                    'ok' => false,
                    'description' => "Bad Request: can't use custom emoji in this chat",
                ], 400);
            }

            return Http::response(['ok' => true, 'result' => ['message_id' => 782]], 200);
        });

        app(TelegramBotClient::class)->editMessageCaption(
            12345,
            777,
            'Catalog <tg-emoji emoji-id="5368324170671202286" data-omp-icon="1">📩</tg-emoji>',
            ['inline_keyboard' => [[['text' => 'ChatGPT', 'icon_custom_emoji_id' => '5368324170671202286']]]],
            ['parse_mode' => 'HTML'],
        );

        Http::assertSentCount(2);
        $retry = Http::recorded()[1][0];
        $this->assertSame('Catalog 📩', $retry['caption']);
        $this->assertSame('HTML', $retry['parse_mode']);
        $this->assertArrayNotHasKey('icon_custom_emoji_id', $retry['reply_markup']['inline_keyboard'][0][0]);
    }

    public function test_custom_emoji_rejection_in_photo_caption_retries_without_text_or_button_icons(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;

            if ($calls === 1) {
                return Http::response([
                    'ok' => false,
                    'description' => 'Bad Request: unsupported start tag "tg-emoji"',
                ], 400);
            }

            return Http::response(['ok' => true, 'result' => ['message_id' => 780]], 200);
        });

        app(TelegramBotClient::class)->sendPhoto(
            12345,
            'binary-photo',
            'catalog.png',
            'Catalog <tg-emoji emoji-id="5368324170671202286" data-omp-icon="1">📩</tg-emoji>',
            ['inline_keyboard' => [[
                ['text' => 'ChatGPT', 'icon_custom_emoji_id' => '5368324170671202286'],
                ['text' => 'Sold out', 'icon_custom_emoji_id' => '5368324170671202286', 'disabled' => (object) []],
            ]]],
            ['parse_mode' => 'HTML'],
        );

        Http::assertSentCount(2);
        $retry = Http::recorded()[1][0];
        $fields = collect($retry->data())->keyBy('name');
        $this->assertTrue($retry->isMultipart());
        $this->assertTrue($retry->hasFile('photo', 'binary-photo', 'catalog.png'));
        $this->assertSame('Catalog 📩', $fields['caption']['contents'] ?? null);
        $this->assertSame('HTML', $fields['parse_mode']['contents'] ?? null);
        $markup = json_decode($fields['reply_markup']['contents'] ?? '', flags: JSON_THROW_ON_ERROR);
        $this->assertFalse(isset($markup->inline_keyboard[0][0]->icon_custom_emoji_id));
        $this->assertFalse(isset($markup->inline_keyboard[0][1]->icon_custom_emoji_id));
        $this->assertInstanceOf(\stdClass::class, $markup->inline_keyboard[0][1]->disabled);
        $this->assertEquals((object) [], $markup->inline_keyboard[0][1]->disabled);
    }

    public function test_channel_custom_emoji_rejection_does_not_disable_custom_emoji_for_bot_chats(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;

            if ($calls === 1) {
                return Http::response([
                    'ok' => false,
                    'description' => "Bad Request: custom emoji can't be used in this channel",
                ], 400);
            }

            return Http::response(['ok' => true, 'result' => ['message_id' => 783]], 200);
        });

        $client = app(TelegramBotClient::class);
        $client->sendPhoto(
            '@store_channel',
            'binary-photo',
            'proof.png',
            '<tg-emoji emoji-id="5368324170671202286" data-omp-icon="1">📩</tg-emoji> Order confirmed',
            options: [
                'parse_mode' => 'HTML',
                '_telegram_custom_emoji_scope' => 'channel',
            ],
        );
        $client->sendPhoto(
            '@store_channel',
            'binary-photo-2',
            'proof-2.png',
            '<tg-emoji emoji-id="5368324170671202286" data-omp-icon="1">📩</tg-emoji> Order confirmed',
            options: [
                'parse_mode' => 'HTML',
                '_telegram_custom_emoji_scope' => 'channel',
            ],
        );
        $client->sendMessage(
            12345,
            'Direct <tg-emoji emoji-id="5368324170671202286" data-omp-icon="1">📩</tg-emoji>',
        );

        Http::assertSentCount(4);
        $capability = app(CustomEmojiCapability::class);
        $this->assertTrue(
            $capability->isAvailable(),
            'A channel-specific rejection must not disable custom emoji in supported bot chats.',
        );
        $this->assertFalse($capability->isAvailable(CustomEmojiCapability::SCOPE_CHANNEL));

        $requests = Http::recorded()->all();
        foreach (array_slice($requests, 0, 3) as [$request]) {
            $fields = collect($request->data())->keyBy('name');
            $this->assertFalse($fields->has('_telegram_custom_emoji_scope'));
        }
        $secondChannelFields = collect($requests[2][0]->data())->keyBy('name');
        $this->assertSame('📩 Order confirmed', $secondChannelFields['caption']['contents'] ?? null);
        $this->assertFalse($secondChannelFields->has('caption_entities'));

        $directRequest = $requests[3][0];
        $this->assertSame('Direct 📩', $directRequest['text']);
        $this->assertSame('custom_emoji', $directRequest['entities'][0]['type']);
        $this->assertSame('5368324170671202286', $directRequest['entities'][0]['custom_emoji_id']);
    }

    public function test_concurrent_channel_photo_rejection_uses_channel_capability_scope(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;

            if ($calls === 1) {
                return Http::response([
                    'ok' => false,
                    'description' => "Bad Request: custom emoji can't be used in this channel",
                ], 400);
            }

            return Http::response(['ok' => true, 'result' => ['message_id' => 784]], 200);
        });

        $result = app(TelegramBotClient::class)->sendConcurrently([
            'channel-proof' => [
                'method' => 'sendPhoto',
                'chat_id' => '@store_channel',
                'photo_contents' => 'binary-photo',
                'filename' => 'proof.png',
                'caption' => '<tg-emoji emoji-id="5368324170671202286" data-omp-icon="1">📩</tg-emoji> Order confirmed',
                'options' => [
                    'parse_mode' => 'HTML',
                    TelegramBotClient::CUSTOM_EMOJI_SCOPE_OPTION => CustomEmojiCapability::SCOPE_CHANNEL,
                ],
            ],
        ], 1);

        Http::assertSentCount(2);
        $this->assertIsArray($result['channel-proof']['response']);
        $this->assertTrue(app(CustomEmojiCapability::class)->isAvailable());
        $this->assertFalse(app(CustomEmojiCapability::class)->isAvailable(CustomEmojiCapability::SCOPE_CHANNEL));

        $retry = Http::recorded()[1][0];
        $fields = collect($retry->data())->keyBy('name');
        $this->assertSame('📩 Order confirmed', $fields['caption']['contents'] ?? null);
        $this->assertFalse($fields->has(TelegramBotClient::CUSTOM_EMOJI_SCOPE_OPTION));
    }

    public function test_custom_emoji_rejection_in_photo_reference_caption_falls_back_to_unicode(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;

            if ($calls === 1) {
                return Http::response([
                    'ok' => false,
                    'description' => "Bad Request: can't use custom emoji in this chat",
                ], 400);
            }

            return Http::response(['ok' => true, 'result' => ['message_id' => 781]], 200);
        });

        app(TelegramBotClient::class)->sendPhotoReference(
            12345,
            'telegram-file-id',
            'Catalog <tg-emoji emoji-id="5368324170671202286" data-omp-icon="1">📩</tg-emoji>',
            ['inline_keyboard' => [[['text' => 'ChatGPT', 'icon_custom_emoji_id' => '5368324170671202286']]]],
            ['parse_mode' => 'HTML'],
        );

        Http::assertSentCount(2);
        $retry = Http::recorded()[1][0];
        $this->assertSame('Catalog 📩', $retry['caption']);
        $this->assertSame('HTML', $retry['parse_mode']);
        $this->assertArrayNotHasKey('icon_custom_emoji_id', $retry['reply_markup']['inline_keyboard'][0][0]);
    }

    public function test_rejections_unrelated_to_custom_emoji_are_not_retried(): void
    {
        Http::fake([
            'https://api.telegram.org/*/sendMessage' => Http::response([
                'ok' => false,
                'description' => 'Bad Request: chat not found',
            ], 400),
        ]);

        try {
            app(TelegramBotClient::class)->sendMessage(12345, 'Katalog', [
                'inline_keyboard' => [[['text' => 'ChatGPT', 'icon_custom_emoji_id' => '5368324170671202286']]],
            ]);
            $this->fail('Expected the rejected send to surface.');
        } catch (RequestException $exception) {
            $this->assertSame(400, $exception->response?->status());
        }

        Http::assertSentCount(1);
        $this->assertTrue(app(CustomEmojiCapability::class)->isAvailable());
    }

    public function test_unrelated_send_photo_http_400_is_not_retried_by_the_shared_client(): void
    {
        Http::fake(['https://api.telegram.org/*/sendPhoto' => Http::response([
            'ok' => false,
            'error_code' => 400,
            'description' => 'Bad Request: photo rejected',
        ], 400)]);

        try {
            app(TelegramBotClient::class)->sendPhoto(12345, 'invalid-photo', 'receipt.png', 'Receipt');
            $this->fail('An unrelated Telegram photo rejection should be surfaced.');
        } catch (RequestException $exception) {
            $this->assertSame(400, $exception->response?->status());
        }

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/sendPhoto'));
        $this->assertTrue(app(CustomEmojiCapability::class)->isAvailable());
    }

    public function test_unsuccessful_json_edit_response_is_rejected(): void
    {
        Http::fake([
            'https://api.telegram.org/*/editMessageText' => Http::response([
                'ok' => false,
                'description' => 'Unexpected Telegram failure',
            ]),
        ]);

        $this->expectException(TelegramApiException::class);
        app(TelegramBotClient::class)->editMessageText(12345, 777, 'Invalid response');
    }

    public function test_fake_telegram_client_matches_send_photo_contract(): void
    {
        $response = (new FakeTelegramBotClient)->sendPhoto(12345, 'binary-photo', 'receipt.png', 'Receipt');

        $this->assertTrue($response['ok']);
        $this->assertIsInt($response['result']['message_id']);
        $this->assertSame(12345, $response['result']['chat']['id']);
    }

    public function test_send_document_uses_multipart_payload_and_download_options(): void
    {
        $contents = "email@example.test\npassword=secret-value\n";
        $replyMarkup = ['inline_keyboard' => [[['text' => 'Download', 'callback_data' => 'download']]]];
        $captionEntities = [['type' => 'bold', 'offset' => 0, 'length' => 8]];

        Http::fake(fn () => Http::response([
            'ok' => true,
            'result' => [
                'message_id' => 987654,
                'chat' => ['id' => 12345],
            ],
        ], 200));

        $response = app(TelegramBotClient::class)->sendDocument(
            12345,
            $contents,
            '../lhie store/ORDER:42.txt',
            'Download',
            $replyMarkup,
            [
                'caption_entities' => $captionEntities,
                'protect_content' => false,
            ],
        );

        $this->assertSame(987654, $response['result']['message_id']);

        $requests = Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendDocument'));
        $this->assertCount(1, $requests);
        $request = $requests->sole()[0];

        $this->assertTrue($request->isMultipart());
        $this->assertTrue($request->hasFile('document', $contents, 'lhie-store-ORDER-42.txt'));
        $this->assertSame('12345', (string) $this->multipartField($request, 'chat_id'));
        $this->assertSame('Download', $this->multipartField($request, 'caption'));
        $this->assertSame(
            $captionEntities,
            json_decode((string) $this->multipartField($request, 'caption_entities'), true, 512, JSON_THROW_ON_ERROR),
        );
        $this->assertFalse(filter_var($this->multipartField($request, 'protect_content'), FILTER_VALIDATE_BOOLEAN));
    }

    public function test_send_document_requires_a_positive_message_id_for_successful_response(): void
    {
        Http::fake(fn () => Http::response([
            'ok' => true,
            'result' => ['message_id' => 0],
        ], 200));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('positive integer');

        app(TelegramBotClient::class)->sendDocument(12345, 'document-body', 'download.txt');
    }

    public function test_send_document_transport_failures_preserve_ambiguous_classification_without_body_output(): void
    {
        $contents = 'credential-body-must-not-appear';

        Http::fake([
            'https://api.telegram.org/*/sendDocument' => Http::failedConnection('socket reset'),
        ]);

        try {
            app(TelegramBotClient::class)->sendDocument(12345, $contents, 'download.txt');
            $this->fail('Expected the Telegram document transport failure.');
        } catch (TelegramTransportException $exception) {
            $this->assertStringContainsString('sendDocument', $exception->getMessage());
            $this->assertStringNotContainsString($contents, $exception->getMessage());
        }
    }

    public function test_fake_telegram_client_records_send_document_without_document_contents(): void
    {
        $contents = 'credential-body-must-not-appear';
        $replyMarkup = ['inline_keyboard' => [[['text' => 'Download', 'callback_data' => 'download']]]];
        $options = ['protect_content' => false];
        $client = new FakeTelegramBotClient;

        $response = $client->sendDocument(
            12345,
            $contents,
            '../lhie store/ORDER:42.txt',
            'Download',
            $replyMarkup,
            $options,
        );

        $this->assertTrue($response['ok']);
        $this->assertIsInt($response['result']['message_id']);
        $this->assertGreaterThan(0, $response['result']['message_id']);
        $this->assertSame(12345, $response['result']['chat']['id']);
        $this->assertSame('lhie-store-ORDER-42.txt', $response['result']['document']['file_name']);

        $call = $client->calls[0];
        $this->assertSame('sendDocument', $call['method']);
        $this->assertSame(12345, $call['chat_id']);
        $this->assertSame('lhie-store-ORDER-42.txt', $call['filename']);
        $this->assertSame($options, $call['options']);
        $this->assertSame($response, $call['response']);
        $this->assertStringNotContainsString($contents, json_encode($client->calls, JSON_THROW_ON_ERROR));
    }

    public function test_send_document_rejects_unsuccessful_api_response(): void
    {
        Http::fake(fn () => Http::response([
            'ok' => false,
            'description' => 'Document rejected',
        ], 200));

        $this->expectException(TelegramApiException::class);

        app(TelegramBotClient::class)->sendDocument(12345, 'document-body', 'download.txt');
    }

    public function test_fast_webhook_ack_skips_empty_worker_ack_but_preserves_callback_notification(): void
    {
        Http::fake();
        app(TelegramCallbackAckState::class)->acknowledge('callback-query-id');

        $client = app(TelegramBotClient::class);
        $client->answerCallbackQuery('callback-query-id');
        $client->answerCallbackQuery('callback-query-id', 'Done');

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/answerCallbackQuery')
            && $request['text'] === 'Done');
    }

    public function test_transport_failures_surface_sanitized_client_visible_errors(): void
    {
        config()->set('services.telegram.token', '123456:ABC-issue-114-secret-token');
        config()->set('services.telegram.api_base_url', 'https://api.telegram.org');

        $token = (string) config('services.telegram.token');
        $botApiUrl = "https://api.telegram.org/bot{$token}/sendMessage";
        $botApiPath = "/bot{$token}/";

        Http::fake([
            $botApiUrl => Http::failedConnection(
                "cURL error 28: Operation timed out while requesting {$botApiUrl}; path={$botApiPath}sendMessage"
            ),
        ]);

        try {
            app(TelegramBotClient::class)->sendMessage(12345, 'This will time out');
            $this->fail('Expected the Telegram client to surface the transport failure.');
        } catch (Throwable $exception) {
            $messages = $this->exceptionChainMessages($exception);

            $this->assertNotSame('', $messages, 'The Telegram transport failure should include a safe actionable message.');
            $this->assertStringNotContainsString($token, $messages, 'Exception chain leaked the raw Telegram bot token.');
            $this->assertStringNotContainsString(rawurlencode($token), $messages, 'Exception chain leaked the URL-encoded Telegram bot token.');
            $this->assertStringNotContainsString($botApiUrl, $messages, 'Exception chain leaked the full Telegram Bot API URL.');
            $this->assertStringNotContainsString($botApiPath, $messages, 'Exception chain leaked the raw Telegram bot URL path segment.');
        }
    }

    private function operationForRequest($request): string
    {
        $method = basename(parse_url($request->url(), PHP_URL_PATH) ?: '');

        if ($method === 'sendPhoto' && ! $request->isMultipart()) {
            return 'sendPhotoReference';
        }

        return $method;
    }

    private function multipartField(mixed $request, string $field): mixed
    {
        foreach ($request->data() as $part) {
            if (is_array($part) && ($part['name'] ?? null) === $field) {
                return $part['contents'] ?? null;
            }
        }

        return $request[$field] ?? null;
    }

    private function exceptionChainMessages(Throwable $exception): string
    {
        $messages = [];

        do {
            $messages[] = $exception->getMessage();
            $exception = $exception->getPrevious();
        } while ($exception !== null);

        return implode("\n", $messages);
    }
}
