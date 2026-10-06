<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\StoreBalanceTopUp;
use App\Models\User;
use App\Services\Admin\AdminBalanceReportReader;
use App\Services\StoreBalance\StoreBalanceTopUpProofFetcher;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;

final class AdminBalanceReportController extends Controller
{
    public function index(Request $request, AdminBalanceReportReader $reports): Response
    {
        $actor = $this->authorizedActor($request);
        $filters = $request->validate([
            'section' => ['sometimes', 'string', 'in:accounts,entries,topups,reviews,treasury,audit'],
            'currency' => ['sometimes', 'string', 'in:IDR,USDT'],
            'telegram_id' => ['sometimes', 'nullable', 'string', 'regex:/\A[1-9][0-9]{0,18}\z/'],
            'state' => ['sometimes', 'nullable', 'string', 'max:64'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'cursor' => ['sometimes', 'string', 'max:1024'],
        ]);

        return response()->view('admin.balance-reports.index', [
            'report' => $reports->page($actor, $filters),
        ])->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }

    public function proof(Request $request, string $topUp, StoreBalanceTopUpProofFetcher $proofs): Response
    {
        $actor = $this->authorizedActor($request);
        if ($request->query('admin') !== (string) $actor->getKey()) {
            abort(404);
        }

        $topUpModel = StoreBalanceTopUp::query()
            ->select(['id', 'payment_proof_file_id', 'payment_proof_submitted_at'])
            ->whereKey($topUp)
            ->firstOrFail();

        try {
            $proof = $proofs->fetch($topUpModel);
        } catch (RuntimeException) {
            abort(404);
        }

        $extension = $proof->mimeType === 'image/png' ? 'png' : 'jpg';

        return response($proof->contents)
            ->header('Content-Type', $proof->mimeType)
            ->header('Content-Disposition', 'attachment; filename="payment-proof.'.$extension.'"')
            ->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('Content-Security-Policy', "default-src 'none'; sandbox");
    }

    private function authorizedActor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && $actor->role instanceof UserRole && $actor->role->canReconcilePayments(), 403);

        return $actor;
    }
}
