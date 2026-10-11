<?php

namespace App\Services\Customer;

use App\Enums\CustomerAuthTokenPurpose;
use App\Jobs\SendCustomerAuthMail;
use App\Models\Customer;
use App\Models\CustomerAuthMailWork;
use App\Models\CustomerAuthToken;
use App\Models\CustomerLoginLink;
use App\Notifications\CustomerAuthMailNotification;
use App\Notifications\CustomerLoginLinkNotification;
use Illuminate\Mail\Transport\LogTransport;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Throwable;

final class CustomerAuthMailDeliveryService
{
    public function persist(CustomerAuthToken $token, #[\SensitiveParameter] string $plainToken): void
    {
        $this->persistWork(['auth_token_id' => $token->getKey()], $token->email, $plainToken, $token->expires_at);
    }

    public function persistLoginLink(CustomerLoginLink $link, #[\SensitiveParameter] string $plainToken): void
    {
        $this->persistWork(['login_link_id' => $link->getKey()], $link->email, $plainToken, $link->expires_at);
    }

    private function persistWork(array $binding, #[\SensitiveParameter] string $email, #[\SensitiveParameter] string $plainToken, $expiresAt): void
    {
        $work = new CustomerAuthMailWork;
        $work->forceFill([
            ...$binding,
            'payload' => ['email' => $email, 'token' => $plainToken],
            'status' => 'pending',
            'expires_at' => $expiresAt,
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
                $valid = $this->validPayload($payload, $claim['grant']);
            } catch (Throwable) {
                $valid = false;
            }
            if (! $valid) {
                $this->finish($work, ['status' => 'failed', 'payload' => null, 'last_error_code' => 'invalid_payload']);

                return;
            }
            if (! $this->eligibleBeforeSend($work)) {
                return;
            }
            try {
                $this->assertSafeMailer((string) config('mail.default'));
                if (Mail::mailer()->getSymfonyTransport() instanceof LogTransport) {
                    throw new RuntimeException('Authentication mail cannot use a logging transport.');
                }
                $notification = $claim['grant'] instanceof CustomerLoginLink
                    ? new CustomerLoginLinkNotification(route('customer.login-link.show').'#token='.$payload['token'])
                    : new CustomerAuthMailNotification($claim['grant']->purpose, $payload['token']);
                // Reset can revoke a grant during SMTP; consumption remains fenced by its locked state.
                Notification::route('mail', $payload['email'])->notifyNow($notification);
            } catch (Throwable) {
                $terminal = $work->attempts >= 5;
                $failure = [
                    'status' => $terminal ? 'failed' : 'pending',
                    'next_attempt_at' => now()->addSeconds(match ($work->attempts) {
                        1 => 60, 2 => 120, default => 300
                    }),
                    'last_error_code' => 'mail_delivery_failed',
                ];
                if ($terminal) {
                    $failure['payload'] = null;
                }
                $this->finish($work, $failure);

                throw new RuntimeException('Customer authentication mail delivery failed.');
            }
            $this->finish($work, ['status' => 'sent', 'sent_at' => now(), 'payload' => null, 'last_error_code' => null]);
        } catch (Throwable) {
            Log::warning('customer.auth_mail_failed', ['work_id' => $workId, 'outcome' => 'delivery_unavailable']);
            throw new RuntimeException('Customer authentication mail temporarily unavailable.');
        }
    }

    private function validPayload(#[\SensitiveParameter] mixed $payload, CustomerAuthToken|CustomerLoginLink $grant): bool
    {
        if (! is_array($payload) || ! is_string($payload['token'] ?? null) || ($payload['email'] ?? null) !== $grant->email) {
            return false;
        }
        if ($grant instanceof CustomerAuthToken && $grant->purpose === CustomerAuthTokenPurpose::EmailOtp) {
            return preg_match('/\A[0-9]{6}\z/', $payload['token']) === 1
                && is_string($grant->challenge_hash)
                && preg_match('/\A[0-9a-f]{64}\z/', $grant->challenge_hash) === 1
                && hash_equals($grant->token_hash, CustomerEmailOtpService::codeDigest($grant->email, $grant->challenge_hash, $payload['token']));
        }

        return preg_match('/\A[0-9a-f]{64}\z/', $payload['token']) === 1
            && hash_equals($grant->token_hash, hash('sha256', $payload['token']));
    }

    private function claim(int $workId): ?array
    {
        return DB::transaction(function () use ($workId): ?array {
            [$customer, $grant] = $this->lockGrant($workId);
            $work = CustomerAuthMailWork::query()->whereKey($workId)->lockForUpdate()->first();
            if (! $work || $work->status !== 'pending') {
                return null;
            }
            if (! $this->canDeliver($grant, $customer) || ! $work->expires_at->isFuture()) {
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

            return ['work' => $work, 'grant' => $grant];
        });
    }

    private function lockGrant(int $workId): array
    {
        $binding = CustomerAuthMailWork::query()->whereKey($workId)->first(['auth_token_id', 'login_link_id']);
        if (! $binding || ($binding->auth_token_id === null) === ($binding->login_link_id === null)) {
            return [null, null];
        }
        if ($binding->auth_token_id !== null) {
            $customerId = CustomerAuthToken::query()->whereKey($binding->auth_token_id)->value('customer_id');
            $customer = Customer::query()->whereKey($customerId)->lockForUpdate()->first();
            $grant = CustomerAuthToken::query()->whereKey($binding->auth_token_id)->lockForUpdate()->first();
        } else {
            $email = CustomerLoginLink::query()->whereKey($binding->login_link_id)->value('email');
            $customer = Customer::query()->where('email', $email)->lockForUpdate()->first();
            $grant = CustomerLoginLink::query()->whereKey($binding->login_link_id)->lockForUpdate()->first();
        }

        return [$customer, $grant];
    }

    private function eligibleBeforeSend(CustomerAuthMailWork $claim): bool
    {
        return DB::transaction(function () use ($claim): bool {
            [$customer, $grant] = $this->lockGrant((int) $claim->getKey());
            $work = CustomerAuthMailWork::query()->whereKey($claim->getKey())->lockForUpdate()->first();
            if (! $work || $work->status !== 'pending' || $work->attempts !== $claim->attempts
                || ! $work->next_attempt_at->equalTo($claim->next_attempt_at)) {
                return false;
            }
            if (! $work->expires_at->isFuture() || ! $this->canDeliver($grant, $customer)) {
                $work->forceFill(['status' => 'cancelled', 'payload' => null, 'last_error_code' => null])->save();

                return false;
            }

            return $work->next_attempt_at->isFuture();
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
        $invalid = CustomerAuthMailWork::query()->where('status', 'pending')->where(function ($query): void {
            $query->where('expires_at', '<=', now())
                ->orWhereHas('loginLink', fn ($link) => $link->whereNotNull('used_at')->orWhere('expires_at', '<=', now()))
                ->orWhereHas('authToken', function ($token): void {
                    $token->whereNotNull('consumed_at')->orWhere('expires_at', '<=', now())
                        ->orWhere(fn ($otp) => $otp->where('purpose', CustomerAuthTokenPurpose::EmailOtp->value)->where('attempts', '>=', 5))
                        ->orWhereHas('customer', function ($customer): void {
                            $customer->whereColumn('customers.auth_version', '<>', 'customer_auth_tokens.auth_version')
                                ->orWhereColumn('customers.enrollment_version', '<>', 'customer_auth_tokens.enrollment_version')
                                ->orWhereColumn('customers.email', '<>', 'customer_auth_tokens.email');
                        });
                });
        })->orderBy('id')->limit($limit)->pluck('id');
        foreach ($invalid as $id) {
            DB::transaction(function () use ($id): void {
                [$customer, $grant] = $this->lockGrant((int) $id);
                $work = CustomerAuthMailWork::query()->whereKey($id)->lockForUpdate()->first();
                if ($work && $work->status === 'pending' && (! $work->expires_at->isFuture() || ! $this->canDeliver($grant, $customer))) {
                    $work->forceFill(['status' => 'cancelled', 'payload' => null, 'last_error_code' => null])->save();
                }
            });
        }
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

    private function canDeliver(CustomerAuthToken|CustomerLoginLink|null $grant, ?Customer $customer): bool
    {
        if ($grant instanceof CustomerLoginLink) {
            return $grant->used_at === null && $grant->expires_at->isFuture();
        }
        if (! $grant || ! $customer
            || ! in_array($grant->purpose, [CustomerAuthTokenPurpose::EmailVerification, CustomerAuthTokenPurpose::PasswordReset, CustomerAuthTokenPurpose::EmailOtp], true)
            || ! $grant->isActiveFor($grant->purpose)
            || $grant->email !== $customer->email
            || $grant->auth_version !== $customer->auth_version
            || $grant->enrollment_version !== $customer->enrollment_version) {
            return false;
        }

        return match ($grant->purpose) {
            CustomerAuthTokenPurpose::EmailVerification => $customer->email_verified_at === null && $customer->password === null,
            CustomerAuthTokenPurpose::PasswordReset => $customer->email_verified_at !== null,
            CustomerAuthTokenPurpose::EmailOtp => $customer->email_verified_at !== null
                && is_string($grant->session_binding_hash) && preg_match('/\A[0-9a-f]{64}\z/', $grant->session_binding_hash) === 1,
            default => false,
        };
    }

    private function assertSafeMailer(string $name, array $seen = []): void
    {
        if (in_array($name, $seen, true)) {
            throw new RuntimeException('Invalid authentication mail transport configuration.');
        }
        $config = config('mail.mailers.'.$name, []);
        if (($config['transport'] ?? null) === 'log') {
            throw new RuntimeException('Authentication mail cannot use a logging transport.');
        }
        foreach ($config['mailers'] ?? [] as $child) {
            $this->assertSafeMailer($child, [...$seen, $name]);
        }
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
