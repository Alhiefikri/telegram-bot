<?php

namespace App\Services\Customer;

use App\Enums\CustomerAuthTokenPurpose;
use App\Jobs\SendCustomerAuthMail;
use App\Models\Customer;
use App\Models\CustomerAuthMailWork;
use App\Models\CustomerAuthToken;
use App\Notifications\CustomerAuthMailNotification;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Throwable;

final class CustomerAuthMailDeliveryService
{
    public function persist(CustomerAuthToken $token, #[\SensitiveParameter] string $plainToken): void
    {
        $work = new CustomerAuthMailWork;
        $work->forceFill([
            'auth_token_id' => $token->getKey(),
            'payload' => ['email' => $token->email, 'token' => $plainToken],
            'status' => 'pending',
            'expires_at' => $token->expires_at,
            'next_attempt_at' => now(),
        ])->save();
        DB::afterCommit(fn () => $this->dispatch((int) $work->getKey()));
    }

    public function deliver(int $workId): void
    {
        try {
            $claim = $this->claim($workId);
            if ($claim === null) {
                return;
            }
            $work = $claim['work'];
            try {
                $payload = $work->payload;
            } catch (Throwable) {
                $this->finish($work, ['status' => 'failed', 'payload' => null, 'last_error_code' => 'invalid_payload']);

                return;
            }
            if (! is_array($payload)
                || ! is_string($payload['token'] ?? null)
                || ! preg_match('/\A[0-9a-f]{64}\z/', $payload['token'])
                || ($payload['email'] ?? null) !== $claim['email']
                || ! hash_equals($claim['token_hash'], hash('sha256', $payload['token']))) {
                $this->finish($work, ['status' => 'failed', 'payload' => null, 'last_error_code' => 'invalid_payload']);

                return;
            }
            if (! $this->eligibleBeforeSend($work)) {
                return;
            }
            try {
                // Reset may revoke this token during SMTP; token consumption remains version-fenced.
                Notification::route('mail', $payload['email'])->notifyNow(new CustomerAuthMailNotification($claim['purpose'], $payload['token']));
            } catch (Throwable) {
                $terminal = $work->attempts >= 5;
                $failure = [
                    'status' => $terminal ? 'failed' : 'pending',
                    'next_attempt_at' => now()->addSeconds(match ($work->attempts) { 1 => 60, 2 => 120, default => 300 }),
                    'last_error_code' => 'mail_delivery_failed',
                ];
                if ($terminal) {
                    $failure['payload'] = null;
                }
                $this->finish($work, $failure);
                Log::warning('customer.auth_mail_failed', ['work_id' => $workId, 'outcome' => 'mail_delivery_failed']);

                throw new RuntimeException('Customer authentication mail delivery failed.');
            }
            $this->finish($work, ['status' => 'sent', 'sent_at' => now(), 'payload' => null, 'last_error_code' => null]);
        } catch (Throwable) {
            Log::warning('customer.auth_mail_failed', ['work_id' => $workId, 'outcome' => 'delivery_unavailable']);
            throw new RuntimeException('Customer authentication mail temporarily unavailable.');
        }
    }

    private function claim(int $workId): ?array
    {
        return DB::transaction(function () use ($workId): ?array {
            $binding = CustomerAuthMailWork::query()->whereKey($workId)->value('auth_token_id');
            if ($binding === null) {
                return null;
            }
            $customerId = CustomerAuthToken::query()->whereKey($binding)->value('customer_id');
            $customer = Customer::query()->whereKey($customerId)->lockForUpdate()->first();
            $token = CustomerAuthToken::query()->whereKey($binding)->lockForUpdate()->first();
            $work = CustomerAuthMailWork::query()->whereKey($workId)->lockForUpdate()->first();
            if (! $work || $work->status !== 'pending') {
                return null;
            }
            if (! $customer || ! $token || ! $this->canDeliver($token, $customer) || ! $work->expires_at->isFuture()) {
                $work->forceFill(['status' => 'cancelled', 'payload' => null, 'last_error_code' => null])->save();

                return null;
            }
            if ($work->next_attempt_at->isFuture()) {
                return null;
            }
            if ($work->attempts >= 5) {
                $work->forceFill(['status' => 'failed', 'payload' => null, 'last_error_code' => 'attempt_limit'])->save();

                return null;
            }
            // The lease exceeds the worker timeout and survives a process or Redis crash.
            $work->forceFill(['attempts' => $work->attempts + 1, 'next_attempt_at' => now()->startOfSecond()->addSeconds(120)])->save();

            return ['work' => $work, 'purpose' => $token->purpose, 'email' => $token->email, 'token_hash' => $token->token_hash];
        });
    }

    private function eligibleBeforeSend(CustomerAuthMailWork $claim): bool
    {
        return DB::transaction(function () use ($claim): bool {
            $customerId = CustomerAuthToken::query()->whereKey($claim->auth_token_id)->value('customer_id');
            $customer = Customer::query()->whereKey($customerId)->lockForUpdate()->first();
            $token = CustomerAuthToken::query()->whereKey($claim->auth_token_id)->lockForUpdate()->first();
            $work = CustomerAuthMailWork::query()->whereKey($claim->getKey())->lockForUpdate()->first();

            return $customer && $token && $work
                && $work->status === 'pending'
                && $work->attempts === $claim->attempts
                && $work->next_attempt_at->equalTo($claim->next_attempt_at)
                && $work->next_attempt_at->isFuture()
                && $work->expires_at->isFuture()
                && $this->canDeliver($token, $customer);
        });
    }

    private function finish(CustomerAuthMailWork $claim, array $changes): void
    {
        CustomerAuthMailWork::query()->whereKey($claim->getKey())->where('status', 'pending')
            ->where('attempts', $claim->attempts)->where('next_attempt_at', $claim->next_attempt_at)
            ->update([...$changes, 'updated_at' => now()]);
    }

    public function recover(int $limit = 100): int
    {
        $limit = max(1, min(1000, $limit));
        $expired = CustomerAuthMailWork::query()->where('status', 'pending')->where('expires_at', '<=', now())->orderBy('id')->limit($limit)->pluck('id');
        CustomerAuthMailWork::query()->whereIn('id', $expired)->where('status', 'pending')->update([
            'status' => 'cancelled', 'payload' => null, 'last_error_code' => null, 'updated_at' => now(),
        ]);
        $old = CustomerAuthMailWork::query()->whereIn('status', ['sent', 'cancelled', 'failed'])->where('updated_at', '<=', now()->subDay())->orderBy('id')->limit($limit)->pluck('id');
        CustomerAuthMailWork::query()->whereIn('id', $old)->delete();

        $count = 0;
        $ids = CustomerAuthMailWork::query()->where('status', 'pending')->where('expires_at', '>', now())->where('next_attempt_at', '<=', now())->orderBy('id')->limit($limit)->pluck('id');
        foreach ($ids as $id) {
            if ($this->dispatch((int) $id)) {
                $count++;
            }
        }

        return $count;
    }

    private function canDeliver(CustomerAuthToken $token, Customer $customer): bool
    {
        if (! in_array($token->purpose, [CustomerAuthTokenPurpose::EmailVerification, CustomerAuthTokenPurpose::PasswordReset], true)
            || ! $token->isActiveFor($token->purpose)
            || $token->email !== $customer->email
            || $token->auth_version !== $customer->auth_version
            || $token->enrollment_version !== $customer->enrollment_version) {
            return false;
        }

        return $token->purpose === CustomerAuthTokenPurpose::EmailVerification
            ? $customer->email_verified_at === null && $customer->password === null
            : $customer->email_verified_at !== null;
    }

    private function dispatch(int $workId): bool
    {
        try {
            Bus::dispatch(new SendCustomerAuthMail($workId));

            return true;
        } catch (Throwable) {
            Log::warning('customer.auth_mail_dispatch_failed', ['work_id' => $workId, 'outcome' => 'queue_unavailable']);

            return false;
        }
    }
}
