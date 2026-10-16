<?php

namespace App\Services\CustomerApi;

use App\Models\Customer;
use App\Models\CustomerApiCredential;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class CustomerApiCredentials
{
    public function authenticate(#[\SensitiveParameter] ?string $authorization): ?CustomerApiPrincipal
    {
        if ($authorization === null || preg_match('/\ABearer capi_([a-f0-9]{32})\.([a-f0-9]{64})\z/iD', $authorization, $parts) !== 1) {
            return null;
        }
        // The indexed public selector is case-sensitive, as is the secret.
        $credential = CustomerApiCredential::query()->where('selector', $parts[1])->whereNull('revoked_at')->first();
        if ($credential === null || ! hash_equals($credential->secret_digest, hash('sha256', $parts[2]))) {
            return null;
        }

        return new CustomerApiPrincipal((int) $credential->customer_id, (int) $credential->id);
    }

    public function generate(Customer $customer): IssuedCustomerApiKey
    {
        return DB::transaction(function () use ($customer): IssuedCustomerApiKey {
            $this->lockCustomer($customer);
            if ($this->active($customer) !== null) {
                throw new CustomerApiFailure(409, 'key_exists', 'An active key already exists. Confirm rotation to replace it.');
            }

            return $this->insert($customer);
        }, 3);
    }

    public function prepareRotation(Customer $customer, int $messageId): string
    {
        return DB::transaction(function () use ($customer, $messageId): string {
            $this->lockCustomer($customer);
            $credential = $this->active($customer);
            if ($credential === null) {
                throw new CustomerApiFailure(409, 'stale_key_action', 'The API key changed. Open API management again.');
            }
            $nonce = bin2hex(random_bytes(16));
            $credential->forceFill(['rotation_digest' => hash('sha256', $nonce), 'rotation_message_id' => $messageId, 'rotation_expires_at' => now()->addMinutes(5)])->save();

            return $nonce;
        }, 3);
    }

    public function rotate(Customer $customer, int $messageId, #[\SensitiveParameter] string $confirmation): IssuedCustomerApiKey
    {
        return DB::transaction(function () use ($customer, $messageId, $confirmation): IssuedCustomerApiKey {
            $this->lockCustomer($customer);
            $credential = $this->active($customer);
            if ($credential === null || $credential->rotation_expires_at === null || $credential->rotation_expires_at->isPast()
                || (int) $credential->rotation_message_id !== $messageId || strlen($confirmation) !== 32
                || ! hash_equals((string) $credential->rotation_digest, hash('sha256', $confirmation))) {
                throw new CustomerApiFailure(409, 'stale_key_action', 'Rotation confirmation expired or the API key changed.');
            }
            $credential->forceFill(['revoked_at' => now(), 'rotation_digest' => null, 'rotation_expires_at' => null, 'rotation_message_id' => null])->save();

            return $this->insert($customer);
        }, 3);
    }

    public function prepareDisable(Customer $customer, int $messageId): string
    {
        return DB::transaction(function () use ($customer, $messageId): string {
            $this->lockCustomer($customer);
            $credential = $this->active($customer);
            if ($credential === null) {
                throw new CustomerApiFailure(409, 'stale_key_action', 'The API key changed.');
            }
            $nonce = bin2hex(random_bytes(16));
            $credential->forceFill(['rotation_digest' => hash('sha256', 'disable:'.$nonce), 'rotation_message_id' => $messageId, 'rotation_expires_at' => now()->addMinutes(5)])->save();

            return $nonce;
        }, 3);
    }

    public function disableConfirmed(Customer $customer, int $messageId, #[\SensitiveParameter] string $confirmation): void
    {
        DB::transaction(function () use ($customer, $messageId, $confirmation): void {
            $this->lockCustomer($customer);
            $credential = $this->active($customer);
            if ($credential === null || $credential->rotation_expires_at === null || $credential->rotation_expires_at->isPast()
                || (int) $credential->rotation_message_id !== $messageId || strlen($confirmation) !== 32
                || ! hash_equals((string) $credential->rotation_digest, hash('sha256', 'disable:'.$confirmation))) {
                throw new CustomerApiFailure(409, 'stale_key_action', 'Disable confirmation expired or the API key changed.');
            }
            $credential->forceFill(['revoked_at' => now(), 'rotation_digest' => null, 'rotation_expires_at' => null, 'rotation_message_id' => null])->save();
        }, 3);
    }

    public function disable(Customer $customer): void
    {
        DB::transaction(function () use ($customer): void {
            $this->lockCustomer($customer);
            $credential = $this->active($customer);
            $credential?->forceFill(['revoked_at' => now(), 'rotation_digest' => null, 'rotation_expires_at' => null, 'rotation_message_id' => null])->save();
        }, 3);
    }

    public function summary(Customer $customer): array
    {
        $credential = $this->active($customer);
        $usage = DB::table('customer_api_usage')->where('customer_id', $customer->id)->first();

        return [
            'active' => $credential !== null,
            'masked_identifier' => $credential?->maskedIdentifier(),
            'created_at' => $credential?->created_at?->toIso8601String(),
            'last_used_at' => $usage?->last_used_at === null ? null : CarbonImmutable::parse($usage->last_used_at, 'UTC')->toIso8601String(),
            'account_request_count' => (string) ($usage?->request_count ?? 0),
            'usage_consistency' => 'eventual_lower_bound',
        ];
    }

    private function lockCustomer(Customer $customer): void
    {
        $locked = Customer::query()->whereKey($customer->getKey())->lockForUpdate()->first();
        if ($locked === null || $locked->telegram_id === null) {
            throw new CustomerApiFailure(403, 'not_authorized', 'A registered Telegram Customer is required.');
        }
    }

    private function active(Customer $customer): ?CustomerApiCredential
    {
        return CustomerApiCredential::query()->where('customer_id', $customer->id)->whereNull('revoked_at')->first();
    }

    private function insert(Customer $customer): IssuedCustomerApiKey
    {
        $selector = bin2hex(random_bytes(16));
        $secret = bin2hex(random_bytes(32));
        $credential = new CustomerApiCredential;
        $credential->forceFill(['customer_id' => $customer->id, 'selector' => $selector, 'secret_digest' => hash('sha256', $secret), 'created_at' => now()])->save();

        return new IssuedCustomerApiKey($credential->maskedIdentifier(), 'capi_'.$selector.'.'.$secret);
    }
}
