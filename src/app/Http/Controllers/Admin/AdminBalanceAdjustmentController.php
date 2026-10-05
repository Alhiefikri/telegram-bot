<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\BalanceAdjustmentRejected;
use App\Exceptions\StoreBalanceProjectionMismatchException;
use App\Http\Controllers\Controller;
use App\Models\BalanceAdjustmentRequest as BalanceAdjustmentPreview;
use App\Models\Customer;
use App\Models\CustomerBalanceAccount;
use App\Models\User;
use App\Services\Admin\BalanceAdjustmentDashboardReadiness;
use App\Services\StoreBalance\BalanceAdjustmentService;
use App\Services\StoreBalance\StoreBalanceLedger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

final class AdminBalanceAdjustmentController extends Controller
{
    private const BALANCE_PAGE_SIZE = 20;

    private const HISTORY_PAGE_SIZE = 20;

    public function __construct(private readonly BalanceAdjustmentDashboardReadiness $readiness) {}

    public function index(Request $request, BalanceAdjustmentService $adjustments, StoreBalanceLedger $ledger): View
    {
        $this->assertDashboardReady();
        $owner = $this->owner($request);
        $telegramId = $request->query('telegram_id');
        $customer = null;
        $lookupError = null;

        if ($telegramId !== null) {
            try {
                $customer = $adjustments->findRegisteredCustomerByTelegramId($owner, $telegramId);
                if ($customer === null) {
                    $lookupError = 'Customer tidak ditemukan untuk Telegram ID tersebut.';
                }
            } catch (BalanceAdjustmentRejected $exception) {
                $lookupError = $this->message($exception->reason);
            }
        }

        $customerBalance = $customer === null ? null : $ledger->snapshot($customer);

        $previewId = $request->query('preview');
        $preview = is_string($previewId) && Str::isUuid($previewId)
            ? BalanceAdjustmentPreview::query()
                ->select(['id', 'customer_id', 'owner_user_id', 'telegram_id', 'username', 'amount', 'reason', 'category', 'fingerprint', 'version', 'state', 'ledger_entry_id', 'expires_at', 'resolved_at'])
                ->whereKey($previewId)
                ->where('owner_user_id', $owner->id)
                ->where('category', 'owner_attested')
                ->where('session_binding', hash('sha256', $request->session()->getId()))
                ->where('credential_binding', hash('sha256', $owner->password))
                ->first()
            : null;
        $previewCustomer = $preview === null
            ? null
            : Customer::query()->select(['id', 'telegram_id'])->find($preview->customer_id);
        $previewBalance = $previewCustomer === null ? null : $ledger->snapshot($previewCustomer);

        $previewUnavailable = $previewId !== null && $preview === null;
        $positiveBalances = CustomerBalanceAccount::query()
            ->select(['id', 'customer_id', 'balance'])
            ->with('customer:id,telegram_id,username,first_name')
            ->where(function ($query): void {
                $query->where('balance', '>', 0)->orWhereExists(function ($latest): void {
                    $latest->selectRaw('1')
                        ->from('store_balance_entries as latest_entries')
                        ->whereColumn('latest_entries.customer_balance_account_id', 'customer_balance_accounts.id')
                        ->whereRaw('latest_entries.id = (SELECT MAX(balance_entries.id) FROM store_balance_entries AS balance_entries WHERE balance_entries.customer_balance_account_id = customer_balance_accounts.id)')
                        ->where('latest_entries.balance_after', '>', 0);
                });
            })
            ->orderByDesc('balance')
            ->orderBy('customer_id')
            ->paginate(self::BALANCE_PAGE_SIZE, ['id', 'customer_id', 'balance'], 'balances_page')
            ->withQueryString();
        $positiveBalances->setCollection($positiveBalances->getCollection()->map(
            fn (CustomerBalanceAccount $account): array => [
                'customer' => $account->customer,
                'snapshot' => $ledger->snapshot($account->customer),
            ],
        ));

        $history = $customer === null
            ? null
            : BalanceAdjustmentPreview::query()
                ->where('customer_id', $customer->id)
                ->with('ledgerEntry:id,kind,amount')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate(self::HISTORY_PAGE_SIZE, [
                    'id', 'customer_id', 'telegram_id', 'username', 'amount', 'reason', 'category', 'state', 'ledger_entry_id', 'created_at',
                ], 'history_page')
                ->withQueryString();

        return view('admin.store-balance.adjustments', [
            'telegramId' => is_string($telegramId) ? $telegramId : null,
            'customer' => $customer,
            'customerBalance' => $customerBalance,
            'lookupError' => $lookupError,
            'preview' => $preview,
            'previewCustomer' => $previewCustomer,
            'previewBalance' => $previewBalance,
            'previewUnavailable' => $previewUnavailable,
            'positiveBalances' => $positiveBalances,
            'history' => $history,
        ]);
    }

    public function preview(Request $request, BalanceAdjustmentService $adjustments): RedirectResponse
    {
        $this->assertDashboardReady();
        $owner = $this->owner($request);
        $telegramId = $request->input('telegram_id');
        $amount = $request->input('amount');
        $reason = $request->input('reason');

        try {
            if (! is_string($reason)) {
                throw new BalanceAdjustmentRejected('invalid_amount_or_reason');
            }

            $preview = $adjustments->request($owner, $telegramId, $amount, $reason);
        } catch (BalanceAdjustmentRejected $exception) {
            return redirect()
                ->route('admin.store-balance.adjustments.index', $this->lookupQuery($telegramId))
                ->withErrors(['adjustment' => $this->message($exception->reason)])
                ->withInput($this->safeInput($telegramId, $amount, $reason));
        }

        return redirect()
            ->route('admin.store-balance.adjustments.index', [
                'telegram_id' => $preview->telegram_id,
                'preview' => $preview->id,
            ])
            ->with('status', 'Preview dibuat. Periksa kembali Customer, Telegram ID, nominal, dan alasan sebelum memasukkan password Owner.');
    }

    public function confirm(Request $request, string $adjustment, BalanceAdjustmentService $adjustments): RedirectResponse
    {
        $this->assertDashboardReady();
        $owner = $this->owner($request);
        $validated = $request->validate([
            'fingerprint' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/D'],
            'version' => ['required', 'integer', 'in:1'],
            'password' => ['required', 'string', 'max:4096'],
        ]);

        try {
            $result = $adjustments->confirm(
                $owner,
                $adjustment,
                $validated['fingerprint'],
                (int) $validated['version'],
                $validated['password'],
            );
        } catch (BalanceAdjustmentRejected $exception) {
            if ($exception->reason === 'owner_required') {
                abort(403);
            }

            return redirect()
                ->route('admin.store-balance.adjustments.index', ['preview' => $adjustment])
                ->withErrors(['confirmation' => $this->message($exception->reason)]);
        } catch (StoreBalanceProjectionMismatchException) {
            return redirect()
                ->route('admin.store-balance.adjustments.index', ['preview' => $adjustment])
                ->withErrors(['confirmation' => 'Saldo tidak dapat diverifikasi dengan ledger. Penambahan ditahan sampai rekonsiliasi dilakukan.']);
        }

        $preview = BalanceAdjustmentPreview::query()->select(['id', 'telegram_id'])->find($adjustment);
        abort_if($preview === null, 404);
        $status = $result->applied
            ? 'Penambahan saldo diterapkan. Entry #'.$result->entryId.'; saldo Rupiah sekarang Rp '.number_format($result->balanceAfter, 0, ',', '.').'.'
            : 'Permintaan ini sudah diterapkan sebelumnya. Saldo tidak ditambah kembali.';

        return redirect()
            ->route('admin.store-balance.adjustments.index', [
                'telegram_id' => $preview->telegram_id,
                'preview' => $preview->id,
            ])
            ->with('status', $status);
    }

    public function cancel(Request $request, string $adjustment, BalanceAdjustmentService $adjustments): RedirectResponse
    {
        $this->assertDashboardReady();
        $owner = $this->owner($request);
        $validated = $request->validate([
            'fingerprint' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/D'],
            'version' => ['required', 'integer', 'in:1'],
        ]);

        try {
            $preview = $adjustments->close($owner, $adjustment, $validated['fingerprint'], (int) $validated['version'], 'cancelled');
        } catch (BalanceAdjustmentRejected $exception) {
            if ($exception->reason === 'owner_required') {
                abort(403);
            }

            return redirect()
                ->route('admin.store-balance.adjustments.index', ['preview' => $adjustment])
                ->withErrors(['confirmation' => $this->message($exception->reason)]);
        }

        return redirect()
            ->route('admin.store-balance.adjustments.index', [
                'telegram_id' => $preview->telegram_id,
                'preview' => $preview->id,
            ])
            ->with('status', 'Preview dibatalkan. Tidak ada saldo yang berubah.');
    }

    private function owner(Request $request): User
    {
        $owner = $request->attributes->get('adminUser');
        abort_unless($owner instanceof User && $owner->role?->canCompensateStoreBalance() === true, 403);

        return $owner;
    }

    private function assertDashboardReady(): void
    {
        abort_unless($this->readiness->isReady(), 404);
    }

    private function lookupQuery(mixed $telegramId): array
    {
        return is_string($telegramId) ? ['telegram_id' => $telegramId] : [];
    }

    private function safeInput(mixed $telegramId, mixed $amount, mixed $reason): array
    {
        return [
            'telegram_id' => is_string($telegramId) ? $telegramId : '',
            'amount' => is_string($amount) ? $amount : '',
            'reason' => is_string($reason) ? $reason : '',
        ];
    }

    private function message(string $reason): string
    {
        return match ($reason) {
            'invalid_telegram_id' => 'Telegram ID harus berupa angka positif tanpa pemisah.',
            'customer_not_registered' => 'Customer tidak ditemukan untuk Telegram ID tersebut.',
            'invalid_amount_or_reason' => 'Masukkan nominal Rupiah positif dalam angka utuh dan alasan maksimal 500 karakter.',
            'owner_required' => 'Hanya Owner yang dapat mengelola penambahan saldo.',
            'invalid_password' => 'Password Owner tidak cocok. Preview masih menunggu konfirmasi.',
            'owner_locked' => 'Konfirmasi dikunci selama 15 menit setelah terlalu banyak password salah.',
            'confirmation_busy' => 'Konfirmasi lain sedang berjalan. Coba lagi sebentar.',
            'preview_binding_mismatch' => 'Preview tidak cocok dengan sesi Owner ini. Buat preview baru.',
            'preview_unavailable' => 'Preview sudah kedaluwarsa atau ditutup. Buat preview baru.',
            'credentials_changed' => 'Password Owner berubah. Buat preview baru untuk melanjutkan.',
            'authenticated_session_required' => 'Sesi admin tidak tersedia. Muat ulang halaman dan coba lagi.',
            default => 'Penambahan saldo tidak dapat diproses. Periksa status dan coba lagi jika preview masih berlaku.',
        };
    }
}
