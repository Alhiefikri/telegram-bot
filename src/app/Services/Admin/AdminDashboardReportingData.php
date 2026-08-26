<?php

namespace App\Services\Admin;

use App\Enums\KlikQrisEnvironment;
use App\Models\Payment;
use App\Models\User;
use App\Services\KlikQris\KlikQrisClient;
use App\Services\KlikQris\KlikQrisHistoryItem;
use App\Services\KlikQris\KlikQrisReportingException;
use App\Services\KlikQris\KlikQrisWithdrawalItem;
use App\Services\KlikQris\KlikQrisWithdrawalQuery;
use App\Services\Telegram\TelegramBotApiRedactor;
use App\Support\Money;
use App\Support\PaymentProvider;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

final class AdminDashboardReportingData
{
    public function __construct(private readonly KlikQrisClient $client) {}

    /** @return array<string, mixed> */
    public function emptyHistory(): array
    {
        return [
            'state' => 'inactive',
            'can_view' => true,
            'environment' => $this->environmentLabel(),
            'last_fetched_at' => null,
            'last_fetched_at_label' => null,
            'stale' => false,
            'notice' => null,
            'items' => [],
            'pagination' => $this->emptyPagination(),
        ];
    }

    /** @return array<string, mixed> */
    public function forHistory(mixed $page = 1): array
    {
        $pageNumber = $this->normalizePage($page);

        try {
            $cached = $this->rememberReport(
                'history',
                ['page' => $pageNumber],
                fn () => $this->client->historyPage($pageNumber),
            );
            $report = $cached['report'];
            $fetchedAt = $cached['fetched_at'];
        } catch (KlikQrisReportingException $exception) {
            return $this->reportingFailure($this->emptyHistory(), $exception, 'history');
        }

        $items = $report->items();
        $localPayments = $this->localPayments($items);

        return array_replace($this->emptyHistory(), [
            'state' => 'ready',
            'last_fetched_at' => $fetchedAt?->toDateTimeString(),
            'last_fetched_at_label' => $fetchedAt?->format('d M Y H:i'),
            'stale' => $fetchedAt === null,
            'items' => array_map(
                fn (KlikQrisHistoryItem $item): array => $this->historyItem($item, $localPayments),
                $items,
            ),
            'pagination' => $this->pagination(
                $report->currentPage(),
                $report->lastPage(),
                $report->perPage(),
                $report->total(),
                'riwayat',
                [],
            ),
        ]);
    }

    /** @return array<string, mixed> */
    public function emptyWithdraw(User $user): array
    {
        $canView = (bool) $user->role?->canReconcilePayments();
        $payload = [
            'state' => $canView ? 'inactive' : 'forbidden',
            'can_view' => $canView,
            'environment' => $this->environmentLabel(),
            'last_fetched_at' => null,
            'last_fetched_at_label' => null,
            'stale' => false,
            'notice' => null,
            'filters' => $this->emptyWithdrawalFilters(),
            'items' => [],
            'pagination' => $this->emptyPagination(),
        ];

        if (! $canView) {
            $payload['notice'] = [
                'tone' => 'warning',
                'title' => 'Akses terbatas',
                'message' => 'Support tidak memiliki akses ke data withdraw.',
            ];
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function forWithdraw(User $user, array $filters = []): array
    {
        $empty = $this->emptyWithdraw($user);
        if (! $empty['can_view']) {
            return $empty;
        }

        [$query, $normalizedFilters, $validationError] = $this->withdrawalQuery($filters);
        if ($validationError !== null || $query === null) {
            return $this->reportingFailure(
                array_replace($empty, ['filters' => $normalizedFilters]),
                KlikQrisReportingException::validation(),
                'withdraw',
            );
        }

        try {
            $cached = $this->rememberReport(
                'withdrawal-orders',
                $normalizedFilters,
                fn () => $this->client->withdrawalOrdersPage($query),
            );
            $report = $cached['report'];
            $fetchedAt = $cached['fetched_at'];
        } catch (KlikQrisReportingException $exception) {
            return $this->reportingFailure(
                array_replace($empty, ['filters' => $normalizedFilters]),
                $exception,
                'withdraw',
            );
        }

        return array_replace($empty, [
            'state' => 'ready',
            'filters' => $normalizedFilters,
            'last_fetched_at' => $fetchedAt?->toDateTimeString(),
            'last_fetched_at_label' => $fetchedAt?->format('d M Y H:i'),
            'stale' => $fetchedAt === null,
            'items' => array_map(
                fn (KlikQrisWithdrawalItem $item): array => $this->withdrawalItem($item),
                $report->items(),
            ),
            'pagination' => $this->pagination(
                $report->currentPage(),
                $report->lastPage(),
                $report->perPage(),
                $report->total(),
                'withdraw',
                $normalizedFilters,
            ),
        ]);
    }

    /**
     * @param  array<string, int|string|null>  $parameters
     * @param  Closure(): object  $callback
     * @return array{report: object, fetched_at: CarbonInterface}
     */
    private function rememberReport(string $operation, array $parameters, Closure $callback): array
    {
        $remember = fn (): array => [
            'report' => $callback(),
            'fetched_at' => now(),
        ];

        $entry = $this->reportingCacheSeconds() === 0
            ? $remember()
            : Cache::remember(
                $this->reportingCacheKey($operation, $parameters),
                $this->reportingCacheSeconds(),
                $remember,
            );

        if (
            ! is_array($entry)
            || ! isset($entry['report'])
            || ! is_object($entry['report'])
            || ! (($entry['fetched_at'] ?? null) instanceof CarbonInterface)
        ) {
            return $remember();
        }

        return [
            'report' => $entry['report'],
            'fetched_at' => $entry['fetched_at'],
        ];
    }

    /** @param array<string, int|string|null> $parameters */
    private function reportingCacheKey(string $operation, array $parameters): string
    {
        try {
            $serialized = json_encode($parameters, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw KlikQrisReportingException::validation();
        }

        return 'admin:klikqris:reporting:'.KlikQrisEnvironment::runtime()->value.':'.$operation.':'.hash('sha256', $serialized);
    }

    private function reportingCacheSeconds(): int
    {
        $value = config('services.klikqris.reporting_cache_seconds', 15);
        if (! is_numeric($value)) {
            return 15;
        }

        return min(300, max(0, (int) $value));
    }

    /**
     * @param  list<KlikQrisHistoryItem>  $items
     * @return array<string, Payment>
     */
    private function localPayments(array $items): array
    {
        $orderIds = collect($items)
            ->map(static fn (KlikQrisHistoryItem $item): string => $item->orderId())
            ->filter()
            ->unique()
            ->values();

        if ($orderIds->isEmpty()) {
            return [];
        }

        return Payment::query()
            ->with('order:id,invoice_number')
            ->where('provider', PaymentProvider::KLIKQRIS)
            ->whereIn('merchant_ref', $orderIds->all())
            ->get(['id', 'order_id', 'merchant_ref'])
            ->filter(static fn (Payment $payment): bool => $payment->order !== null)
            ->keyBy('merchant_ref')
            ->all();
    }

    /**
     * @param  array<string, Payment>  $localPayments
     * @return array<string, mixed>
     */
    private function historyItem(KlikQrisHistoryItem $item, array $localPayments): array
    {
        $payment = $localPayments[$item->orderId()] ?? null;
        $order = $payment?->order;

        return [
            'order_id' => $item->orderId(),
            'created_at' => $item->createdAt()->toDateTimeString(),
            'created_at_label' => $item->createdAt()->format('d M Y H:i'),
            'amount' => $item->totalAmount(),
            'amount_label' => Money::idr($item->totalAmount()),
            'status' => $item->status(),
            'status_label' => $this->statusLabel($item->status()),
            'status_tone' => $this->statusTone($item->status()),
            'type' => $item->type() ?? '-',
            'via' => $item->via() ?? '-',
            'note' => $this->safeNote($item->keterangan()),
            'local_match' => $order !== null,
            'local_invoice' => $order?->invoice_number,
            'local_order_url' => $order === null ? null : route('admin.orders.show', ['order' => $order]),
            'mismatch_message' => $order === null ? 'Tidak ada Order lokal yang cocok dengan provider order ID ini.' : null,
        ];
    }

    /** @return array<string, mixed> */
    private function withdrawalItem(KlikQrisWithdrawalItem $item): array
    {
        $proofUrl = $item->withdrawalProofUrl();

        return [
            'order_id' => $item->orderId(),
            'original_amount' => $item->originalAmount(),
            'original_amount_label' => Money::idr($item->originalAmount()),
            'amount_paid_uniq' => $item->amountPaidUniq(),
            'amount_paid_uniq_label' => Money::idr($item->amountPaidUniq()),
            'mdr' => $item->mdr(),
            'mdr_label' => Money::idr($item->mdr()),
            'net_amount_settled' => $item->netAmountSettled(),
            'net_amount_settled_label' => Money::idr($item->netAmountSettled()),
            'status' => $item->withdrawalStatus(),
            'status_label' => $this->statusLabel($item->withdrawalStatus()),
            'status_tone' => $this->statusTone($item->withdrawalStatus()),
            'withdrawal_code' => $item->withdrawalCode() ?? '-',
            'transaction_paid_at' => $this->dateLabel($item->transactionPaidAt()),
            'withdrawal_requested_at' => $this->dateLabel($item->withdrawalRequestedAt()),
            'withdrawal_processed_at' => $this->dateLabel($item->withdrawalProcessedAt()),
            'proof_url' => $proofUrl,
            'proof_label' => $proofUrl === null
                ? 'Tautan bukti transfer tidak tersedia sebagai tautan aman; ditampilkan sebagai teks'
                : 'Buka bukti transfer',
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{0: ?KlikQrisWithdrawalQuery, 1: array<string, ?int|string>, 2: ?string}
     */
    private function withdrawalQuery(array $filters): array
    {
        $invalid = false;
        $status = $this->optionalFilterText($filters['status'] ?? null, $invalid);
        $orderId = $this->optionalFilterText($filters['order_id'] ?? null, $invalid);
        $startDate = $this->optionalFilterText($filters['start_date'] ?? null, $invalid);
        $endDate = $this->optionalFilterText($filters['end_date'] ?? null, $invalid);
        $limit = $this->optionalFilterInteger($filters['limit'] ?? null, $invalid);
        $page = $this->normalizePage($filters['page'] ?? 1);
        $normalized = [
            'status' => $status,
            'order_id' => $orderId,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'limit' => $limit,
            'page' => $page,
        ];

        if ($invalid) {
            return [null, $normalized, 'One or more withdrawal filters are invalid.'];
        }

        try {
            $query = new KlikQrisWithdrawalQuery(
                status: $status,
                orderId: $orderId,
                startDate: $startDate,
                endDate: $endDate,
                limit: $limit,
                page: $page,
            );
        } catch (InvalidArgumentException) {
            return [null, $normalized, 'One or more withdrawal filters are invalid.'];
        }
        $normalized = [
            'status' => $query->status(),
            'order_id' => $query->orderId(),
            'start_date' => $query->startDate(),
            'end_date' => $query->endDate(),
            'limit' => $query->limit(),
            'page' => $query->page(),
        ];

        return [$query, $normalized, null];
    }

    private function optionalFilterText(mixed $value, bool &$invalid): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_scalar($value)) {
            $invalid = true;

            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function optionalFilterInteger(mixed $value, bool &$invalid): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) {
            $parsed = filter_var(trim($value), FILTER_VALIDATE_INT);
            if ($parsed !== false) {
                return (int) $parsed;
            }
        }

        $invalid = true;

        return null;
    }

    /** @return array<string, ?int|string> */
    private function emptyWithdrawalFilters(): array
    {
        return [
            'status' => null,
            'order_id' => null,
            'start_date' => null,
            'end_date' => null,
            'limit' => null,
            'page' => 1,
        ];
    }

    /** @return array<string, mixed> */
    private function reportingFailure(array $payload, KlikQrisReportingException $exception, string $tab): array
    {
        $notice = match ($exception->reason) {
            'readiness' => [
                'tone' => 'warning',
                'title' => 'KlikQRIS reporting tidak aktif',
                'message' => 'Provider belum siap atau sedang dinonaktifkan. Periksa konfigurasi sebelum memuat data.',
            ],
            'validation' => [
                'tone' => 'warning',
                'title' => 'Filter withdraw tidak valid',
                'message' => 'Periksa status, tanggal, limit, dan halaman lalu coba lagi.',
            ],
            default => [
                'tone' => 'danger',
                'title' => 'Data KlikQRIS belum dapat dimuat',
                'message' => 'Provider gagal merespons atau mengirim data yang tidak dapat dibaca. Muat ulang halaman untuk mencoba lagi.',
            ],
        };

        return array_replace($payload, [
            'state' => $exception->reason === 'readiness' ? 'disabled' : 'error',
            'notice' => $notice,
            'error_reason' => $exception->reason,
            'retry_url' => route('admin.dashboard', ['tab' => $tab]),
        ]);
    }

    /** @return array<string, mixed> */
    private function pagination(int $currentPage, int $lastPage, int $perPage, int $total, string $tab, array $parameters): array
    {
        $pageParameters = array_filter(
            $parameters,
            static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== [],
        );
        $from = $total === 0 ? null : (($currentPage - 1) * $perPage) + 1;
        $to = $total === 0 ? null : min($total, $currentPage * $perPage);

        return [
            'current_page' => $currentPage,
            'last_page' => $lastPage,
            'from' => $from,
            'to' => $to,
            'per_page' => $perPage,
            'total' => $total,
            'has_pages' => $currentPage > 1 || $currentPage < $lastPage,
            'previous_url' => $currentPage > 1
                ? $this->dashboardPageUrl($tab, $pageParameters, $currentPage - 1)
                : null,
            'next_url' => $currentPage < $lastPage
                ? $this->dashboardPageUrl($tab, $pageParameters, $currentPage + 1)
                : null,
        ];
    }

    /** @return array<string, mixed> */
    private function emptyPagination(): array
    {
        return [
            'current_page' => 1,
            'last_page' => 1,
            'from' => null,
            'to' => null,
            'per_page' => 0,
            'total' => 0,
            'has_pages' => false,
            'previous_url' => null,
            'next_url' => null,
        ];
    }

    /** @param array<string, ?int|string> $parameters */
    private function dashboardPageUrl(string $tab, array $parameters, int $page): string
    {
        return route('admin.dashboard', array_merge(['tab' => $tab], $parameters, ['page' => $page]));
    }

    private function normalizePage(mixed $value): int
    {
        if (is_int($value)) {
            return max(1, $value);
        }

        if (! is_string($value) || preg_match('/^\d+$/', trim($value)) !== 1) {
            return 1;
        }

        $parsed = filter_var(trim($value), FILTER_VALIDATE_INT);

        return $parsed === false ? 1 : max(1, (int) $parsed);
    }

    private function safeNote(?string $note): string
    {
        if ($note === null || trim($note) === '') {
            return '-';
        }

        $note = TelegramBotApiRedactor::redactText(trim($note));
        $note = preg_replace(
            '/\b(api[_-]?key|token|secret|signature|password)\s*=\s*[^\s&]+/i',
            '$1=[redacted-secret]',
            $note,
        ) ?? $note;
        $note = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $note) ?? $note;

        return mb_strimwidth($note, 0, 160, '...', 'UTF-8');
    }

    private function dateLabel(?CarbonInterface $date): string
    {
        return $date?->format('d M Y H:i') ?? '-';
    }

    private function statusLabel(string $status): string
    {
        return match (strtoupper($status)) {
            'SUCCESS' => 'Success',
            'PENDING' => 'Pending',
            'FAILED' => 'Failed',
            'EXPIRED' => 'Expired',
            default => ucfirst(strtolower(str_replace(['_', '-'], ' ', trim($status)))),
        };
    }

    private function statusTone(string $status): string
    {
        return match (strtoupper($status)) {
            'SUCCESS' => 'emerald',
            'PENDING' => 'amber',
            'FAILED', 'EXPIRED' => 'rose',
            default => 'slate',
        };
    }

    private function environmentLabel(): string
    {
        return app()->environment('production') ? 'production' : 'sandbox';
    }
}
