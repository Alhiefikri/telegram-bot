<?php

namespace App\Services\StoreBalance;

use App\Enums\UserRole;
use App\Exceptions\BalanceAdjustmentRejected;
use App\Models\BalanceAdjustmentRequest;
use App\Models\UsdtReorgRecoveryRequest;
use App\Models\UsdtTopUpReviewRequest;
use App\Models\UsdtTransferRecoveryRequest;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/** Short-lived authority minted only from a successful request-bound KDF lease. */
final readonly class ApprovedBalanceAdjustment
{
    private function __construct(
        public string $token,
        private string $requestId,
        private string $fingerprint,
        private int $actorId,
        private int $expiresAt,
    ) {}

    public static function fromVerifiedLease(User $actor, BalanceAdjustmentRequest|UsdtTopUpReviewRequest|UsdtReorgRecoveryRequest|UsdtTransferRecoveryRequest $request, string $token): self
    {
        $lease = DB::table('balance_adjustment_auth_states')->where('user_id', $actor->id)->first();
        $fresh = $actor->fresh();
        if ($lease === null || $lease->lease_token !== $token || now()->gte($lease->lease_until)
            || $lease->verified_request_id !== $request->id || $lease->verified_fingerprint !== $request->fingerprint
            || (int) $request->owner_user_id !== (int) Auth::id() || (int) $actor->id !== (int) Auth::id()
            || $fresh?->role !== UserRole::Owner || ! $fresh->canAccessAdmin()
            || $request->credential_binding !== hash('sha256', $fresh->password)
            || ! request()->hasSession() || $request->session_binding !== hash('sha256', request()->session()->getId())) {
            throw new BalanceAdjustmentRejected('stale_authentication');
        }

        return new self($token, $request->id, $request->fingerprint, (int) $actor->id, now()->addSeconds(30)->timestamp);
    }

    public function assertMatches(BalanceAdjustmentRequest|UsdtTopUpReviewRequest|UsdtReorgRecoveryRequest|UsdtTransferRecoveryRequest $request): void
    {
        if ($request->id !== $this->requestId || ! hash_equals($request->fingerprint, $this->fingerprint)
            || (int) $request->owner_user_id !== $this->actorId || now()->timestamp >= $this->expiresAt) {
            throw new BalanceAdjustmentRejected('stale_authentication');
        }
    }
}
