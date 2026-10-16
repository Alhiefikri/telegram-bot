<?php

namespace App\Services\Telegram;

use App\Models\Customer;
use App\Models\TelegramNavigationRoot;
use App\Services\CustomerApi\CustomerApiAdmission;
use App\Services\CustomerApi\CustomerApiCredentials;
use App\Services\CustomerApi\CustomerApiFailure;
use App\Services\CustomerApi\IssuedCustomerApiKey;
use Illuminate\Support\Facades\DB;

/** Only trusted Telegram update facts belong at this boundary, never client-supplied HTTP identity. */
final class TelegramCustomerApiActions
{
    public function __construct(private CustomerApiCredentials $credentials, private CustomerApiAdmission $admission) {}

    public function generate(string $actorId, string $chatId, string $chatType, int $messageId): IssuedCustomerApiKey
    {
        $customer = $this->customer($actorId, $chatId, $chatType, $messageId);
        $this->admission->keyOperation($customer->id);

        return $this->withCurrentRoot($customer, $chatId, $messageId, fn () => $this->credentials->generate($customer));
    }

    public function prepareRotation(string $actorId, string $chatId, string $chatType, int $messageId): string
    {
        $customer = $this->customer($actorId, $chatId, $chatType, $messageId);
        $this->admission->keyOperation($customer->id, confirmation: true);

        return $this->withCurrentRoot($customer, $chatId, $messageId, fn () => $this->credentials->prepareRotation($customer, $messageId));
    }

    public function rotate(string $actorId, string $chatId, string $chatType, int $messageId, #[\SensitiveParameter] string $confirmation): IssuedCustomerApiKey
    {
        $customer = $this->customer($actorId, $chatId, $chatType, $messageId);
        $this->admission->keyOperation($customer->id);

        return $this->withCurrentRoot($customer, $chatId, $messageId, fn () => $this->credentials->rotate($customer, $messageId, $confirmation));
    }

    public function disable(string $actorId, string $chatId, string $chatType, int $messageId): void
    {
        $customer = $this->customer($actorId, $chatId, $chatType, $messageId);
        $this->admission->keyOperation($customer->id, disable: true);
        $this->withCurrentRoot($customer, $chatId, $messageId, fn () => $this->credentials->disable($customer));
    }

    public function summary(string $actorId, string $chatId, string $chatType, int $messageId): array
    {
        return $this->credentials->summary($this->customer($actorId, $chatId, $chatType, $messageId));
    }

    private function withCurrentRoot(Customer $customer, string $chatId, int $messageId, \Closure $operation): mixed
    {
        return DB::transaction(function () use ($customer, $chatId, $messageId, $operation): mixed {
            $locked = Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            if ((string) $locked->telegram_id !== $chatId) {
                throw new CustomerApiFailure(403, 'not_authorized', 'The Store Bot identity changed.');
            }
            $root = TelegramNavigationRoot::query()->where('customer_id', $customer->id)
                ->where('chat_id', $chatId)->where('message_id', $messageId)->sharedLock()->first();
            if ($root === null) {
                throw new CustomerApiFailure(403, 'not_authorized', 'A current private Store Bot conversation is required.');
            }

            return $operation();
        }, 3);
    }

    private function customer(string $actorId, string $chatId, string $chatType, int $messageId): Customer
    {
        if ($chatType !== 'private' || $actorId !== $chatId || $messageId < 1
            || preg_match('/\A[1-9][0-9]{0,18}\z/D', $actorId) !== 1
            || (strlen($actorId) === 19 && strcmp($actorId, (string) PHP_INT_MAX) > 0)) {
            throw new CustomerApiFailure(403, 'not_authorized', 'A current private Store Bot conversation is required.');
        }
        $customer = Customer::query()->where('telegram_id', $actorId)->first();
        if ($customer === null || (string) $customer->telegram_id !== $actorId
            || ! TelegramNavigationRoot::query()->where('customer_id', $customer->id)->where('chat_id', $chatId)->where('message_id', $messageId)->exists()) {
            throw new CustomerApiFailure(403, 'not_authorized', 'A current private Store Bot conversation is required.');
        }

        return $customer;
    }
}
