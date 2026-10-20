<?php

namespace App\Services\Telegram;

use App\Exceptions\TelegramApiException;
use App\Support\DogfoodTiming;
use GuzzleHttp\TransferStats;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramBotClient
{
    public const CUSTOM_EMOJI_SCOPE_OPTION = '_telegram_custom_emoji_scope';

    private readonly TelegramBotApiRequestFactory $requests;

    private readonly TelegramOutboundResponseValidator $responseValidator;

    private readonly CustomEmojiCapability $customEmoji;

    public function __construct(
        ?TelegramBotApiRequestFactory $requests = null,
        ?TelegramOutboundResponseValidator $responseValidator = null,
        ?CustomEmojiCapability $customEmoji = null,
    ) {
        $this->requests = $requests ?? new TelegramBotApiRequestFactory;
        $this->responseValidator = $responseValidator ?? new TelegramOutboundResponseValidator;
        $this->customEmoji = $customEmoji ?? new CustomEmojiCapability;
    }

    public function getChat(int|string $chatId): array
    {
        return $this->timed('getChat', fn () => $this->requests->request()->post($this->requests->url('getChat'), ['chat_id' => $chatId])->throw()->json());
    }

    public function getChatMember(int|string $chatId, int|string $userId): array
    {
        return $this->timed('getChatMember', fn () => $this->requests->request()->post($this->requests->url('getChatMember'), ['chat_id' => $chatId, 'user_id' => $userId])->throw()->json());
    }

    /**
     * @param  list<string>  $customEmojiIds
     * @return array<string, mixed>
     */
    public function getCustomEmojiStickers(array $customEmojiIds): array
    {
        $response = $this->timed('getCustomEmojiStickers', fn () => $this->requests->request()->post($this->requests->url('getCustomEmojiStickers'), [
            'custom_emoji_ids' => json_encode(array_values($customEmojiIds), JSON_THROW_ON_ERROR),
        ])->throw()->json());

        return $this->successfulResponse('getCustomEmojiStickers', $response);
    }

    public function sendMessage(int|string $chatId, string $text, array $replyMarkup = [], array $options = []): array
    {
        $customEmojiScope = $this->customEmojiScope($options);
        $payload = $this->messagePayload($chatId, $text, $replyMarkup, $options);

        return $this->timed('sendMessage', fn () => $this->postWithCustomEmojiFallback(
            'sendMessage',
            'sendMessage',
            $payload,
            wrapFailures: false,
            customEmojiScope: $customEmojiScope,
        ));
    }

    public function editMessageText(int|string $chatId, int $messageId, string $text, array $replyMarkup = [], array $options = []): array
    {
        $payload = array_merge([
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
        ], $options);
        if ($replyMarkup !== []) {
            $payload['reply_markup'] = $replyMarkup;
        }
        $payload = $this->normalizeCustomEmojiText($payload);

        $response = $this->timed('editMessageText', fn () => $this->postWithCustomEmojiFallback('editMessageText', 'editMessageText', $payload, wrapFailures: true));

        return $this->successfulResponse('editMessageText', $response);
    }

    public function editMessageCaption(int|string $chatId, int $messageId, string $caption, array $replyMarkup = [], array $options = []): array
    {
        $payload = array_merge([
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'caption' => $caption,
        ], $options);
        if ($replyMarkup !== []) {
            $payload['reply_markup'] = $replyMarkup;
        }
        $payload = $this->normalizeCustomEmojiText($payload);
        if (isset($payload['caption_entities']) && is_array($payload['caption_entities'])) {
            $payload['caption_entities'] = json_encode($payload['caption_entities'], JSON_THROW_ON_ERROR);
        }

        $response = $this->timed('editMessageCaption', fn () => $this->postWithCustomEmojiFallback('editMessageCaption', 'editMessageCaption', $payload, wrapFailures: true));

        return $this->successfulResponse('editMessageCaption', $response);
    }

    public function editMessageReplyMarkup(
        int|string $chatId,
        int $messageId,
        array $replyMarkup = ['inline_keyboard' => []],
        array $options = [],
    ): array {
        $payload = array_merge([
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'reply_markup' => $replyMarkup,
        ], $options);
        $payload = $this->normalizeCustomEmojiText($payload);

        $response = $this->timed('editMessageReplyMarkup', fn () => $this->postWithCustomEmojiFallback('editMessageReplyMarkup', 'editMessageReplyMarkup', $payload, wrapFailures: true));

        return $this->successfulResponse('editMessageReplyMarkup', $response);
    }

    public function editMessageTextOrSend(
        int|string $chatId,
        string $text,
        array $replyMarkup = [],
        ?int $messageId = null,
        array $options = [],
    ): void {
        if ($messageId === null || $messageId < 1) {
            $this->sendMessage($chatId, $text, $replyMarkup, $options);

            return;
        }

        try {
            $this->editMessageText($chatId, $messageId, $text, $replyMarkup, $options);
        } catch (TelegramApiException $exception) {
            if ($exception->isMessageNotModified()) {
                return;
            }
            if (! $exception->isDefiniteEditFailure()) {
                throw $exception;
            }

            $this->sendMessage($chatId, $text, $replyMarkup, $options);
        }
    }

    public function editMessageCaptionOrSend(
        int|string $chatId,
        string $caption,
        array $replyMarkup = [],
        ?int $messageId = null,
        array $options = [],
    ): void {
        if ($messageId === null || $messageId < 1) {
            $this->sendMessage($chatId, $caption, $replyMarkup, $options);

            return;
        }

        try {
            $this->editMessageCaption($chatId, $messageId, $caption, $replyMarkup, $options);
        } catch (TelegramApiException $exception) {
            if ($exception->isMessageNotModified()) {
                return;
            }
            if (! $exception->isDefiniteEditFailure()) {
                throw $exception;
            }

            $this->sendMessage($chatId, $caption, $replyMarkup, $options);
        }
    }

    public function sendPhoto(int|string $chatId, string $photoContents, string $filename, ?string $caption = null, array $replyMarkup = [], array $options = []): array
    {
        $customEmojiScope = $this->customEmojiScope($options);
        $payload = $this->photoPayload($chatId, $caption, $replyMarkup, $options);

        $response = $this->timed('sendPhoto', fn () => $this->postWithCustomEmojiFallback(
            'sendPhoto',
            'sendPhoto',
            $payload,
            wrapFailures: false,
            attachment: ['field' => 'photo', 'contents' => $photoContents, 'filename' => $filename],
            customEmojiScope: $customEmojiScope,
        ));
        if (($response['ok'] ?? false) !== true) {
            throw new \RuntimeException('Telegram Bot API sendPhoto response was not successful.');
        }

        return $response;
    }

    /**
     * @param  array<string, array<string, mixed>>  $operations
     * @return array<string, array{response:array<string, mixed>|Throwable, duration_ms:float}>
     */
    public function sendConcurrently(array $operations, int $concurrency): array
    {
        if ($operations === []) {
            return [];
        }

        $startedAt = [];
        $durations = [];
        $responses = $this->requests->request()->pool(
            function (Pool $pool) use ($operations, &$startedAt, &$durations): void {
                foreach ($operations as $key => $operation) {
                    $startedAt[$key] = DogfoodTiming::now();
                    $request = $this->requests->poolRequest($pool, (string) $key);
                    $request->withOptions([
                        'on_stats' => function (TransferStats $stats) use (&$durations, $key): void {
                            $durations[$key] = round($stats->getTransferTime() * 1000, 2);
                        },
                    ]);

                    if ($operation['method'] === 'sendMessage') {
                        $options = $operation['options'] ?? [];
                        $scope = $this->customEmojiScope($options);
                        $payload = $this->messagePayload(
                            $operation['chat_id'],
                            $operation['text'],
                            $operation['reply_markup'] ?? [],
                            $options,
                        );
                        $payload = $this->withoutCustomEmojiWhenUnavailable($payload, $scope);
                        $request->post(
                            $this->requests->url('sendMessage'),
                            $payload,
                        );

                        continue;
                    }

                    if ($operation['method'] !== 'sendPhoto') {
                        throw new \InvalidArgumentException('Unsupported Telegram batch method.');
                    }

                    $options = $operation['options'] ?? [];
                    $scope = $this->customEmojiScope($options);
                    $payload = $this->photoPayload(
                        $operation['chat_id'],
                        $operation['caption'] ?? null,
                        $operation['reply_markup'] ?? [],
                        $options,
                    );
                    $payload = $this->withoutCustomEmojiWhenUnavailable($payload, $scope);
                    $request->attach('photo', $operation['photo_contents'], $operation['filename'])
                        ->post($this->requests->url('sendPhoto'), $payload);
                }
            },
            concurrency: min(max(1, $concurrency), count($operations)),
        );

        $results = [];
        foreach ($operations as $key => $operation) {
            $response = $responses[$key] ?? new \RuntimeException('Telegram batch returned no response.');

            try {
                $outcome = $this->normalizeConcurrentResponse($operation, $response);
            } catch (Throwable $exception) {
                $outcome = $exception instanceof ConnectionException
                    ? $this->requests->transportException($operation['method'], $exception)
                    : $exception;
            }

            $duration = $durations[$key] ?? DogfoodTiming::elapsedMs($startedAt[$key]);
            $results[$key] = ['response' => $outcome, 'duration_ms' => $duration];
            DogfoodTiming::log('dogfood.outbound_http', [
                'provider' => 'telegram',
                'operation' => $operation['method'],
                'status' => $response instanceof Response
                    ? $response->status()
                    : ($response instanceof RequestException ? $response->response?->status() : null),
                'duration_ms' => $duration,
            ]);
        }

        return $results;
    }

    public function sendDocument(
        int|string $chatId,
        string $documentContents,
        string $filename,
        ?string $caption = null,
        array $replyMarkup = [],
        array $options = [],
    ): array {
        $customEmojiScope = $this->customEmojiScope($options);
        $payload = array_merge($options, ['chat_id' => $chatId]);
        unset($payload['document']);
        unset($payload[self::CUSTOM_EMOJI_SCOPE_OPTION]);

        if ($caption !== null && $caption !== '') {
            $payload['caption'] = $caption;
        } else {
            unset($payload['caption']);
        }
        if ($replyMarkup !== []) {
            $payload['reply_markup'] = $replyMarkup;
        } else {
            unset($payload['reply_markup']);
        }
        $payload = $this->normalizeCustomEmojiText($payload);
        $payload = $this->withoutCustomEmojiWhenUnavailable($payload, $customEmojiScope);
        if (isset($payload['caption_entities']) && is_array($payload['caption_entities'])) {
            $payload['caption_entities'] = json_encode($payload['caption_entities'], JSON_THROW_ON_ERROR);
        }
        if (is_array($payload['reply_markup'] ?? null)) {
            $payload['reply_markup'] = json_encode($payload['reply_markup'], JSON_THROW_ON_ERROR);
        }

        $response = $this->timed(
            'sendDocument',
            fn () => $this->postWithCustomEmojiFallback(
                'sendDocument',
                'sendDocument',
                $payload,
                wrapFailures: false,
                attachment: [
                    'field' => 'document',
                    'contents' => $documentContents,
                    'filename' => $this->sanitizeDocumentFilename($filename),
                ],
                customEmojiScope: $customEmojiScope,
            ),
        );
        $response = $this->successfulResponse('sendDocument', $response);
        $this->responseValidator->messageIdFromResponse($response, 'Telegram sendDocument');

        return $response;
    }

    public function sendPhotoReference(int|string $chatId, string $photoFileId, ?string $caption = null, array $replyMarkup = [], array $options = []): array
    {
        $customEmojiScope = $this->customEmojiScope($options);
        $payload = array_merge(['chat_id' => $chatId, 'photo' => $photoFileId], $options);
        unset($payload[self::CUSTOM_EMOJI_SCOPE_OPTION]);

        if ($caption !== null && $caption !== '') {
            $payload['caption'] = $caption;
        }

        if ($replyMarkup !== []) {
            $payload['reply_markup'] = $replyMarkup;
        }
        $payload = $this->normalizeCustomEmojiText($payload);
        $payload = $this->withoutCustomEmojiWhenUnavailable($payload, $customEmojiScope);

        $response = $this->timed('sendPhotoReference', fn () => $this->postWithCustomEmojiFallback(
            'sendPhotoReference',
            'sendPhoto',
            $payload,
            wrapFailures: false,
            customEmojiScope: $customEmojiScope,
        ));
        if (($response['ok'] ?? false) !== true) {
            throw new \RuntimeException('Telegram Bot API sendPhoto response was not successful.');
        }

        return $response;
    }

    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null): void
    {
        // The webhook response already stops Telegram's spinner. Keep a later
        // text/alert answer because handlers use it for user-visible feedback.
        if ($text === null && app(TelegramCallbackAckState::class)->isAcknowledged($callbackQueryId)) {
            return;
        }

        try {
            $this->timed('answerCallbackQuery', fn () => $this->requests->request()->post($this->requests->url('answerCallbackQuery'), array_filter([
                'callback_query_id' => $callbackQueryId,
                'text' => $text === null ? null : $this->customEmoji->stripTextCustomEmoji($text),
                'show_alert' => $text !== null,
            ], fn ($value) => $value !== null))->throw());
        } catch (RequestException $e) {
            Log::warning('telegram.answer_callback_query_failed', [
                'callback_query_correlation_id' => DogfoodTiming::correlation($callbackQueryId),
                'status' => $e->response?->status(),
                'description' => TelegramBotApiRedactor::redactText((string) data_get($e->response?->json(), 'description')),
            ]);
        }
    }

    public function deleteMessage(int|string $chatId, int|string $messageId): void
    {
        $this->timed('deleteMessage', fn () => $this->requests->request()->post($this->requests->url('deleteMessage'), ['chat_id' => $chatId, 'message_id' => $messageId])->throw());
    }

    public function pinChatMessage(
        int|string $chatId,
        int|string $messageId,
        bool $disableNotification = true,
    ): array {
        $payload = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'disable_notification' => $disableNotification,
        ];

        try {
            $response = $this->timed(
                'pinChatMessage',
                fn () => $this->requests->request()->post($this->requests->url('pinChatMessage'), $payload)->throw()->json(),
            );
        } catch (RequestException $exception) {
            throw TelegramApiException::fromRequest('pinChatMessage', $exception);
        }

        return $this->successfulResponse('pinChatMessage', $response);
    }

    protected function sanitizeDocumentFilename(string $filename): string
    {
        $sanitized = preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename) ?? '';
        $sanitized = preg_replace('/\.{2,}/', '.', $sanitized) ?? '';
        $sanitized = trim($sanitized, '.-');

        return $sanitized !== '' ? $sanitized : 'document';
    }

    /** @return array<string, mixed> */
    private function messagePayload(int|string $chatId, string $text, array $replyMarkup, array $options): array
    {
        $payload = array_merge(['chat_id' => $chatId, 'text' => $text], $options);
        unset($payload[self::CUSTOM_EMOJI_SCOPE_OPTION]);
        if ($replyMarkup !== []) {
            $payload['reply_markup'] = $replyMarkup;
        }

        return $this->normalizeCustomEmojiText($payload);
    }

    private function defaultInlineButtonStyles(array|string $replyMarkup): array|string
    {
        $markup = is_string($replyMarkup) ? json_decode($replyMarkup) : $replyMarkup;
        $rows = is_array($markup) ? ($markup['inline_keyboard'] ?? null) : ($markup->inline_keyboard ?? null);
        if (! is_array($rows)) {
            return $replyMarkup;
        }

        foreach ($rows as $rowIndex => $row) {
            foreach ($row as $buttonIndex => $button) {
                if (is_array($markup)) {
                    $markup['inline_keyboard'][$rowIndex][$buttonIndex]['style'] ??= 'primary';
                } else {
                    $markup->inline_keyboard[$rowIndex][$buttonIndex]->style ??= 'primary';
                }
            }
        }

        return is_string($replyMarkup) ? json_encode($markup, JSON_THROW_ON_ERROR) : $markup;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeCustomEmojiText(array $payload): array
    {
        if (($payload['parse_mode'] ?? null) === null) {
            unset($payload['parse_mode']);
        }
        if (is_array($payload['reply_markup'] ?? null) || is_string($payload['reply_markup'] ?? null)) {
            $payload['reply_markup'] = $this->customEmoji->iconsFromReplyMarkup($payload['reply_markup']);
            $payload['reply_markup'] = $this->defaultInlineButtonStyles($payload['reply_markup']);
        }
        if (isset($payload['parse_mode'])) {
            foreach (['text', 'caption'] as $field) {
                if (is_string($payload[$field] ?? null)) {
                    $payload[$field] = $this->customEmoji->canonicalizeTrustedCustomEmojiHtml($payload[$field]);
                }
            }

            return $payload;
        }

        foreach (['text' => 'entities', 'caption' => 'caption_entities'] as $field => $entitiesField) {
            if (! is_string($payload[$field] ?? null) || ! str_contains($payload[$field], '<tg-emoji')) {
                continue;
            }

            $entities = $payload[$entitiesField] ?? [];
            if (is_string($entities)) {
                $entities = json_decode($entities, true, flags: JSON_THROW_ON_ERROR);
            }
            $entities = is_array($entities) ? $entities : [];
            $rendered = $this->customEmoji->textAndEntities($payload[$field], $entities);
            $payload[$field] = $rendered['text'];
            $payload[$entitiesField] = $rendered['entities'];
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    private function photoPayload(int|string $chatId, ?string $caption, array $replyMarkup, array $options): array
    {
        $payload = array_merge(['chat_id' => $chatId], $options);
        unset($payload[self::CUSTOM_EMOJI_SCOPE_OPTION]);

        if ($caption !== null && $caption !== '') {
            $payload['caption'] = $caption;
        }
        if ($replyMarkup !== []) {
            $payload['reply_markup'] = $replyMarkup;
        }
        $payload = $this->normalizeCustomEmojiText($payload);
        if (isset($payload['caption_entities']) && is_array($payload['caption_entities'])) {
            $payload['caption_entities'] = json_encode($payload['caption_entities'], JSON_THROW_ON_ERROR);
        }
        if (is_array($payload['reply_markup'] ?? null)) {
            $payload['reply_markup'] = json_encode($payload['reply_markup'], JSON_THROW_ON_ERROR);
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    private function normalizeConcurrentResponse(array $operation, mixed $response): array
    {
        $method = $operation['method'];
        $options = $operation['options'] ?? [];
        $customEmojiScope = $this->customEmojiScope($options);
        if ($response instanceof ConnectionException) {
            throw $this->requests->transportException($method, $response);
        }
        if ($response instanceof Throwable) {
            throw $response;
        }
        if (! $response instanceof Response) {
            throw new \RuntimeException('Telegram API batch returned an invalid response.');
        }

        try {
            $response->throw();
        } catch (RequestException $exception) {
            if ($method !== 'sendMessage'
                && ! TelegramApiException::fromRequest($method, $exception)->isCustomEmojiRejected()) {
                throw $exception;
            }

            return $this->retryWithoutCustomEmoji(
                $method,
                $method,
                $method === 'sendPhoto'
                    ? $this->photoPayload(
                        $operation['chat_id'],
                        $operation['caption'] ?? null,
                        $operation['reply_markup'] ?? [],
                        $options,
                    )
                    : $this->messagePayload(
                        $operation['chat_id'],
                        $operation['text'],
                        $operation['reply_markup'] ?? [],
                        $options,
                    ),
                $exception,
                wrapFailures: false,
                attachment: $method === 'sendPhoto'
                    ? ['field' => 'photo', 'contents' => $operation['photo_contents'], 'filename' => $operation['filename']]
                    : null,
                customEmojiScope: $customEmojiScope,
            );
        }

        $body = $response->json();
        if (! is_array($body)) {
            throw new \RuntimeException('Telegram API batch returned an invalid response body.');
        }
        if ($method === 'sendPhoto' && ($body['ok'] ?? false) !== true) {
            throw new \RuntimeException('Telegram Bot API sendPhoto response was not successful.');
        }

        return $body;
    }

    /**
     * Custom emoji entities and inline keyboard button icons require Telegram
     * to allow this bot to use them. A rejected send is retried once without
     * custom emoji, and capability remains disabled for later sends in that
     * destination scope.
     *
     * @param  array<string, mixed>  $payload
     * @param  array{field: string, contents: string, filename: string}|null  $attachment
     * @return array<string, mixed>
     */
    private function postWithCustomEmojiFallback(
        string $operation,
        string $method,
        array $payload,
        bool $wrapFailures,
        ?array $attachment = null,
        string $customEmojiScope = CustomEmojiCapability::SCOPE_MESSAGES,
    ): array {
        $payload = $this->withoutCustomEmojiWhenUnavailable($payload, $customEmojiScope);

        try {
            return $this->post($method, $payload, $attachment);
        } catch (RequestException $exception) {
            return $this->retryWithoutCustomEmoji(
                $operation,
                $method,
                $payload,
                $exception,
                $wrapFailures,
                $attachment,
                $customEmojiScope,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array{field: string, contents: string, filename: string}|null  $attachment
     * @return array<string, mixed>
     */
    private function retryWithoutCustomEmoji(
        string $operation,
        string $method,
        array $payload,
        RequestException $exception,
        bool $wrapFailures,
        ?array $attachment = null,
        string $customEmojiScope = CustomEmojiCapability::SCOPE_MESSAGES,
    ): array {
        $apiException = TelegramApiException::fromRequest($operation, $exception);
        if (! $apiException->isCustomEmojiRejected()) {
            throw $wrapFailures ? $apiException : $exception;
        }

        // Telegram rejected this request before creating a message, so retrying
        // without the icons cannot duplicate a customer-visible delivery.
        $this->customEmoji->markUnavailable($apiException->description, $customEmojiScope);

        try {
            return $this->post($method, $this->withoutCustomEmojiMarkup($payload), $attachment);
        } catch (RequestException $retryException) {
            throw $wrapFailures ? TelegramApiException::fromRequest($operation, $retryException) : $retryException;
        }
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function customEmojiScope(array $options): string
    {
        return ($options[self::CUSTOM_EMOJI_SCOPE_OPTION] ?? null) === CustomEmojiCapability::SCOPE_CHANNEL
            ? CustomEmojiCapability::SCOPE_CHANNEL
            : CustomEmojiCapability::SCOPE_MESSAGES;
    }

    /** @param array<string, mixed> $payload */
    private function withoutCustomEmojiWhenUnavailable(array $payload, string $scope): array
    {
        return $this->customEmoji->isAvailable($scope)
            ? $payload
            : $this->withoutCustomEmojiMarkup($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withoutCustomEmojiMarkup(array $payload): array
    {
        $isHtml = ($payload['parse_mode'] ?? null) === 'HTML';
        foreach (['text' => 'entities', 'caption' => 'caption_entities'] as $field => $entityField) {
            if (! is_string($payload[$field] ?? null)) {
                continue;
            }
            if ($isHtml) {
                $payload[$field] = $this->customEmoji->stripHtmlCustomEmoji($payload[$field]);

                continue;
            }
            $entities = $payload[$entityField] ?? [];
            $encodedEntities = is_string($entities);
            if ($encodedEntities) {
                $entities = json_decode($entities, true, flags: JSON_THROW_ON_ERROR);
            }
            $rendered = $this->customEmoji->textAndEntities($payload[$field], $entities);
            $payload[$field] = $rendered['text'];
            if (isset($payload[$entityField]) || $rendered['entities'] !== []) {
                $payload[$entityField] = $encodedEntities
                    ? json_encode($rendered['entities'], JSON_THROW_ON_ERROR)
                    : $rendered['entities'];
            }
        }

        foreach (['entities', 'caption_entities'] as $field) {
            if (is_array($payload[$field] ?? null) || is_string($payload[$field] ?? null)) {
                $payload[$field] = $this->customEmoji->stripTextCustomEmojiEntities($payload[$field]);
            }
        }

        $replyMarkup = $payload['reply_markup'] ?? null;
        if (is_array($replyMarkup)) {
            $payload['reply_markup'] = $this->customEmoji->stripIcons(
                $this->customEmoji->stripTextCustomEmojiFromReplyMarkup($replyMarkup),
            );
        } elseif (is_string($replyMarkup)) {
            $payload['reply_markup'] = $this->customEmoji->stripTextCustomEmojiFromReplyMarkup(
                $this->customEmoji->stripEncodedIcons($replyMarkup),
            );
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array{field: string, contents: string, filename: string}|null  $attachment
     * @return array<string, mixed>
     */
    private function post(string $method, array $payload, ?array $attachment): array
    {
        $request = $this->requests->request();
        if ($attachment !== null) {
            $request = $request->attach($attachment['field'], $attachment['contents'], $attachment['filename']);
        }

        return $request->post($this->requests->url($method), $payload)->throw()->json();
    }

    /**
     * @return array<string, mixed>
     */
    private function successfulResponse(string $operation, mixed $response): array
    {
        if (! is_array($response) || ($response['ok'] ?? false) !== true) {
            throw TelegramApiException::fromResponse($operation, $response);
        }

        return $response;
    }

    private function timed(string $operation, callable $callback): mixed
    {
        $startedAt = DogfoodTiming::now();
        $status = null;

        try {
            $result = $callback();
            $status = is_object($result) && method_exists($result, 'status') ? $result->status() : 200;

            return $result;
        } catch (ConnectionException $e) {
            throw $this->requests->transportException($operation, $e);
        } catch (RequestException $e) {
            $status = $e->response?->status();
            throw $e;
        } finally {
            DogfoodTiming::log('dogfood.outbound_http', [
                'provider' => 'telegram',
                'operation' => $operation,
                'status' => $status,
                'duration_ms' => DogfoodTiming::elapsedMs($startedAt),
            ]);
        }
    }
}
