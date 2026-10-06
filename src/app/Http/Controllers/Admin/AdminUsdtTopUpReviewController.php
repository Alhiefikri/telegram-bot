<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Exceptions\BalanceAdjustmentRejected;
use App\Http\Controllers\Controller;
use App\Models\CustomerUsdtTopUp;
use App\Models\UsdtReorgRecoveryRequest;
use App\Models\UsdtTopUpReviewRequest;
use App\Models\User;
use App\Services\UsdtTopUps\UsdtReorgIncidentService;
use App\Services\UsdtTopUps\UsdtTopUpReview;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use InvalidArgumentException;

final class AdminUsdtTopUpReviewController extends Controller
{
    public function index(Request $request): View
    {
        $owner = User::query()->find($request->user()->id);
        abort_unless($owner?->role === UserRole::Owner && $owner->canAccessAdmin(), 403);
        $request->validate([
            'preview' => ['nullable', 'uuid'],
            'recovery_preview' => ['nullable', 'uuid'],
        ]);
        $targets = DB::table('customer_usdt_top_ups as targets')
            ->join('customer_usdt_top_up_settlement_work as work', 'work.top_up_id', '=', 'targets.id')
            ->join('customers', 'customers.id', '=', 'targets.customer_id')
            ->leftJoin('customer_usdt_top_up_work as preparation', 'preparation.top_up_id', '=', 'targets.id')
            ->leftJoin('customer_usdt_top_up_resolutions as resolution', 'resolution.top_up_id', '=', 'targets.id')
            ->select(['targets.id', 'targets.customer_id', 'targets.amount_micro', 'targets.payable_micro', 'targets.fee_micro', 'targets.created_at', 'customers.telegram_id', 'work.state', 'work.last_error', 'preparation.state as preparation_state', 'resolution.kind as resolution_kind'])
            ->orderByDesc('targets.created_at')->orderByDesc('targets.id')
            ->paginate(25, pageName: 'targets_page')->withQueryString();

        $selected = null;
        $evidence = collect();
        if ($request->filled('top_up')) {
            $validated = $request->validate(['top_up' => ['required', 'uuid']]);
            $selected = CustomerUsdtTopUp::query()->findOrFail($validated['top_up']);
            $evidence = DB::table('customer_usdt_top_up_evidence')->where('top_up_id', $selected->id)
                ->select(['id', 'version', 'status', 'reason', 'created_at'])
                ->orderByDesc('version')->paginate(25, pageName: 'evidence_page')->withQueryString();
        }

        // Receiver ownership, not the actor who supplies a public hash, owns
        // every observation. Raw addresses/hash/chain payload stay out of this list.
        $observations = DB::table('usdt_bsc_transfer_observations as observations')
            ->join('customer_usdt_deposit_receivers as receivers', 'receivers.id', '=', 'observations.receiver_id')
            ->leftJoin('usdt_bsc_scan_checkpoints as checkpoints', 'checkpoints.receiver_id', '=', 'receivers.id')
            ->select(['observations.id', 'receivers.customer_id', 'observations.receiver_id', 'observations.amount_units', 'observations.block_number', 'observations.observed_at', 'checkpoints.review_reason'])
            ->orderByDesc('observations.id')->paginate(25, pageName: 'observations_page')->withQueryString();

        $sessionBinding = hash('sha256', $request->session()->getId());
        $credentialBinding = hash('sha256', $owner->password);
        $preview = UsdtTopUpReviewRequest::query()
            ->whereKey($request->query('preview'))
            ->where('owner_user_id', $owner->id)
            ->where('session_binding', $sessionBinding)->where('credential_binding', $credentialBinding)
            ->first();
        $previewOriginal = $preview === null ? null : DB::table('customer_usdt_top_ups as targets')
            ->join('customers', 'customers.id', '=', 'targets.customer_id')
            ->where('targets.id', $preview->top_up_id)
            ->select(['targets.customer_id', 'customers.telegram_id', 'targets.amount_micro', 'targets.fee_micro'])
            ->firstOrFail();
        $recoveryPreview = UsdtReorgRecoveryRequest::query()
            ->whereKey($request->query('recovery_preview'))
            ->where('owner_user_id', $owner->id)
            ->where('session_binding', $sessionBinding)->where('credential_binding', $credentialBinding)
            ->first();
        $recoveryIncident = $recoveryPreview === null ? null : DB::table('usdt_reorg_incidents')
            ->where('id', $recoveryPreview->incident_id)
            ->select(['customer_id', 'exposure_micro', 'spent_exposure_micro'])->firstOrFail();
        $incidents = DB::table('usdt_reorg_incidents as incidents')
            ->leftJoin('usdt_reorg_recovery_cases as cases', 'cases.incident_id', '=', 'incidents.id')
            ->select(['incidents.id', 'incidents.top_up_id', 'incidents.customer_id', 'incidents.reason',
                'incidents.exposure_micro', 'incidents.available_exposure_micro', 'incidents.spent_exposure_micro',
                'incidents.created_at', 'cases.status as recovery_status'])
            ->orderByDesc('incidents.id')->paginate(25, pageName: 'incidents_page')->withQueryString();

        return view('admin.store-balance.usdt-review', compact('targets', 'selected', 'evidence', 'observations', 'preview', 'previewOriginal', 'recoveryPreview', 'recoveryIncident', 'incidents'));
    }

    public function preview(Request $request, UsdtTopUpReview $reviews): RedirectResponse
    {
        $data = $request->validate([
            'top_up_id' => ['required', 'uuid'],
            'decision' => ['required', 'in:settled,resolved_by_compensation,externally_refunded'],
            'amount' => ['required', 'string', 'max:32'],
            'transaction_hash' => ['required', 'string', 'regex:/\A0x[a-fA-F0-9]{64}\z/'],
            'reason' => ['required', 'string', 'max:1000'],
            'refund_transaction_hash' => ['nullable', 'string', 'regex:/\A0x[a-fA-F0-9]{64}\z/'],
            'refund_authorization_id' => ['nullable', 'uuid'],
        ]);
        try {
            $preview = $reviews->request($request->user(), $data['top_up_id'], $data['decision'],
                $data['amount'], $data['transaction_hash'], $data['reason'],
                $data['refund_transaction_hash'] ?? null, $data['refund_authorization_id'] ?? null);
        } catch (DomainException|InvalidArgumentException $error) {
            return redirect()->route('admin.store-balance.usdt-review.index', ['top_up' => $data['top_up_id']])
                ->withErrors(['review' => $error->getMessage()]);
        }

        return redirect()->route('admin.store-balance.usdt-review.index', ['top_up' => $preview->top_up_id, 'preview' => $preview->id]);
    }

    public function confirm(Request $request, string $review, UsdtTopUpReview $reviews): RedirectResponse
    {
        $data = $request->validate([
            'fingerprint' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'version' => ['required', 'integer', 'in:1'],
            'password' => ['required', 'string', 'max:1024'],
        ]);
        try {
            $resolution = $reviews->confirm($request->user(), $review, $data['fingerprint'], (int) $data['version'], $data['password']);
        } catch (BalanceAdjustmentRejected|DomainException|InvalidArgumentException $error) {
            return redirect()->route('admin.store-balance.usdt-review.index', ['preview' => $review])
                ->withErrors(['review' => 'Resolusi ditahan. '.$error->getMessage()]);
        }

        return redirect()->route('admin.store-balance.usdt-review.index', ['top_up' => $resolution->top_up_id])
            ->with('status', 'Resolusi tercatat: '.$resolution->kind.'. Invoice asli tetap tidak berubah.');
    }

    public function recoveryPreview(Request $request, UsdtReorgIncidentService $reorgs): RedirectResponse
    {
        $data = $request->validate(['incident_id' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:500']]);
        try {
            $preview = $reorgs->requestRecovery($request->user(), (int) $data['incident_id'], (string) Str::uuid(), $data['reason']);
        } catch (InvalidArgumentException $error) {
            return redirect()->route('admin.store-balance.usdt-review.index')->withErrors(['recovery' => $error->getMessage()]);
        }

        return redirect()->route('admin.store-balance.usdt-review.index', ['recovery_preview' => $preview->id]);
    }

    public function recoveryConfirm(Request $request, string $recovery, UsdtReorgIncidentService $reorgs): RedirectResponse
    {
        $data = $request->validate([
            'fingerprint' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'password' => ['required', 'string', 'max:1024'],
        ]);
        try {
            $reorgs->confirmRecovery($request->user(), $recovery, $data['password'], $data['fingerprint']);
        } catch (BalanceAdjustmentRejected|InvalidArgumentException $error) {
            return redirect()->route('admin.store-balance.usdt-review.index', ['recovery_preview' => $recovery])
                ->withErrors(['recovery' => $error->getMessage()]);
        }

        return redirect()->route('admin.store-balance.usdt-review.index')
            ->with('status', 'Recovery case diotorisasi; exposure tetap unresolved dan akun tetap dibekukan. Tidak ada debit Customer.');
    }
}
