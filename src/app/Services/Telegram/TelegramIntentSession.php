<?php

namespace App\Services\Telegram;

use App\Models\Customer;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class TelegramIntentSession
{
    private const TTL_MINUTES = 15;

    public function key(Customer $customer): string
    {
        return 'telegram:product-selection:'.$customer->telegram_id;
    }

    public function lockKey(Customer $customer): string
    {
        return 'telegram:product-selection-lock:'.$customer->telegram_id;
    }

    /**
     * @return array{
     *     productId: int,
     *     quantity: int,
     *     interactionToken: string,
     *     locale: string,
     *     mode: 'browsing'|'awaiting_custom_quantity',
     *     previousQuantity?: int,
     *     familyId?: int,
     *     familyPage?: int,
     *     familyListPage?: int,
     *     familyOriginToken?: string
     * }|null
     */
    public function get(Customer $customer): ?array
    {
        $state = Cache::get($this->key($customer));
        if (! is_array($state)
            || ! isset($state['productId'], $state['quantity'], $state['interactionToken'], $state['locale'], $state['mode'])
            || ! is_int($state['productId'])
            || $state['productId'] < 1
            || ! is_int($state['quantity'])
            || $state['quantity'] < 1
            || ! is_string($state['interactionToken'])
            || preg_match('/\A[a-f0-9]{16}\z/D', $state['interactionToken']) !== 1
            || ! is_string($state['locale'])
            || ! in_array($state['locale'], TelegramTranslationCatalog::SUPPORTED_LOCALES, true)
            || ! in_array($state['mode'], ['browsing', 'awaiting_custom_quantity'], true)
            || ($state['mode'] === 'awaiting_custom_quantity'
                && (! isset($state['previousQuantity'])
                    || ! is_int($state['previousQuantity'])
                    || $state['previousQuantity'] < 1
                    || $state['previousQuantity'] !== $state['quantity']))
            || (isset($state['familyId']) && (! is_int($state['familyId']) || $state['familyId'] < 1))
            || (isset($state['familyPage']) && (! is_int($state['familyPage']) || $state['familyPage'] < 1))
            || (isset($state['familyListPage']) && (! is_int($state['familyListPage']) || $state['familyListPage'] < 1))
            || (isset($state['familyOriginToken']) && (
                ! is_string($state['familyOriginToken'])
                || preg_match('/\A[a-f0-9]{16}\z/D', $state['familyOriginToken']) !== 1
                || ! isset($state['familyId'])
            ))
            || (isset($state['familyListPage']) && ! isset($state['familyId']))
            || (isset($state['familyId']) !== isset($state['familyPage']))
        ) {
            if ($state !== null) {
                $this->forget($customer);
            }

            return null;
        }

        if ($state['mode'] === 'browsing') {
            unset($state['previousQuantity']);
        }

        return $state;
    }

    /**
     * @param  array{familyId?: int, familyPage?: int, familyListPage?: int, familyOriginToken?: string}  $context
     * @return array{productId: int, quantity: int, interactionToken: string, locale: string, mode: 'browsing', familyId?: int, familyPage?: int, familyListPage?: int, familyOriginToken?: string}
     */
    public function start(Customer $customer, int $productId, int $quantity, string $locale, array $context = []): array
    {
        if ($productId < 1) {
            throw new RuntimeException('Telegram product selection product must be positive.');
        }

        if ($quantity < 1) {
            throw new RuntimeException('Telegram product selection quantity must be positive.');
        }

        $state = [
            'productId' => $productId,
            'quantity' => $quantity,
            'interactionToken' => $this->newInteractionToken(),
            'locale' => in_array($locale, TelegramTranslationCatalog::SUPPORTED_LOCALES, true)
                ? $locale
                : TelegramLocale::DEFAULT_LOCALE,
            'mode' => 'browsing',
            ...$this->navigationContext($context),
        ];
        $this->put($customer, $state);

        return $state;
    }

    /**
     * @return array{productId: int, quantity: int, interactionToken: string, locale: string, mode: 'awaiting_custom_quantity', previousQuantity: int, familyId?: int, familyPage?: int, familyListPage?: int, familyOriginToken?: string}
     */
    public function awaitCustomQuantity(Customer $customer, array $state, string $locale): array
    {
        $awaiting = [
            'productId' => (int) $state['productId'],
            'quantity' => (int) $state['quantity'],
            'interactionToken' => $this->newInteractionToken(),
            'locale' => $locale,
            'mode' => 'awaiting_custom_quantity',
            'previousQuantity' => (int) $state['quantity'],
            ...$this->navigationContext($state),
        ];
        $this->put($customer, $awaiting);

        return $awaiting;
    }

    /**
     * @return array{productId: int, quantity: int, interactionToken: string, locale: string, mode: 'browsing', familyId?: int, familyPage?: int, familyListPage?: int, familyOriginToken?: string}
     */
    public function browsing(Customer $customer, array $state, int $quantity, string $locale): array
    {
        return $this->start($customer, (int) $state['productId'], $quantity, $locale, $this->navigationContext($state));
    }

    public function put(Customer $customer, array $state): void
    {
        Cache::put($this->key($customer), $state, now()->addMinutes(self::TTL_MINUTES));
    }

    public function withLock(Customer $customer, callable $callback): mixed
    {
        return Cache::lock($this->lockKey($customer), 15)->block(5, $callback);
    }

    public function forgetWithLock(Customer $customer): void
    {
        $this->withLock($customer, fn (): null => $this->forget($customer));
    }

    public function forget(Customer $customer): void
    {
        Cache::forget($this->key($customer));
        // Remove scalar state written before the tokenized flow cutover.
        Cache::forget('telegram:selected-product:'.$customer->telegram_id);
    }

    /** @return array{familyId?: int, familyPage?: int, familyListPage?: int, familyOriginToken?: string} */
    private function navigationContext(array $context): array
    {
        $familyId = $context['familyId'] ?? null;
        $familyPage = $context['familyPage'] ?? null;
        $familyListPage = $context['familyListPage'] ?? 1;
        if (! is_int($familyId) || $familyId < 1 || ! is_int($familyPage) || $familyPage < 1
            || ! is_int($familyListPage) || $familyListPage < 1) {
            return [];
        }

        $navigation = [
            'familyId' => $familyId,
            'familyPage' => $familyPage,
            'familyListPage' => $familyListPage,
        ];
        if (is_string($context['familyOriginToken'] ?? null)
            && preg_match('/\A[a-f0-9]{16}\z/D', $context['familyOriginToken']) === 1) {
            $navigation['familyOriginToken'] = $context['familyOriginToken'];
        }

        return $navigation;
    }

    private function newInteractionToken(): string
    {
        return bin2hex(random_bytes(8));
    }
}
