<?php

namespace App\Services\StoreBalance;

use App\Exceptions\BalanceAdjustmentRejected;
use App\Models\BalanceAdjustmentRequest;
use App\Models\UsdtReorgRecoveryRequest;
use App\Models\UsdtTopUpReviewRequest;
use App\Models\UsdtTransferRecoveryRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Telegram\TelegramOutboundEffectService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** Durable per-Owner sliding window and bounded KDF lease, outside money locks. */
final class BalanceAdjustmentPasswordConfirmation
{
    public function __construct(private readonly TelegramOutboundEffectService $outbound, private readonly AuditLogger $audit) {}

    public function verify(User $actor, string $password, BalanceAdjustmentRequest|UsdtTopUpReviewRequest|UsdtReorgRecoveryRequest|UsdtTransferRecoveryRequest $request): ApprovedBalanceAdjustment
    {
        if (DB::transactionLevel() !== 0) {
            throw new BalanceAdjustmentRejected('authentication_requires_independent_transaction');
        }
        if (preg_match('/^[1-9][0-9]{0,18}$/D', trim((string) config('services.telegram.admin_telegram_chat_id'))) !== 1) {
            throw new BalanceAdjustmentRejected('owner_notification_unavailable');
        }
        $token = (string) Str::uuid();
        DB::transaction(function () use ($actor, $token): void {
            DB::table('balance_adjustment_auth_states')->insertOrIgnore([
                'user_id' => $actor->id, 'failed_at' => '[]',
            ]);
            $state = DB::table('balance_adjustment_auth_states')->where('user_id', $actor->id)->lockForUpdate()->first();
            if ($state->locked_until !== null && now()->lt($state->locked_until)) {
                throw new BalanceAdjustmentRejected('owner_locked');
            }
            if ($state->lease_until !== null && now()->lt($state->lease_until)) {
                throw new BalanceAdjustmentRejected('confirmation_busy');
            }
            DB::table('balance_adjustment_auth_states')->where('user_id', $actor->id)->update([
                'lease_token' => $token, 'lease_until' => now()->addSeconds(30), 'verified_request_id' => null, 'verified_fingerprint' => null,
            ]);
        }, 5);
        $valid = strlen($password) <= 1024 && Hash::check($password, $actor->password);
        $accepted = DB::transaction(function () use ($actor, $token, $valid, $request): bool {
            $state = DB::table('balance_adjustment_auth_states')->where('user_id', $actor->id)->lockForUpdate()->first();
            if ($state->lease_token !== $token || now()->gte($state->lease_until)) {
                return false;
            }
            $failures = array_values(array_filter(json_decode($state->failed_at, true, flags: JSON_THROW_ON_ERROR),
                fn (int $timestamp): bool => $timestamp > now()->subMinutes(15)->timestamp));
            if (! $valid) {
                $failures[] = now()->timestamp;
            }
            $changes = ['failed_at' => json_encode($failures, JSON_THROW_ON_ERROR)];
            if ($valid) {
                $changes += ['verified_request_id' => $request->id, 'verified_fingerprint' => $request->fingerprint];
            }
            if (! $valid) {
                $changes += ['lease_token' => null, 'lease_until' => null];
            }
            if (count($failures) >= 5) {
                $episode = (string) Str::uuid();
                $changes += ['locked_until' => now()->addMinutes(15), 'episode' => $episode];
                $this->audit->logWithResolvedContext('balance.adjustment.lockout', new: ['episode' => $episode, 'locked_until' => $changes['locked_until']->toISOString()], user: $actor);
                $this->outbound->recordBalanceAdjustmentNotice('lockout:'.$episode, 'owner',
                    'Konfirmasi saldo Owner dikunci selama 15 menit setelah lima password salah.');
            }
            DB::table('balance_adjustment_auth_states')->where('user_id', $actor->id)->update($changes);

            return $valid;
        }, 5);
        if (! $accepted) {
            throw new BalanceAdjustmentRejected('invalid_password');
        }

        return ApprovedBalanceAdjustment::fromVerifiedLease($actor, $request, $token);
    }

    public function consume(User $actor, string $token): void
    {
        $state = DB::table('balance_adjustment_auth_states')->where('user_id', $actor->id)->lockForUpdate()->first();
        if ($state === null || $state->lease_token !== $token || now()->gte($state->lease_until)
            || ($state->locked_until !== null && now()->lt($state->locked_until))) {
            throw new BalanceAdjustmentRejected('stale_authentication');
        }
        DB::table('balance_adjustment_auth_states')->where('user_id', $actor->id)->update(['lease_token' => null, 'lease_until' => null, 'verified_request_id' => null, 'verified_fingerprint' => null]);
    }

    public function release(User $actor, string $token): void
    {
        DB::table('balance_adjustment_auth_states')->where('user_id', $actor->id)->where('lease_token', $token)
            ->update(['lease_token' => null, 'lease_until' => null, 'verified_request_id' => null, 'verified_fingerprint' => null]);
    }
}
