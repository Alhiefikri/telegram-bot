<?php

namespace App\Services\Admin;

use App\Enums\PaymentStatus;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\User;
use App\Storefront\StorefrontFunnelEvents;
use App\Support\Money;
use App\Support\PaymentProvider;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Read-only storefront reporting over the retained, sanitized audit boundary.
 *
 * Queries are always bounded to a maximum 31-day window; no analytics projection
 * or second write path is introduced, so audit retention remains canonical.
 */
final class StorefrontFunnelReport
{
    public const TAB = 'funnel';

    public const MAX_WINDOW_DAYS = 31;

    private const DEFAULT_WINDOW_DAYS = 30;

    private const EVENT_CHUNK_SIZE = 500;

    private const OPTION_LIMIT = 500;

    /** @var list<string> */
    private const RAILS = ['qris', 'binance_pay_id', 'usdt_bep20', 'other'];

    /** @var array<string, string> */
    private const RAIL_LABELS = [
        'qris' => 'QRIS',
        'binance_pay_id' => 'Binance Pay ID',
        'usdt_bep20' => 'USDT BEP20',
        'other' => 'Lainnya',
    ];

    /** @var array<string, string> */
    private const FAILURE_LABELS = [
        'checkout_rejected' => 'Checkout ditolak',
        'checkout_validation' => 'Validasi checkout',
        'cart_state' => 'Status keranjang',
        'stock_unavailable' => 'Stok tidak tersedia',
        'checkout_maintenance' => 'Checkout sedang ditutup',
        'qris_unavailable' => 'QRIS belum siap',
        'provider_unavailable' => 'Provider tidak tersedia',
        'other' => 'Lainnya',
    ];

    /** @var array<string, string> */
    private const SUPPORT_CATEGORY_LABELS = [
        'pre_purchase' => 'Sebelum membeli',
        'general' => 'Pertanyaan umum',
        'payment_pending' => 'Pembayaran belum terdeteksi',
        'payment_final' => 'Pembayaran gagal/terminal',
        'credentials_missing' => 'Data akun belum muncul',
        'credentials_invalid' => 'Kredensial tidak valid',
        'mfa_problem' => 'MFA bermasalah',
        'login_failed' => 'Login gagal',
        'other' => 'Lainnya',
    ];

    /** @var array<string, string> */
    private const QRIS_OUTCOME_LABELS = [
        'created' => 'Berhasil dibuat',
        'creating' => 'Sedang dibuat',
        'recovery_pending' => 'Menunggu recovery',
        'provider_total_pending' => 'Total provider belum final',
        'expired' => 'Expired',
        'failed' => 'Gagal',
        'other' => 'Lainnya',
    ];

    public function canView(User $user): bool
    {
        return (bool) $user->role?->canViewStorefrontAnalytics();
    }

    /** @return array<string, mixed> */
    public function empty(User $user): array
    {
        $canView = $this->canView($user);

        return [
            'state' => $canView ? 'inactive' : 'forbidden',
            'can_view' => $canView,
            'filters' => $this->defaultFilters(),
            'filter_options' => $this->emptyFilterOptions(),
            'summary' => $this->emptySummary(),
            'products' => [],
            'families' => [],
            'rails' => [],
            'sources' => [],
            'failure_categories' => [],
            'support_categories' => [],
            'qris_outcomes' => [],
            'notice' => $canView ? null : [
                'tone' => 'warning',
                'title' => 'Akses terbatas',
                'message' => 'Role ini tidak memiliki akses ke analytics storefront.',
            ],
        ];
    }

    /** @param array<string, mixed> $filters */
    /** @return array<string, mixed> */
    public function forAdmin(User $user, array $filters = []): array
    {
        $empty = $this->empty($user);
        if (! $empty['can_view']) {
            return $empty;
        }

        $normalized = $this->normalizeFilters($filters);
        if ($normalized['error'] !== null || $normalized['start'] === null || $normalized['end'] === null) {
            return array_replace($empty, [
                'state' => 'error',
                'filters' => $normalized['filters'],
                'notice' => [
                    'tone' => 'warning',
                    'title' => 'Filter analytics tidak valid',
                    'message' => 'Gunakan tanggal maksimal 31 hari dan pilihan filter yang tersedia.',
                ],
            ]);
        }

        $context = $this->loadContext($normalized['start'], $normalized['end']);
        $report = $this->aggregate($context, $normalized['filters']);

        return array_replace($empty, $report, [
            'state' => 'ready',
            'filters' => $normalized['filters'],
            'filter_options' => $this->filterOptions($context, $normalized['filters']),
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{filters: array<string, mixed>, start: ?CarbonImmutable, end: ?CarbonImmutable, error: ?string}
     */
    private function normalizeFilters(array $filters): array
    {
        $invalid = false;
        $timezone = (string) config('app.timezone', 'UTC');
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $defaultStart = $today->subDays(self::DEFAULT_WINDOW_DAYS - 1);
        $defaultEnd = $today;

        $start = $this->parseDate($filters['start_date'] ?? null, $invalid);
        $end = $this->parseDate($filters['end_date'] ?? null, $invalid);

        if ($start === null && $end === null) {
            $start = $defaultStart;
            $end = $defaultEnd;
        } elseif ($start === null && $end !== null) {
            $start = $end->subDays(self::DEFAULT_WINDOW_DAYS - 1);
        } elseif ($start !== null && $end === null) {
            $end = $start->addDays(self::DEFAULT_WINDOW_DAYS - 1);
        }

        if ($start !== null && $end !== null) {
            $days = $start->diffInDays($end) + 1;
            if ($start->greaterThan($end) || $days > self::MAX_WINDOW_DAYS) {
                $invalid = true;
            }
        }

        $productId = $this->optionalInteger($filters['product_id'] ?? null, $invalid);
        $familyId = $this->optionalInteger($filters['family_id'] ?? null, $invalid);
        $categoryId = $this->optionalCategory($filters['category_id'] ?? null, $invalid);
        $provider = $this->optionalText($filters['provider'] ?? null, $invalid);
        $rail = $this->optionalText($filters['rail'] ?? null, $invalid);
        $source = $this->optionalAttributionText($filters['source'] ?? null, $invalid);
        $utmSource = $this->optionalAttributionText($filters['utm_source'] ?? null, $invalid);
        $utmMedium = $this->optionalAttributionText($filters['utm_medium'] ?? null, $invalid);
        $utmCampaign = $this->optionalAttributionText($filters['utm_campaign'] ?? null, $invalid);

        if ($provider !== null) {
            $provider = strtolower($provider);
            if (! in_array($provider, PaymentProvider::known(), true)) {
                $invalid = true;
            }
        }
        if ($rail !== null) {
            $rail = strtolower($rail);
            if (! in_array($rail, self::RAILS, true)) {
                $invalid = true;
            }
        }

        return [
            'filters' => [
                'start_date' => $start?->toDateString() ?? $defaultStart->toDateString(),
                'end_date' => $end?->toDateString() ?? $defaultEnd->toDateString(),
                'product_id' => $productId,
                'family_id' => $familyId,
                'category_id' => $categoryId,
                'provider' => $provider,
                'rail' => $rail,
                'source' => $source,
                'utm_source' => $utmSource,
                'utm_medium' => $utmMedium,
                'utm_campaign' => $utmCampaign,
            ],
            'start' => $invalid ? null : $start,
            'end' => $invalid ? null : $end,
            'error' => $invalid ? 'invalid' : null,
        ];
    }

    private function parseDate(mixed $value, bool &$invalid): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            $invalid = true;

            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, (string) config('app.timezone', 'UTC'));
        } catch (\Throwable) {
            $invalid = true;

            return null;
        }

        if (! $date instanceof CarbonImmutable || $date->format('Y-m-d') !== $value) {
            $invalid = true;

            return null;
        }

        return $date->startOfDay();
    }

    private function optionalInteger(mixed $value, bool &$invalid): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^\d+$/D', trim($value)) === 1) {
            $parsed = filter_var(trim($value), FILTER_VALIDATE_INT);
            if ($parsed !== false && $parsed > 0) {
                return (int) $parsed;
            }
        }

        $invalid = true;

        return null;
    }

    private function optionalCategory(mixed $value, bool &$invalid): int|string|null
    {
        if ($value === 'uncategorized') {
            return $value;
        }

        return $this->optionalInteger($value, $invalid);
    }

    private function optionalText(mixed $value, bool &$invalid): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_scalar($value)) {
            $invalid = true;

            return null;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > 80 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            $invalid = true;

            return null;
        }

        return $value;
    }

    private function optionalAttributionText(mixed $value, bool &$invalid): ?string
    {
        $value = $this->optionalText($value, $invalid);
        if ($value === null) {
            return null;
        }
        if (preg_match('/^[A-Za-z0-9._~%+\/:\-]{1,80}$/D', $value) !== 1
            || preg_match('/^\+?\d[\d-]{6,}$/D', $value) === 1) {
            $invalid = true;

            return null;
        }

        return $value;
    }

    /** @return array<string, string|null> */
    private function defaultFilters(): array
    {
        $today = CarbonImmutable::now((string) config('app.timezone', 'UTC'))->startOfDay();

        return [
            'start_date' => $today->subDays(self::DEFAULT_WINDOW_DAYS - 1)->toDateString(),
            'end_date' => $today->toDateString(),
            'product_id' => null,
            'family_id' => null,
            'category_id' => null,
            'provider' => null,
            'rail' => null,
            'source' => null,
            'utm_source' => null,
            'utm_medium' => null,
            'utm_campaign' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function emptyFilterOptions(): array
    {
        return [
            'products' => [],
            'families' => [],
            'categories' => [],
            'providers' => $this->providerOptions(),
            'rails' => $this->railOptions(),
            'sources' => [],
            'utm_sources' => [],
            'utm_mediums' => [],
            'utm_campaigns' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function emptySummary(): array
    {
        return $this->bucketMetrics($this->newBucket());
    }

    /** @return array<string, int|float|array<string, mixed>|null> */
    private function newBucket(): array
    {
        return [
            'views' => 0,
            'family_views' => 0,
            'checkout_opens' => 0,
            'checkout_submits' => 0,
            'accepted_checkouts' => 0,
            'rejected_checkouts' => 0,
            'other_checkout_outcomes' => 0,
            'qris_created' => 0,
            'support_selections' => 0,
            'provider_creation_attempts' => 0,
            '_session_hashes' => [],
            '_paid_order_ids' => [],
            '_amounts' => [],
            '_customer_counts' => [],
            '_failure_categories' => [],
        ];
    }

    /**
     * @return array{events: list<array<string, mixed>>, event_order_ids: list<string>}
     */
    private function loadEvents(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $events = [];
        $orderIds = [];

        AuditLog::query()
            ->select(['id', 'action', 'subject_type', 'subject_id', 'new_values', 'created_at'])
            ->whereIn('action', StorefrontFunnelEvents::ACTIONS)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end->addDay())
            ->orderBy('id')
            ->chunkById(self::EVENT_CHUNK_SIZE, function (Collection $logs) use (&$events, &$orderIds): void {
                foreach ($logs as $log) {
                    $values = is_array($log->new_values) ? $log->new_values : [];
                    $subjectType = (string) $log->subject_type;
                    $subjectId = $this->safeEventText($log->subject_id, 80);
                    $productId = $this->positiveId($values['product_id'] ?? null);
                    if ($productId === null && $this->isSubjectType($subjectType, Product::class)) {
                        $productId = $this->positiveId($subjectId);
                    }
                    $familyId = $this->positiveId($values['family_id'] ?? null);
                    $familyName = $familyId === null
                        ? null
                        : $this->safeEventText($values['family_name'] ?? null, 160);

                    $orderId = $this->safeEventText($values['order_id'] ?? null, 80);
                    if ($orderId === null && $this->isSubjectType($subjectType, Order::class)) {
                        $orderId = $subjectId;
                    }
                    if ($orderId !== null) {
                        $orderIds['o'.$orderId] = $orderId;
                    }
                    $event = [
                        'action' => (string) $log->action,
                        'product_id' => $productId,
                        'family_id' => $familyId,
                        'family_name' => $familyName,
                        'order_id' => $orderId,
                        'provider' => $this->safeEventText($values['provider'] ?? null, 40),
                        'payment_method' => $this->safeEventText($values['payment_method'] ?? null, 40),
                        'provider_create_state' => $this->safeEventText($values['provider_create_state'] ?? null, 80),
                        'session_hash' => $this->safeSessionHash($values['session_hash'] ?? null),
                        'source' => $this->safeAttributionText($values['source'] ?? null) ?? 'direct',
                        'utm_source' => $this->safeAttributionText($values['utm_source'] ?? null),
                        'utm_medium' => $this->safeAttributionText($values['utm_medium'] ?? null),
                        'utm_campaign' => $this->safeAttributionText($values['utm_campaign'] ?? null),
                        'support_category' => $this->safeEventText($values['support_category'] ?? null, 80),
                        'support_channel' => $this->safeEventText($values['support_channel'] ?? null, 40),
                        'status_endpoint_result' => $this->safeEventText($values['status_endpoint_result'] ?? null, 40),
                        'failure_kind' => $this->safeEventText($values['failure_kind'] ?? null, 120),
                    ];
                    $events[] = $event;
                }
            });

        return [
            'events' => $events,
            'event_order_ids' => array_values($orderIds),
        ];
    }

    /** @return array<string, mixed> */
    private function loadContext(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $eventContext = $this->loadEvents($start, $end);
        $payments = $this->loadPayments($eventContext['event_order_ids'], $start, $end);
        $paymentOrderIds = $payments->pluck('order_id')->map(fn ($id): string => (string) $id)->all();
        $orderIds = array_values(array_unique([...$eventContext['event_order_ids'], ...$paymentOrderIds]));
        $orders = $this->loadOrders($orderIds);

        $productIds = [];
        foreach ($eventContext['events'] as $event) {
            if ($event['product_id'] !== null) {
                $productIds[(int) $event['product_id']] = true;
            }
        }
        $productNames = [];
        foreach ($orders as $order) {
            foreach ($order->orderItems as $item) {
                $id = $this->positiveId($item->product_id);
                if ($id === null) {
                    continue;
                }
                $productIds[$id] = true;
                $productNames[$id] ??= $this->safeEventText($item->product_name, 160);
            }
        }

        $products = $this->loadProducts(array_keys($productIds));
        $categories = $this->loadCategories(array_values(array_unique(array_filter(array_map(
            static fn (Product $product): ?int => $product->category_id === null ? null : (int) $product->category_id,
            $products,
        ), static fn (?int $id): bool => $id !== null))));

        $paymentsByOrder = $payments
            ->groupBy(fn (Payment $payment): string => (string) $payment->order_id)
            ->map(fn (Collection $items): Payment => $this->preferredPayment($items))
            ->all();
        $paidPayments = $payments
            ->filter(fn (Payment $payment): bool => $this->isPaidPaymentInRange($payment, $start, $end))
            ->groupBy(fn (Payment $payment): string => (string) $payment->order_id)
            ->map(fn (Collection $items): Payment => $items->sortBy('paid_at')->first())
            ->all();

        return [
            'start' => $start,
            'end' => $end,
            'events' => $eventContext['events'],
            'payments' => $payments,
            'paid_payments' => $paidPayments,
            'payments_by_order' => $paymentsByOrder,
            'orders' => $orders,
            'products' => $products,
            'categories' => $categories,
            'product_names' => $productNames,
            'catalog_products' => Product::withTrashed()
                ->select(['id', 'name', 'category_id', 'is_active', 'deleted_at'])
                ->orderBy('name')
                ->orderBy('id')
                ->limit(self::OPTION_LIMIT)
                ->get(),
            'catalog_families' => ProductFamily::withTrashed()
                ->select(['id', 'name', 'slug', 'deleted_at'])
                ->orderBy('name')
                ->orderBy('id')
                ->limit(self::OPTION_LIMIT)
                ->get(),
            'catalog_categories' => Category::withTrashed()
                ->select(['id', 'name', 'is_active', 'deleted_at'])
                ->orderBy('name')
                ->orderBy('id')
                ->limit(self::OPTION_LIMIT)
                ->get(),
        ];
    }

    /** @return Collection<int, Payment> */
    private function loadPayments(array $eventOrderIds, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        $endExclusive = $end->addDay();
        $columns = [
            'id', 'order_id', 'provider', 'method', 'provider_payment_method',
            'provider_create_state', 'status', 'amount', 'currency', 'paid_at', 'created_at',
        ];

        $payments = Payment::query()
            ->select($columns)
            ->where(function (Builder $query) use ($start, $endExclusive): void {
                $query
                    ->where(function (Builder $created) use ($start, $endExclusive): void {
                        $created
                            ->where('created_at', '>=', $start)
                            ->where('created_at', '<', $endExclusive);
                    })
                    ->orWhere(function (Builder $paid) use ($start, $endExclusive): void {
                        $paid
                            ->where('paid_at', '>=', $start)
                            ->where('paid_at', '<', $endExclusive);
                    });
            })
            ->orderBy('created_at')
            ->get();

        foreach (array_chunk($eventOrderIds, self::EVENT_CHUNK_SIZE) as $orderIdChunk) {
            $payments = $payments->merge(
                Payment::query()->select($columns)->whereIn('order_id', $orderIdChunk)->get(),
            );
        }

        return $payments
            ->unique(fn (Payment $payment): string => (string) $payment->getKey())
            ->sortBy('created_at')
            ->values();
    }

    /** @return array<string, Order> */
    private function loadOrders(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        $orders = collect();
        foreach (array_chunk($orderIds, self::EVENT_CHUNK_SIZE) as $orderIdChunk) {
            $orders = $orders->merge(
                Order::query()
                    ->select(['id', 'customer_id', 'total_amount', 'currency', 'payment_status', 'order_status', 'created_at'])
                    ->with(['orderItems' => fn ($query) => $query->select(['id', 'order_id', 'product_id', 'product_name', 'family_id', 'family_name', 'subtotal'])])
                    ->whereIn('id', $orderIdChunk)
                    ->get(),
            );
        }

        return $orders
            ->keyBy(fn (Order $order): string => (string) $order->getKey())
            ->all();
    }

    /** @return array<int, Product> */
    private function loadProducts(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $products = collect();
        foreach (array_chunk($productIds, self::EVENT_CHUNK_SIZE) as $productIdChunk) {
            $products = $products->merge(
                Product::withTrashed()
                    ->select(['id', 'name', 'category_id', 'is_active', 'deleted_at'])
                    ->whereIn('id', $productIdChunk)
                    ->get(),
            );
        }

        return $products
            ->keyBy(fn (Product $product): int => (int) $product->getKey())
            ->all();
    }

    /** @return array<int, Category> */
    private function loadCategories(array $categoryIds): array
    {
        if ($categoryIds === []) {
            return [];
        }

        $categories = collect();
        foreach (array_chunk($categoryIds, self::EVENT_CHUNK_SIZE) as $categoryIdChunk) {
            $categories = $categories->merge(
                Category::withTrashed()
                    ->select(['id', 'name', 'is_active', 'deleted_at'])
                    ->whereIn('id', $categoryIdChunk)
                    ->get(),
            );
        }

        return $categories
            ->keyBy(fn (Category $category): int => (int) $category->getKey())
            ->all();
    }

    private function preferredPayment(Collection $items): Payment
    {
        return $items
            ->sortByDesc(fn (Payment $payment): int => $this->paymentStatus($payment) === PaymentStatus::Paid->value ? 1 : 0)
            ->sortByDesc(fn (Payment $payment): int => $payment->created_at?->getTimestamp() ?? 0)
            ->first();
    }

    private function isPaidPaymentInRange(Payment $payment, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        if ($this->paymentStatus($payment) !== PaymentStatus::Paid->value || $payment->paid_at === null) {
            return false;
        }

        return $payment->paid_at->greaterThanOrEqualTo($start)
            && $payment->paid_at->lessThan($end->addDay());
    }

    /** @param array<string, mixed> $context */
    /** @param array<string, mixed> $filters */
    /** @return array<string, mixed> */
    private function aggregate(array $context, array $filters): array
    {
        $overall = $this->newBucket();
        $productBuckets = [];
        $familyBuckets = [];
        $railBuckets = [];
        $sourceBuckets = [];
        $failureCounts = [];
        $supportCounts = [];
        $qrisOutcomes = [];
        $matchedEventsByOrder = [];

        foreach ($context['events'] as $event) {
            $order = $event['order_id'] === null ? null : ($context['orders'][$event['order_id']] ?? null);
            $payment = $this->eventPayment($event, $context['payments_by_order']);
            $productIds = $this->eventProductIds($event, $order);
            if (! $this->matchesEvent($event, $productIds, $payment, $order, $context['products'], $filters, $matchedEventsByOrder)) {
                continue;
            }

            $this->recordEvent($overall, $event, collect: true, failureCounts: $failureCounts, supportCounts: $supportCounts);
            foreach ($productIds as $productId) {
                $bucketKey = 'p'.$productId;
                if (! isset($productBuckets[$bucketKey])) {
                    $productBuckets[$bucketKey] = $this->newProductBucket($productId, $context);
                }
                $this->recordEvent($productBuckets[$bucketKey], $event, collect: false);
            }
            if (($event['family_id'] ?? null) !== null) {
                $familyKey = $this->familyBucketKey((int) $event['family_id'], $event['family_name'] ?? null);
                if (! isset($familyBuckets[$familyKey])) {
                    $familyBuckets[$familyKey] = $this->newFamilyBucket(
                        (int) $event['family_id'],
                        $event['family_name'] ?? null,
                    );
                }
                $this->recordEvent($familyBuckets[$familyKey], $event, collect: false);
            }

            $provider = $this->eventProvider($event, $payment);
            $rail = $this->paymentRail($provider, $event['payment_method'] ?? $payment?->method);
            if ($provider !== null && $rail !== null) {
                $railKey = $provider.'|'.$rail;
                if (! isset($railBuckets[$railKey])) {
                    $railBuckets[$railKey] = $this->newRailBucket($provider, $rail);
                }
                $this->recordEvent($railBuckets[$railKey], $event, collect: false);
            }

            $sourceKey = $this->sourceKey($event);
            if (! isset($sourceBuckets[$sourceKey])) {
                $sourceBuckets[$sourceKey] = $this->newSourceBucket($event);
            }
            $this->recordEvent($sourceBuckets[$sourceKey], $event, collect: false);

            if ($event['order_id'] !== null) {
                $matchedEventsByOrder[$event['order_id']][] = $event;
            }
        }

        foreach ($context['payments'] as $payment) {
            if (! filled($payment->provider_create_state) || ! $this->paymentCreatedInRange($payment, $context['start'], $context['end'])) {
                continue;
            }

            $order = $context['orders'][(string) $payment->order_id] ?? null;
            $provider = $this->normalizeProvider($payment->provider);
            $rail = $this->paymentRail($provider, $payment->method);
            if (! $this->matchesCanonicalOrder($order, $payment, $rail, $context['products'], $filters, $matchedEventsByOrder)) {
                continue;
            }

            $outcome = $this->qrisOutcome((string) $payment->provider_create_state);
            $outcomeKey = $provider.'|'.$outcome;
            if (! isset($qrisOutcomes[$outcomeKey])) {
                $qrisOutcomes[$outcomeKey] = [
                    'provider' => $provider,
                    'provider_label' => $this->providerLabel($provider),
                    'outcome' => $outcome,
                    'outcome_label' => self::QRIS_OUTCOME_LABELS[$outcome] ?? self::QRIS_OUTCOME_LABELS['other'],
                    'count' => 0,
                ];
            }
            $qrisOutcomes[$outcomeKey]['count']++;

            if ($rail !== null) {
                $railKey = $provider.'|'.$rail;
                if (! isset($railBuckets[$railKey])) {
                    $railBuckets[$railKey] = $this->newRailBucket($provider, $rail);
                }
                $railBuckets[$railKey]['provider_creation_attempts']++;
            }
        }

        foreach ($context['paid_payments'] as $payment) {
            $orderId = (string) $payment->order_id;
            $order = $context['orders'][$orderId] ?? null;
            $provider = $this->normalizeProvider($payment->provider);
            $rail = $this->paymentRail($provider, $payment->method);
            if (! $this->matchesCanonicalOrder($order, $payment, $rail, $context['products'], $filters, $matchedEventsByOrder)) {
                continue;
            }
            if (! $order instanceof Order) {
                continue;
            }

            $this->recordPaid($overall, $order);
            $productIds = $this->orderProductIds($order);
            foreach ($productIds as $productId) {
                $bucketKey = 'p'.$productId;
                if (! isset($productBuckets[$bucketKey])) {
                    $productBuckets[$bucketKey] = $this->newProductBucket($productId, $context);
                }
                $this->recordPaid($productBuckets[$bucketKey], $order);
            }
            $familySnapshots = [
                ...$this->familySnapshots($matchedEventsByOrder[$orderId] ?? [], $filters['family_id'] ?? null),
                ...$this->familySnapshotsForOrder($order, $filters['family_id'] ?? null),
            ];
            $uniqueFamilySnapshots = [];
            foreach ($familySnapshots as $snapshot) {
                $uniqueFamilySnapshots[$snapshot['id'].'|'.($snapshot['name'] ?? '')] = $snapshot;
            }
            foreach ($uniqueFamilySnapshots as $snapshot) {
                $familyKey = $this->familyBucketKey($snapshot['id'], $snapshot['name']);
                if (! isset($familyBuckets[$familyKey])) {
                    $familyBuckets[$familyKey] = $this->newFamilyBucket($snapshot['id'], $snapshot['name']);
                }
                $this->recordPaid($familyBuckets[$familyKey], $order);
            }

            if ($provider !== null && $rail !== null) {
                $railKey = $provider.'|'.$rail;
                if (! isset($railBuckets[$railKey])) {
                    $railBuckets[$railKey] = $this->newRailBucket($provider, $rail);
                }
                $this->recordPaid($railBuckets[$railKey], $order);
            }

            $sourceEvents = [];
            foreach ($matchedEventsByOrder[$orderId] ?? [] as $event) {
                $sourceEvents[$this->sourceKey($event)] ??= $event;
            }
            foreach ($sourceEvents as $sourceKey => $sourceEvent) {
                if (! isset($sourceBuckets[$sourceKey])) {
                    $sourceBuckets[$sourceKey] = $this->newSourceBucket($sourceEvent);
                }
                $this->recordPaid($sourceBuckets[$sourceKey], $order);
            }
        }

        return [
            'summary' => $this->bucketMetrics($overall),
            'products' => $this->finalizeProductBuckets($productBuckets),
            'families' => $this->finalizeFamilyBuckets($familyBuckets),
            'rails' => $this->finalizeRailBuckets($railBuckets),
            'sources' => $this->finalizeSourceBuckets($sourceBuckets),
            'failure_categories' => $this->finalizeCountBuckets($failureCounts, self::FAILURE_LABELS),
            'support_categories' => $this->finalizeSupportBuckets($supportCounts),
            'qris_outcomes' => $this->finalizeQrisOutcomes($qrisOutcomes),
        ];
    }

    /** @param array<string, mixed> $event */
    /** @param array<int, int> $productIds */
    /** @param array<string, mixed> $filters */
    /** @param array<string, list<array<string, mixed>>> $matchedEventsByOrder */
    private function matchesEvent(array $event, array $productIds, ?Payment $payment, ?Order $order, array $products, array $filters, array &$matchedEventsByOrder): bool
    {
        if (! $this->matchesProductFilter($productIds, $filters['product_id'] ?? null)) {
            return false;
        }
        if (! $this->matchesFamilyFilter($event['family_id'] ?? null, $filters['family_id'] ?? null)) {
            return false;
        }
        if (! $this->matchesCategoryFilter($productIds, $filters['category_id'] ?? null, $products)) {
            return false;
        }

        $provider = $this->eventProvider($event, $payment);
        $rail = $this->paymentRail($provider, $event['payment_method'] ?? $payment?->method);
        if (($filters['provider'] ?? null) !== null && $provider !== $filters['provider']) {
            return false;
        }
        if (($filters['rail'] ?? null) !== null && $rail !== $filters['rail']) {
            return false;
        }
        if (! $this->matchesAttribution($event, $filters)) {
            return false;
        }

        if ($order instanceof Order && $event['order_id'] !== null) {
            $matchedEventsByOrder[$event['order_id']] ??= [];
        }

        return true;
    }

    /** @param array<string, mixed> $filters */
    /** @param array<string, list<array<string, mixed>>> $matchedEventsByOrder */
    private function matchesCanonicalOrder(?Order $order, Payment $payment, ?string $rail, array $products, array $filters, array $matchedEventsByOrder): bool
    {
        if (! $order instanceof Order) {
            return false;
        }
        $productIds = $this->orderProductIds($order);
        $familyFilter = $filters['family_id'] ?? null;
        $familyMatches = $familyFilter === null
            || $this->familySnapshots($matchedEventsByOrder[(string) $order->getKey()] ?? [], $familyFilter) !== []
            || $this->familySnapshotsForOrder($order, $familyFilter) !== [];
        if (! $this->matchesProductFilter($productIds, $filters['product_id'] ?? null)
            || ! $this->matchesCategoryFilter($productIds, $filters['category_id'] ?? null, $products)
            || ! $familyMatches) {
            return false;
        }

        $provider = $this->normalizeProvider($payment->provider);
        if (($filters['provider'] ?? null) !== null && $provider !== $filters['provider']) {
            return false;
        }
        if (($filters['rail'] ?? null) !== null && $rail !== $filters['rail']) {
            return false;
        }
        if ($this->hasAttributionFilters($filters) && ! isset($matchedEventsByOrder[(string) $order->getKey()])) {
            return false;
        }

        return true;
    }

    private function matchesFamilyFilter(mixed $eventFamilyId, int|string|null $filter): bool
    {
        return $filter === null || ($eventFamilyId !== null && (int) $eventFamilyId === (int) $filter);
    }

    /** @param list<int> $productIds */
    private function matchesProductFilter(array $productIds, int|string|null $filter): bool
    {
        return $filter === null || in_array((int) $filter, $productIds, true);
    }

    /** @param list<int> $productIds */
    private function matchesCategoryFilter(array $productIds, int|string|null $filter, array $products): bool
    {
        if ($filter === null) {
            return true;
        }

        foreach ($productIds as $productId) {
            $product = $products[$productId] ?? null;
            $categoryId = $product?->category_id === null ? null : (int) $product->category_id;
            if ($filter === 'uncategorized' && $categoryId === null) {
                return true;
            }
            if (is_int($filter) && $categoryId === $filter) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $event */
    private function matchesAttribution(array $event, array $filters): bool
    {
        foreach (['source', 'utm_source', 'utm_medium', 'utm_campaign'] as $field) {
            if (($filters[$field] ?? null) !== null && ($event[$field] ?? null) !== $filters[$field]) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $bucket */
    /** @param array<string, int>|null $failureCounts */
    /** @param array<string, array{count:int, channels:array<string, int>}>|null $supportCounts */
    private function recordEvent(array &$bucket, array $event, bool $collect = false, ?array &$failureCounts = null, ?array &$supportCounts = null): void
    {
        $action = $event['action'];
        if ($action === 'storefront.family_opened') {
            $bucket['family_views']++;
            if ($event['session_hash'] !== null) {
                $bucket['_session_hashes']['s'.$event['session_hash']] = true;
            }
        } elseif ($action === 'storefront.product_opened') {
            $bucket['views']++;
            if ($event['session_hash'] !== null) {
                $bucket['_session_hashes']['s'.$event['session_hash']] = true;
            }
        } elseif ($action === 'storefront.checkout_opened') {
            $bucket['checkout_opens']++;
        } elseif ($action === 'storefront.checkout_submitted') {
            $bucket['checkout_submits']++;
            $status = strtolower((string) ($event['status_endpoint_result'] ?? ''));
            if ($status === 'accepted') {
                $bucket['accepted_checkouts']++;
            } elseif ($status === 'rejected') {
                $bucket['rejected_checkouts']++;
            } else {
                $bucket['other_checkout_outcomes']++;
            }
            $failure = $this->failureCategory($event);
            if ($failure !== null) {
                $bucket['_failure_categories'][$failure] = ($bucket['_failure_categories'][$failure] ?? 0) + 1;
                if ($collect && $failureCounts !== null) {
                    $failureCounts[$failure] = ($failureCounts[$failure] ?? 0) + 1;
                }
            }
        } elseif ($action === 'storefront.qris_created') {
            $bucket['qris_created']++;
        } elseif ($action === 'storefront.support_selected') {
            $bucket['support_selections']++;
            if ($collect && $supportCounts !== null) {
                $category = $this->supportCategory((string) ($event['support_category'] ?? ''));
                $supportCounts[$category] ??= ['count' => 0, 'channels' => []];
                $supportCounts[$category]['count']++;
                $channel = in_array($event['support_channel'], ['whatsapp', 'telegram_admin'], true)
                    ? (string) $event['support_channel']
                    : 'other';
                $supportCounts[$category]['channels'][$channel] = ($supportCounts[$category]['channels'][$channel] ?? 0) + 1;
            }
        }
    }

    private function recordPaid(array &$bucket, Order $order): void
    {
        $key = 'o'.$order->getKey();
        if (isset($bucket['_paid_order_ids'][$key])) {
            return;
        }

        $bucket['_paid_order_ids'][$key] = true;
        $customerKey = 'c'.$order->customer_id;
        $bucket['_customer_counts'][$customerKey] = ($bucket['_customer_counts'][$customerKey] ?? 0) + 1;
        if (strtoupper((string) $order->currency) === 'IDR') {
            $bucket['_amounts'][$key] = (int) $order->total_amount;
        }
    }

    /** @return list<int> */
    private function eventProductIds(array $event, ?Order $order): array
    {
        if ($event['product_id'] !== null) {
            $productId = (int) $event['product_id'];
            if (! $order instanceof Order || in_array($productId, $this->orderProductIds($order), true)) {
                return [$productId];
            }
        }

        return $order instanceof Order ? $this->orderProductIds($order) : [];
    }

    /** @return list<int> */
    private function orderProductIds(Order $order): array
    {
        return $order->orderItems
            ->map(fn ($item): ?int => $this->positiveId($item->product_id))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @return list<array{id:int,name:?string}>
     */
    private function familySnapshots(array $events, int|string|null $filter = null): array
    {
        $snapshots = [];
        foreach ($events as $event) {
            $id = $this->positiveId($event['family_id'] ?? null);
            if ($id === null || ! $this->matchesFamilyFilter($id, $filter)) {
                continue;
            }
            $name = $this->safeEventText($event['family_name'] ?? null, 160);
            $key = $id.'|'.($name ?? '');
            $snapshots[$key] = ['id' => $id, 'name' => $name];
        }

        return array_values($snapshots);
    }

    /** @return list<array{id:int,name:?string}> */
    private function familySnapshotsForOrder(Order $order, int|string|null $filter = null): array
    {
        $snapshots = [];
        foreach ($order->orderItems as $item) {
            $id = $this->positiveId($item->family_id ?? null);
            if ($id === null || ! $this->matchesFamilyFilter($id, $filter)) {
                continue;
            }
            $name = $this->safeEventText($item->family_name ?? null, 160);
            $snapshots[$id.'|'.($name ?? '')] = ['id' => $id, 'name' => $name];
        }

        return array_values($snapshots);
    }

    private function familyBucketKey(int $familyId, ?string $familyName): string
    {
        return 'f'.$familyId.'|'.sha1((string) $familyName);
    }

    /** @param array<string, mixed> $event */
    /** @param array<string, Payment> $paymentsByOrder */
    private function eventPayment(array $event, array $paymentsByOrder): ?Payment
    {
        return $event['order_id'] === null ? null : ($paymentsByOrder[$event['order_id']] ?? null);
    }

    /** @param array<string, mixed> $event */
    private function eventProvider(array $event, ?Payment $payment): ?string
    {
        $provider = $event['provider'] ?? null;

        return $provider !== null ? $this->normalizeProvider($provider) : ($payment ? $this->normalizeProvider($payment->provider) : null);
    }

    private function paymentRail(?string $provider, mixed $method): ?string
    {
        $method = strtoupper(trim((string) $method));
        if (in_array($method, ['QRIS', 'QR_MANUAL'], true)) {
            return 'qris';
        }
        if ($method === 'BINANCE_PAY_ID') {
            return 'binance_pay_id';
        }
        if ($method === 'USDT_BEP20') {
            return 'usdt_bep20';
        }
        if ($method === '' && in_array($provider, [PaymentProvider::KLIKQRIS, PaymentProvider::PAKASIR], true)) {
            return 'qris';
        }

        return 'other';
    }

    private function normalizeProvider(mixed $provider): string
    {
        $provider = strtolower(trim((string) $provider));

        return in_array($provider, PaymentProvider::known(), true) ? $provider : 'other';
    }

    private function paymentStatus(Payment $payment): string
    {
        return $payment->status instanceof PaymentStatus ? $payment->status->value : strtolower((string) $payment->status);
    }

    private function paymentCreatedInRange(Payment $payment, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        if ($payment->created_at === null) {
            return false;
        }

        return $payment->created_at->greaterThanOrEqualTo($start)
            && $payment->created_at->lessThan($end->addDay());
    }

    private function failureCategory(array $event): ?string
    {
        $status = strtolower((string) ($event['status_endpoint_result'] ?? ''));
        $kind = strtolower((string) ($event['failure_kind'] ?? ''));
        if (! in_array($status, ['rejected', 'provider_failure', 'qris_not_ready'], true) && $kind === '') {
            return null;
        }
        if ($kind === '' && $status === 'rejected') {
            return 'checkout_rejected';
        }
        if (str_contains($kind, 'insufficientstock')) {
            return 'stock_unavailable';
        }
        if (str_contains($kind, 'invalidcartstate')) {
            return 'cart_state';
        }
        if (str_contains($kind, 'checkoutmaintenance')) {
            return 'checkout_maintenance';
        }
        if (str_contains($kind, 'invalidcheckout')) {
            return 'checkout_validation';
        }
        if ($status === 'qris_not_ready' || str_contains($kind, 'qris')) {
            return 'qris_unavailable';
        }
        if ($status === 'provider_failure' || str_contains($kind, 'provider') || str_contains($kind, 'connection')) {
            return 'provider_unavailable';
        }

        return 'other';
    }

    private function supportCategory(string $category): string
    {
        return array_key_exists($category, self::SUPPORT_CATEGORY_LABELS) ? $category : 'other';
    }

    private function qrisOutcome(string $state): string
    {
        return match (strtolower($state)) {
            'created', 'provider_success' => 'created',
            'creating' => 'creating',

            'outcome_unknown', 'recovery_unconfirmed' => 'recovery_pending',
            'transient_qris_image', 'provider_total_pending' => 'provider_total_pending',
            'provider_expired' => 'expired',
            'create_failed', 'provider_qris_unusable', 'provider_total_invalid', 'financial_integrity_failed' => 'failed',
            default => 'other',
        };
    }

    private function providerLabel(string $provider): string
    {
        return $provider === 'other' ? 'Lainnya' : PaymentProvider::label($provider);
    }

    private function sourceKey(array $event): string
    {
        return implode('|', [
            (string) ($event['source'] ?? 'direct'),
            (string) ($event['utm_source'] ?? ''),
            (string) ($event['utm_medium'] ?? ''),
            (string) ($event['utm_campaign'] ?? ''),
        ]);
    }

    /** @return array<string, mixed> */
    private function newProductBucket(int $productId, array $context): array
    {
        $product = $context['products'][$productId] ?? null;
        $categoryId = $product?->category_id === null ? null : (int) $product->category_id;
        $category = $categoryId === null ? null : ($context['categories'][$categoryId] ?? null);

        return $this->newBucket() + [
            'product_id' => $productId,
            'product_name' => $product?->name ?: ($context['product_names'][$productId] ?? 'Produk #'.$productId),
            'category_id' => $categoryId,
            'category_name' => $category?->name ?: 'Tanpa kategori',
            'is_active' => $product?->is_active,
            'is_deleted' => $product?->trashed() ?? false,
        ];
    }

    /** @return array<string, mixed> */
    private function newFamilyBucket(int $familyId, ?string $familyName): array
    {
        return $this->newBucket() + [
            'family_id' => $familyId,
            'family_name' => $familyName ?: 'Product Family #'.$familyId,
        ];
    }

    /** @return array<string, mixed> */
    private function newRailBucket(string $provider, string $rail): array
    {
        return $this->newBucket() + [
            'provider' => $provider,
            'provider_label' => $this->providerLabel($provider),
            'rail' => $rail,
            'rail_label' => self::RAIL_LABELS[$rail] ?? self::RAIL_LABELS['other'],
        ];
    }

    /** @return array<string, mixed> */
    private function newSourceBucket(array $event): array
    {
        return $this->newBucket() + [
            'source' => (string) ($event['source'] ?? 'direct'),
            'utm_source' => $event['utm_source'],
            'utm_medium' => $event['utm_medium'],
            'utm_campaign' => $event['utm_campaign'],
        ];
    }

    /** @return array<string, mixed> */
    private function bucketMetrics(array $bucket): array
    {
        $paidOrders = count($bucket['_paid_order_ids']);
        $accepted = (int) $bucket['accepted_checkouts'];
        $rate = $accepted === 0 ? 0.0 : round(($paidOrders / $accepted) * 100, 2);
        $amounts = array_values($bucket['_amounts']);
        $average = $amounts === [] ? null : (int) round(array_sum($amounts) / count($amounts));
        $customerCounts = $bucket['_customer_counts'];
        $paidCustomers = count($customerCounts);
        $repeatCustomers = count(array_filter($customerCounts, static fn (int $count): bool => $count > 1));
        $repeatRate = $paidCustomers === 0 ? 0.0 : round(($repeatCustomers / $paidCustomers) * 100, 2);

        return [
            'views' => (int) $bucket['views'],
            'product_views' => (int) $bucket['views'],
            'family_views' => (int) $bucket['family_views'],
            'unique_sessions' => count($bucket['_session_hashes']),
            'checkout_opens' => (int) $bucket['checkout_opens'],
            'checkout_opened' => (int) $bucket['checkout_opens'],
            'checkout_submits' => (int) $bucket['checkout_submits'],
            'checkout_submitted' => (int) $bucket['checkout_submits'],
            'accepted_checkouts' => $accepted,
            'checkout_accepted' => $accepted,
            'rejected_checkouts' => (int) $bucket['rejected_checkouts'],
            'checkout_rejected' => (int) $bucket['rejected_checkouts'],
            'other_checkout_outcomes' => (int) $bucket['other_checkout_outcomes'],
            'qris_created' => (int) $bucket['qris_created'],
            'provider_creation_attempts' => (int) $bucket['provider_creation_attempts'],
            'paid_orders' => $paidOrders,
            'paid_order_count' => $paidOrders,
            'payment_conversion_rate' => $rate,
            'payment_conversion_rate_label' => $this->percentageLabel($rate),
            'average_order_value' => $average,
            'average_order_value_label' => $average === null ? '-' : Money::idr($average),
            'support_selections' => (int) $bucket['support_selections'],
            'paid_customers' => $paidCustomers,
            'repeat_customers' => $repeatCustomers,
            'repeat_order_rate' => $repeatRate,
            'repeat_order_rate_label' => $this->percentageLabel($repeatRate),
            'repeat_order_signal' => [
                'paid_customers' => $paidCustomers,
                'repeat_customers' => $repeatCustomers,
                'rate' => $repeatRate,
                'rate_label' => $this->percentageLabel($repeatRate),
            ],
            'failure_categories' => $this->finalizeCountBuckets($bucket['_failure_categories'], self::FAILURE_LABELS),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function finalizeProductBuckets(array $buckets): array
    {
        $rows = [];
        foreach ($buckets as $bucket) {
            $identity = [
                'product_id' => $bucket['product_id'],
                'product_name' => $bucket['product_name'],
                'category_id' => $bucket['category_id'],
                'category_name' => $bucket['category_name'],
                'is_active' => $bucket['is_active'],
                'is_deleted' => $bucket['is_deleted'],
            ];
            $rows[] = $identity + $this->bucketMetrics($bucket);
        }

        usort($rows, fn (array $left, array $right): int => $this->compareRows($left, $right, 'product_name'));

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function finalizeFamilyBuckets(array $buckets): array
    {
        $rows = [];
        foreach ($buckets as $bucket) {
            $metrics = $this->bucketMetrics($bucket);
            $metrics['views'] = (int) $metrics['views'] + (int) $metrics['family_views'];
            $rows[] = [
                'family_id' => $bucket['family_id'],
                'family_name' => $bucket['family_name'],
            ] + $metrics;
        }

        usort($rows, fn (array $left, array $right): int => $this->compareRows($left, $right, 'family_name'));

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function finalizeRailBuckets(array $buckets): array
    {
        $rows = [];
        foreach ($buckets as $bucket) {
            $rows[] = [
                'provider' => $bucket['provider'],
                'provider_label' => $bucket['provider_label'],
                'rail' => $bucket['rail'],
                'rail_label' => $bucket['rail_label'],
            ] + $this->bucketMetrics($bucket);
        }

        usort($rows, fn (array $left, array $right): int => $this->compareRows($left, $right, 'rail_label'));

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function finalizeSourceBuckets(array $buckets): array
    {
        $rows = [];
        foreach ($buckets as $bucket) {
            $rows[] = [
                'source' => $bucket['source'],
                'utm_source' => $bucket['utm_source'],
                'utm_medium' => $bucket['utm_medium'],
                'utm_campaign' => $bucket['utm_campaign'],
            ] + $this->bucketMetrics($bucket);
        }

        usort($rows, fn (array $left, array $right): int => $this->compareRows($left, $right, 'source'));

        return $rows;
    }

    /** @param array<string, int> $counts */
    /** @param array<string, string> $labels */
    /** @return list<array{key:string,label:string,count:int}> */
    private function finalizeCountBuckets(array $counts, array $labels): array
    {
        $rows = [];
        foreach ($counts as $key => $count) {
            $rows[] = ['key' => $key, 'label' => $labels[$key] ?? $labels['other'] ?? 'Lainnya', 'count' => (int) $count];
        }
        usort($rows, static fn (array $left, array $right): int => $right['count'] <=> $left['count'] ?: strcmp($left['label'], $right['label']));

        return $rows;
    }

    /** @param array<string, array{count:int, channels:array<string, int>}> $counts */
    /** @return list<array<string, mixed>> */
    private function finalizeSupportBuckets(array $counts): array
    {
        $rows = [];
        foreach ($counts as $key => $value) {
            arsort($value['channels']);
            $rows[] = [
                'key' => $key,
                'label' => self::SUPPORT_CATEGORY_LABELS[$key] ?? self::SUPPORT_CATEGORY_LABELS['other'],
                'count' => $value['count'],
                'channels' => array_map(
                    fn (int $count, string $channel): array => ['channel' => $channel, 'count' => $count],
                    $value['channels'],
                    array_keys($value['channels']),
                ),
            ];
        }
        usort($rows, static fn (array $left, array $right): int => $right['count'] <=> $left['count'] ?: strcmp($left['label'], $right['label']));

        return $rows;
    }

    /** @param array<string, array<string, mixed>> $outcomes */
    /** @return list<array<string, mixed>> */
    private function finalizeQrisOutcomes(array $outcomes): array
    {
        $rows = array_values($outcomes);
        usort($rows, static fn (array $left, array $right): int => $right['count'] <=> $left['count'] ?: strcmp($left['outcome_label'], $right['outcome_label']));

        return $rows;
    }

    private function compareRows(array $left, array $right, string $labelKey): int
    {
        return $right['paid_orders'] <=> $left['paid_orders']
            ?: $right['views'] <=> $left['views']
            ?: strcmp((string) $left[$labelKey], (string) $right[$labelKey]);
    }

    private function percentageLabel(float $percentage): string
    {
        return rtrim(rtrim(number_format($percentage, 2, '.', ''), '0'), '.').'%';
    }

    /** @param array<string, mixed> $context */
    /** @param array<string, mixed> $filters */
    /** @return array<string, mixed> */
    private function filterOptions(array $context, array $filters): array
    {
        $products = $context['catalog_products']->map(function (Product $product): array {
            return [
                'id' => (int) $product->getKey(),
                'name' => $product->name ?: 'Produk #'.$product->getKey(),
                'status_label' => $product->trashed() ? 'Deleted' : ($product->is_active ? 'Aktif' : 'Tidak aktif'),
            ];
        })->values()->all();
        $categories = $context['catalog_categories']->map(fn (Category $category): array => [
            'id' => (int) $category->getKey(),
            'name' => $category->name ?: 'Kategori #'.$category->getKey(),
            'status_label' => $category->trashed() ? 'Deleted' : ($category->is_active ? 'Aktif' : 'Tidak aktif'),
        ])->values()->all();
        $familiesById = [];
        foreach ($context['catalog_families'] as $family) {
            $familiesById[(int) $family->getKey()] = [
                'id' => (int) $family->getKey(),
                'name' => $family->name ?: 'Product Family #'.$family->getKey(),
                'status_label' => $family->trashed() ? 'Deleted' : 'Aktif',
            ];
        }
        foreach ($context['events'] as $event) {
            $familyId = $this->positiveId($event['family_id'] ?? null);
            if ($familyId === null || isset($familiesById[$familyId])) {
                continue;
            }
            $familiesById[$familyId] = [
                'id' => $familyId,
                'name' => $event['family_name'] ?: 'Product Family #'.$familyId,
                'status_label' => 'Snapshot',
            ];
        }
        foreach ($context['orders'] as $order) {
            foreach ($order->orderItems as $item) {
                $familyId = $this->positiveId($item->family_id ?? null);
                if ($familyId === null || isset($familiesById[$familyId])) {
                    continue;
                }
                $familiesById[$familyId] = [
                    'id' => $familyId,
                    'name' => $this->safeEventText($item->family_name ?? null, 160) ?: 'Product Family #'.$familyId,
                    'status_label' => 'Snapshot',
                ];
            }
        }
        $families = array_values($familiesById);
        usort($families, static fn (array $left, array $right): int => strcmp($left['name'], $right['name']) ?: ($left['id'] <=> $right['id']));

        $values = fn (string $field): array => collect($context['events'])
            ->map(fn (array $event): ?string => $event[$field] === null ? null : (string) $event[$field])
            ->filter()
            ->unique()
            ->sort()
            ->take(100)
            ->values()
            ->all();

        return [
            'products' => $products,
            'families' => $families,
            'categories' => $categories,
            'providers' => $this->providerOptions(),
            'rails' => $this->railOptions(),
            'sources' => $this->withSelected($values('source'), $filters['source'] ?? null),
            'utm_sources' => $this->withSelected($values('utm_source'), $filters['utm_source'] ?? null),
            'utm_mediums' => $this->withSelected($values('utm_medium'), $filters['utm_medium'] ?? null),
            'utm_campaigns' => $this->withSelected($values('utm_campaign'), $filters['utm_campaign'] ?? null),
        ];
    }

    /** @return list<array{value:string,label:string}> */
    private function providerOptions(): array
    {
        return array_map(fn (string $provider): array => ['value' => $provider, 'label' => PaymentProvider::label($provider)], PaymentProvider::known());
    }

    /** @return list<array{value:string,label:string}> */
    private function railOptions(): array
    {
        return array_map(fn (string $rail): array => ['value' => $rail, 'label' => self::RAIL_LABELS[$rail]], self::RAILS);
    }

    /** @return list<string> */
    private function withSelected(array $values, ?string $selected): array
    {
        if ($selected !== null && ! in_array($selected, $values, true)) {
            $values[] = $selected;
            sort($values);
        }

        return $values;
    }

    private function positiveId(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^\d+$/D', $value) === 1) {
            $parsed = filter_var($value, FILTER_VALIDATE_INT);
            if ($parsed !== false && $parsed > 0) {
                return (int) $parsed;
            }
        }

        return null;
    }

    private function safeEventText(mixed $value, int $maxLength): ?string
    {
        if ($value === null || ! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);
        if ($value === '' || mb_strlen($value) > $maxLength || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            return null;
        }

        return $value;
    }

    private function safeAttributionText(mixed $value): ?string
    {
        $value = $this->safeEventText($value, 80);

        return $value !== null
            && preg_match('/^[A-Za-z0-9._~%+\/:\-]{1,80}$/D', $value) === 1
            && preg_match('/^\+?\d[\d-]{6,}$/D', $value) !== 1
            ? $value
            : null;
    }

    private function safeSessionHash(mixed $value): ?string
    {
        $value = $this->safeEventText($value, 64);

        return $value !== null && preg_match('/^[a-f0-9]{64}$/iD', $value) === 1 ? strtolower($value) : null;
    }

    private function isSubjectType(string $subjectType, string $class): bool
    {
        return $subjectType === $class
            || $subjectType === class_basename($class)
            || str_ends_with($subjectType, '\\'.class_basename($class));
    }

    private function hasAttributionFilters(array $filters): bool
    {
        return ($filters['source'] ?? null) !== null
            || ($filters['utm_source'] ?? null) !== null
            || ($filters['utm_medium'] ?? null) !== null
            || ($filters['utm_campaign'] ?? null) !== null;
    }
}
