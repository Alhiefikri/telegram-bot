<?php

namespace App\Services\Telegram;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\TelegramNavigationScreen;
use App\Jobs\VerifyProviderPaymentStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\Credentials\CredentialDeliveryPresentation;
use App\Services\KlikQris\KlikQrisNativeQrisService;
use App\Services\KlikQris\KlikQrisProviderReadiness;
use App\Services\Pakasir\PakasirProviderReadiness;
use App\Services\PaymentReceiptReport;
use App\Services\ProviderVerification\ProviderVerificationProfile;
use App\Services\ProviderVerification\ProviderVerificationProfiles;
use App\Services\Sakurupiah\SakurupiahCallbackProcessor;
use App\Services\Sakurupiah\SakurupiahInvoiceLifecycleService;
use App\Services\Sakurupiah\SakurupiahProviderReadiness;
use App\Services\Sakurupiah\SakurupiahReconciliationOutcome;
use App\Services\UsdtBalance\UsdtMicroAmount;
use App\Services\WijayaPay\WijayaPayCallbackProcessor;
use App\Services\WijayaPay\WijayaPayInvoiceLifecycleService;
use App\Services\WijayaPay\WijayaPayProviderReadiness;
use App\Services\WijayaPay\WijayaPayReconciliationOutcome;
use App\Storefront\StorefrontInvoiceUrl;
use App\Support\Money;
use App\Support\PaymentProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

class TelegramOrderIntents
{
    private const ORDER_HISTORY_PAGE_SIZE = 10;

    private const ORDER_HISTORY_CURSOR_PATTERN = '/\Aorders:(older|newer):([0-9]{14}):([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})\z/D';

    public function __construct(
        private TelegramBotClient $bot,
        private TelegramOutboundEffectService $outboundEffects,
        private TelegramOutboundEffectDispatcher $outboundEffectDispatcher,
        private PakasirProviderReadiness $pakasirReadiness,
        private KlikQrisNativeQrisService $klikQrisNativeQris,
        private KlikQrisProviderReadiness $klikQrisReadiness,
        private SakurupiahInvoiceLifecycleService $sakurupiahLifecycle,
        private SakurupiahCallbackProcessor $sakurupiahProcessor,
        private SakurupiahProviderReadiness $sakurupiahReadiness,
        private WijayaPayInvoiceLifecycleService $wijayapayLifecycle,
        private WijayaPayCallbackProcessor $wijayapayProcessor,
        private WijayaPayProviderReadiness $wijayapayReadiness,
        private TelegramLocale $locale,
        private TelegramMenuIntents $menu,
        private CredentialDeliveryPresentation $credentialPresentation,
        private TelegramCredentialDeliveryRenderer $credentialRenderer,
        private TelegramMessageChunker $messageChunker,
        private TelegramProductNameRenderer $productNames,
        private StorefrontInvoiceUrl $invoiceUrls,
        private TelegramStarsCustomerInvoice $starsInvoice,
    ) {}

    public function sendLatestOrderStatus(Customer $customer, string $chatId): void
    {
        $order = $customer->orders()
            ->with(['orderItems' => fn ($query) => $query->orderBy('id'), 'payments' => fn ($query) => $query->latest()->orderByDesc('id')])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
        $month = now()->startOfMonth();
        $previousMonth = $month->copy()->subMonth();
        $lines = [$this->locale->translate($customer, 'order.status.title'), ''];
        $keyboard = [];
        if ($order?->payments->first()?->isTelegramStars() && $chatId !== (string) $customer->telegram_id) {
            return;
        }
        if ($order) {
            $payment = $order->payments->first();
            $paymentStatus = $payment?->status ?? $order->payment_status;
            $lines[] = $this->locale->translate($customer, 'order.status.latest');
            $lines[] = $order->invoice_number.' · '.$this->locale->formatShortDate($customer, $order->created_at);
            foreach ($order->orderItems->take(3) as $item) {
                $lines[] = mb_strimwidth(preg_replace('/\s+/u', ' ', (string) $item->product_name), 0, 100, '…').' × '.$item->quantity;
            }
            if ($order->orderItems->count() > 3) {
                $lines[] = $this->locale->translate($customer, 'order.status.more_items', ['count' => $order->orderItems->count() - 3]);
            }
            $lines[] = $this->locale->translate($customer, 'order.detail.total', ['amount' => $this->orderAmount($customer, $order, $payment)]);
            $lines[] = '';
            $lines[] = $this->locale->translate($customer, 'order.status.states', [
                'payment_status' => $this->locale->paymentStatus($customer, $paymentStatus),
                'order_status' => $this->locale->orderStatus($customer, $this->visibleOrderStatus($order)),
            ]);
            $lines[] = $this->orderInstruction($customer, $order, $payment);
            $deadline = $payment?->expired_at ?? $order->expires_at;
            if ($order->order_status === OrderStatus::PendingPayment && $paymentStatus === PaymentStatus::Pending && $deadline) {
                $lines[] = $this->locale->translate($customer, 'order.detail.deadline', ['date' => $this->locale->formatDate($customer, $deadline)]);
                if (! $payment?->isTelegramStars() && $deadline->isFuture()
                    && ! CheckoutPaymentIntents::isBinancePaymentMethod($payment?->method)
                    && $order->created_at->copy()->addDays(max(1, (int) config('services.storefront.invoice_url_ttl_days', 7)))->isFuture()) {
                    $keyboard[] = [[
                        'text' => $this->locale->translate($customer, 'order.status.pay_button'),
                        'url' => url($this->invoiceUrls->show($order)),
                    ]];
                }
            }
            $keyboard[] = [[
                'text' => $this->locale->translate($customer, 'order.status.detail_button'),
                'callback_data' => 'order:'.$order->invoice_number,
            ]];
        } else {
            $lines[] = $this->locale->translate($customer, 'order.status.empty');
        }
        if ($chatId === (string) $customer->telegram_id) {
            $pending = $customer->orders()->where('order_status', OrderStatus::PendingPayment)
                ->where('payment_status', PaymentStatus::Pending)
                ->whereHas('payments', fn ($query) => $query->where('provider', PaymentProvider::TELEGRAM_STARS))
                ->when($order !== null, fn ($query) => $query->whereKeyNot($order->id))
                ->with(['payments' => fn ($query) => $query->where('provider', PaymentProvider::TELEGRAM_STARS)])
                ->latest()->limit(5)->get();
            foreach ($pending as $pendingOrder) {
                $keyboard[] = [[
                    'text' => $pendingOrder->invoice_number.' · '.TelegramStarsCustomerInvoice::tender($pendingOrder->payments->first()),
                    'callback_data' => 'order:'.$pendingOrder->invoice_number,
                ]];
            }
        }
        $lines[] = '';
        $lines[] = $this->locale->translate($customer, 'order.status.summary');
        foreach ([$month, $previousMonth] as $index => $period) {
            $lines[] = $this->monthLabel($customer, $period).' · '.$this->locale->translate($customer, $index === 0 ? 'order.status.this_month' : 'order.status.last_month');
            $lines[] = $this->monthlySpending($customer, $period);
            if ($index === 0) {
                $lines[] = '';
            }
        }
        $lines[] = $this->locale->translate($customer, 'order.status.summary_basis');
        $keyboard[] = array_map(fn ($period, $key) => [
            'text' => $this->locale->translate($customer, $key),
            'callback_data' => 'om:'.$period->format('Ym'),
        ], [$month, $previousMonth], ['order.status.this_month_button', 'order.status.last_month_button']);
        $keyboard[] = [
            ['text' => $this->locale->translate($customer, 'order.status.history_button'), 'callback_data' => 'orders:open'],
            ['text' => $this->locale->translate($customer, 'order.status.home_button'), 'callback_data' => 'status:home'],
        ];
        $this->bot->sendMessage($chatId, implode("\n", $lines), ['inline_keyboard' => $keyboard]);
    }

    private function monthLabel(Customer $customer, Carbon $month): string
    {
        return $this->locale->translate($customer, 'date.month.'.(int) $month->format('n')).' '.$month->format('Y');
    }

    private function monthlySpending(Customer $customer, Carbon $month): string
    {
        $totals = $customer->orders()
            ->where('created_at', '>=', $month)
            ->where('created_at', '<', $month->copy()->addMonth())
            ->where('payment_status', PaymentStatus::Paid)
            ->selectRaw("COUNT(*) as paid_count, COALESCE(SUM(CASE WHEN currency = 'IDR' AND NOT EXISTS (
                SELECT 1 FROM payments WHERE payments.order_id = orders.id AND payments.provider = ?
            ) THEN total_amount ELSE 0 END), 0) as paid_total", [PaymentProvider::TELEGRAM_STARS])
            ->first();

        $currencyTotals = app(PaymentReceiptReport::class)->customerTransactionTotals((int) $customer->id, $month, $month->copy()->addMonth());
        $amounts = [];
        if ((int) $totals->paid_total > 0 || ! isset($currencyTotals['XTR'])) {
            $amounts[] = $this->locale->idr((int) $totals->paid_total);
        }
        if ($currencyTotals['USDT'] > 0) {
            $amounts[] = Money::usdt($currencyTotals['USDT']);
        }
        if (isset($currencyTotals['XTR'])) {
            $amounts[] = Money::xtr($currencyTotals['XTR']);
        }

        return $this->locale->translate($customer, 'order.status.month_totals', [
            'count' => (int) $totals->paid_count,
            'amount' => implode(' / ', $amounts),
        ]);
    }

    private function orderAmount(Customer $customer, Order $order, ?Payment $payment): string
    {
        return match (strtoupper((string) $payment?->currency)) {
            'USDT' => Money::usdt((int) $payment->amount),
            'XTR' => TelegramStarsCustomerInvoice::tender($payment),
            default => $this->locale->idr((int) $order->total_amount),
        };
    }

    public function monthFromCallback(string $data): ?Carbon
    {
        if (preg_match('/\Aom:([0-9]{4})(0[1-9]|1[0-2])(?::[on]:[0-9]{14}:[0-9a-f-]{36})?\z/D', $data, $matches) !== 1 || (int) $matches[1] < 1) {
            return null;
        }

        return Carbon::create((int) $matches[1], (int) $matches[2], 1, 0, 0, 0, config('app.timezone'));
    }

    /**
     * Canonical Orders screen. The presentation seam renders it on the
     * customer-owned message; the callback and menu adapters never re-run the
     * order query themselves.
     */
    public function orderHistorySurface(Customer $customer, ?string $cursor = null, ?Carbon $month = null): TelegramNavigationSurface
    {
        if ($month !== null && $cursor !== null && preg_match('/\Aom:[0-9]{6}:([on]):([0-9]{14}):([0-9a-f-]{36})\z/D', $cursor, $matches) === 1) {
            $cursor = 'orders:'.($matches[1] === 'o' ? 'older' : 'newer').':'.$matches[2].':'.$matches[3];
        }
        $historyCursor = $this->decodeHistoryCursor($cursor);
        $ordersQuery = $customer->orders()
            ->select([
                'id',
                'invoice_number',
                'order_status',
                'payment_status',
                'created_at',
                'telegram_receipt_sent_at',
            ])
            ->withExists(['orderItems as has_manual_unlimited' => fn ($query) => $query->where('manual_unlimited', true)])
            ->with(['payments' => fn ($query) => $query->select('id', 'order_id', 'amount')
                ->where('provider', PaymentProvider::TELEGRAM_STARS)])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($month !== null) {
            $ordersQuery->where('created_at', '>=', $month)->where('created_at', '<', $month->copy()->addMonth());
        } else {
            $ordersQuery->where(fn ($query) => $query
                ->where(fn ($paid) => $paid->where('payment_status', PaymentStatus::Paid)
                    ->whereIn('order_status', [OrderStatus::Processing, OrderStatus::Completed]))
                ->orWhere(fn ($refunded) => $refunded->where('payment_status', PaymentStatus::Refunded)
                    ->whereHas('payments', fn ($payment) => $payment->where('provider', PaymentProvider::TELEGRAM_STARS))));
        }
        $title = $month === null ? $this->locale->translate($customer, 'order.history.title')
            : $this->locale->translate($customer, 'order.status.month_title', ['month' => $this->monthLabel($customer, $month)])."\n".$this->monthlySpending($customer, $month);
        if ($historyCursor !== null) {
            $operator = $historyCursor['direction'] === 'older' ? '<' : '>';
            $ordersQuery->where(function ($query) use ($historyCursor, $operator): void {
                $query->where('created_at', $operator, $historyCursor['created_at'])
                    ->orWhere(function ($query) use ($historyCursor, $operator): void {
                        $query->where('created_at', $historyCursor['created_at'])
                            ->where('id', $operator, $historyCursor['id']);
                    });
            });
        }

        if ($historyCursor !== null && $historyCursor['direction'] === 'newer') {
            $ordersQuery->reorder('created_at')->orderBy('id');
        }
        $orders = $ordersQuery
            ->take(self::ORDER_HISTORY_PAGE_SIZE + 1)
            ->get();
        $hasMore = $orders->count() > self::ORDER_HISTORY_PAGE_SIZE;
        if ($hasMore) {
            $orders = $orders->take(self::ORDER_HISTORY_PAGE_SIZE);
        }

        if ($historyCursor !== null && $historyCursor['direction'] === 'newer') {
            $orders = $orders->reverse()->values();
        }
        $hasNewer = $historyCursor !== null
            && ($historyCursor['direction'] === 'older' || $hasMore);
        $hasOlder = ($hasMore && ($historyCursor === null || $historyCursor['direction'] === 'older'))
            || ($historyCursor !== null && $historyCursor['direction'] === 'newer');

        if ($orders->isEmpty()) {
            $keyboard = [[
                [
                    'text' => $this->locale->translate($customer, 'order.history.browse_button'),
                    'callback_data' => 'katalog',
                ],
            ]];
            if ($historyCursor !== null) {
                $keyboard[] = [[
                    'text' => $this->locale->translate($customer, 'order.history.previous'),
                    'callback_data' => $month === null ? 'orders:open' : 'om:'.$month->format('Ym'),
                ]];
            }
            $keyboard[] = $this->menu->navigationRow($customer);

            return new TelegramNavigationSurface(
                TelegramNavigationScreen::Orders,
                $this->locale->code($customer),
                $month === null ? $this->locale->translate($customer, 'order.history.empty') : $title."\n\n".$this->locale->translate($customer, 'order.status.month_empty'),
                ['inline_keyboard' => $keyboard],
            );
        }

        $keyboard = [];
        foreach ($orders as $order) {
            $starsPayment = $order->payments->first();
            $keyboard[] = [[
                'text' => ($month === null ? '' : $order->created_at->format('d/m').' · ').$this->locale->translate($customer, 'order.history.item_button', [
                    'invoice' => $order->invoice_number,
                    'status' => $this->historyButtonStatus($customer, $order).($month === null || ($starsPayment !== null && $order->payment_status === PaymentStatus::Refunded) ? '' : ' / '.$this->locale->paymentStatus($customer, $order->payment_status)),
                ]).($starsPayment === null ? '' : ' · '.TelegramStarsCustomerInvoice::tender($starsPayment)),
                'callback_data' => 'order:'.$order->invoice_number,
            ]];
        }

        $pagination = [];
        if ($hasNewer) {
            $pagination[] = [
                'text' => $this->locale->translate($customer, 'order.history.previous'),
                'callback_data' => $this->historyCursor('newer', $orders->first(), $month),
            ];
        }
        if ($hasOlder) {
            $pagination[] = [
                'text' => $this->locale->translate($customer, 'order.history.next'),
                'callback_data' => $this->historyCursor('older', $orders->last(), $month),
            ];
        }
        if ($pagination !== []) {
            $keyboard[] = $pagination;
        }
        $keyboard[] = $this->menu->navigationRow($customer);

        return new TelegramNavigationSurface(
            TelegramNavigationScreen::Orders,
            $this->locale->code($customer),
            $title,
            ['inline_keyboard' => $keyboard],
        );
    }

    private function historyCursor(string $direction, Order $order, ?Carbon $month = null): string
    {
        $cursor = 'orders:'.$direction.':'.$order->created_at->format('YmdHis').':'.$order->getKey();
        if ($month !== null) {
            $cursor = 'om:'.$month->format('Ym').':'.($direction === 'older' ? 'o' : 'n').':'.$order->created_at->format('YmdHis').':'.$order->getKey();
        }
        if (strlen($cursor) > 64) {
            throw new \LogicException('Order history callback data exceeded Telegram limit.');
        }

        return $cursor;
    }

    /** @return array{direction: string, created_at: Carbon, id: string}|null */
    private function decodeHistoryCursor(?string $cursor): ?array
    {
        if ($cursor === null
            || preg_match(self::ORDER_HISTORY_CURSOR_PATTERN, $cursor, $matches) !== 1) {
            return null;
        }

        try {
            $createdAt = Carbon::createFromFormat('YmdHis', $matches[2], config('app.timezone'));
        } catch (Throwable) {
            return null;
        }
        if (! $createdAt instanceof Carbon) {
            return null;
        }

        return [
            'direction' => $matches[1],
            'created_at' => $createdAt,
            'id' => $matches[3],
        ];
    }

    private function historyButtonStatus(Customer $customer, Order $order): string
    {
        if ($order->payment_status === PaymentStatus::Refunded && $order->payments->isNotEmpty()) {
            return $this->locale->paymentStatus($customer, PaymentStatus::Refunded);
        }
        if ($this->manualDeliveryPending($order)) {
            return $this->locale->orderStatus($customer, OrderStatus::Processing);
        }

        if ($order->payment_status === PaymentStatus::Paid
            && ($order->order_status === OrderStatus::Completed || $order->telegram_receipt_sent_at !== null)) {
            return $this->locale->translate($customer, 'order.history.delivered');
        }

        return $this->locale->orderStatus($customer, $order->order_status);
    }

    private function manualDeliveryPending(Order $order): bool
    {
        if ($order->order_status !== OrderStatus::Completed
            || $order->payment_status !== PaymentStatus::Paid
            || $order->telegram_receipt_sent_at !== null) {
            return false;
        }

        return $order->relationLoaded('orderItems')
            ? $order->orderItems->contains(fn ($item) => (bool) $item->manual_unlimited)
            : (bool) $order->getAttribute('has_manual_unlimited');
    }

    private function visibleOrderStatus(Order $order): OrderStatus
    {
        return $this->manualDeliveryPending($order) ? OrderStatus::Processing : $order->order_status;
    }

    public function sendOrderDetail(Customer $customer, string $chatId, string $invoice): void
    {
        $order = $customer->orders()
            ->where('invoice_number', $invoice)
            ->with([
                'orderItems.stockItems',
                'orderItems.product',
                'payments' => fn ($query) => $query->latest(),
            ])
            ->first();
        if (! $order) {
            $this->bot->sendMessage($chatId, $this->locale->translate($customer, 'order.detail.not_found'));

            return;
        }

        $payment = $order->payments->first();
        if ($payment?->isTelegramStars() && $chatId !== (string) $customer->telegram_id) {
            return;
        }
        $sakurupiahWasPayable = $this->isSakurupiahPayable($payment);
        $wijayapayWasPayable = $this->isWijayaPayPayable($payment);
        $refreshNotice = $this->refreshProviderPaymentStatus($customer, $order, $payment);
        $sakurupiahPresentationRecovered = false;
        $wijayapayPresentationRecovered = false;
        if ($refreshNotice !== null) {
            $order = $order->fresh([
                'orderItems.stockItems',
                'orderItems.product',
                'payments' => fn ($query) => $query->latest(),
            ]);
            $payment = $order->payments->first();
            $sakurupiahPresentationRecovered = ! $sakurupiahWasPayable
                && $this->isSakurupiahPayable($payment);
            $wijayapayPresentationRecovered = ! $wijayapayWasPayable
                && $this->isWijayaPayPayable($payment);
        }
        $paymentStatus = $payment?->status ?? $order->payment_status;
        $orderStatusLabel = $this->locale->orderStatus($customer, $this->visibleOrderStatus($order));
        $paymentStatusLabel = $this->locale->paymentStatus($customer, $paymentStatus);
        $lines = [
            $this->locale->translate($customer, 'order.detail.title'),
            '━━━━━━━━━━━━━━',
            $this->locale->translate($customer, 'order.detail.invoice', ['invoice' => $order->invoice_number]),
            $this->locale->translate($customer, 'order.detail.status', [
                'order_status' => $orderStatusLabel,
                'payment_status' => $paymentStatusLabel,
            ]),
            $this->locale->translate($customer, 'order.detail.created', [
                'date' => $this->locale->formatDate($customer, $order->created_at),
            ]),
            '',
            $this->locale->translate($customer, 'order.detail.items'),
        ];
        foreach ($order->orderItems as $item) {
            $lines[] = '- '.$this->productNames->render(
                (string) $item->product_name,
                $item->product_icon,
                $item->product_custom_emoji_id,
            ).' x '.$item->quantity;
        }
        $lines[] = $this->locale->translate($customer, 'order.detail.total', [
            'amount' => $this->orderAmount($customer, $order, $payment),
        ]);
        if ($payment) {
            $lines[] = $this->locale->translate($customer, 'order.detail.payment', [
                'method' => $this->locale->paymentMethod($customer, $payment),
                'status' => $this->locale->paymentStatus($customer, $payment->status),
            ]);
            if ($payment->paid_at) {
                $lines[] = $this->locale->translate($customer, 'order.detail.paid', [
                    'date' => $this->locale->formatDate($customer, $payment->paid_at),
                ]);
            }
        }
        if (($order->order_status === OrderStatus::PendingPayment || $paymentStatus === PaymentStatus::Pending)
            && ($payment?->expired_at || $order->expires_at)) {
            $lines[] = $this->locale->translate($customer, 'order.detail.deadline', [
                'date' => $this->locale->formatDate($customer, $payment?->expired_at ?? $order->expires_at),
            ]);
        }
        if (in_array($paymentStatus, [PaymentStatus::Expired, PaymentStatus::Failed], true)
            && ($payment?->expired_at || $order->expires_at)) {
            $lines[] = $this->locale->translate($customer, 'order.detail.expired', [
                'date' => $this->locale->formatDate($customer, $payment?->expired_at ?? $order->expires_at),
            ]);
        }
        if ($refreshNotice !== null) {
            $lines[] = '';
            $lines[] = $refreshNotice;
        }
        $starsPending = $payment?->isTelegramStars() && $order->order_status === OrderStatus::PendingPayment
            && $paymentStatus === PaymentStatus::Pending ? $this->starsInvoice->pending($customer, $order, $payment) : null;
        $instruction = $starsPending === null ? $this->orderInstruction($customer, $order, $payment)
            : $this->locale->translate($customer, $starsPending['key']);
        $delivery = $this->credentialPresentation->forCustomer($customer, $order, $payment);
        if (! $delivery->isEmpty()) {
            $lines[] = '';
            $lines[] = $this->locale->translate($customer, 'order.detail.account_details');
            $units = [
                ...$this->unitsFromLines($lines),
                ...$this->credentialRenderer->accountUnits(
                    $customer,
                    $delivery,
                    'order.detail.otp_button',
                    'order.detail.delivery_note',
                ),
                new TelegramMessageUnit($instruction),
            ];
        } else {
            $lines[] = '';
            $lines[] = $instruction;
            $units = $this->unitsFromLines($lines);
        }

        $lastResponse = null;
        foreach ($units as $index => $unit) {
            $isLast = $index === array_key_last($units);
            $markup = $unit->replyMarkup();
            if ($isLast && $starsPending !== null) {
                $markup = ['inline_keyboard' => [[[
                    'text' => $this->locale->translate($customer, 'stars.refresh'),
                    'callback_data' => 'order:'.$order->invoice_number,
                    'style' => 'primary',
                ]]]];
                if (in_array($starsPending['key'], ['stars.invoice_pending', 'stars.invoice_ready', 'stars.invoice_paused'], true)) {
                    $markup['inline_keyboard'][] = [[
                        'text' => $this->locale->translate($customer, 'payment.action.cancel'),
                        'callback_data' => 'buyer:cancel-pending:'.$order->id,
                        'style' => 'primary',
                    ]];
                }
            }
            if ($isLast && $payment?->provider === PaymentProvider::MANUAL && $payment->method === 'USDT_BEP20'
                && $order->order_status === OrderStatus::PendingPayment && $paymentStatus === PaymentStatus::Pending) {
                $markup = ['inline_keyboard' => [
                    [[
                        'text' => $this->locale->translate($customer, 'payment.bsc.check_status'),
                        'callback_data' => 'bsc:check:'.$order->id,
                        'style' => 'primary',
                    ]],
                    [[
                        'text' => $this->locale->translate($customer, 'payment.bsc.submit_hash'),
                        'callback_data' => 'bsc:submit:'.$order->id,
                        'style' => 'primary',
                    ]],
                ]];
            }
            $lastResponse = $this->bot->sendMessage(
                $chatId,
                $unit->text(),
                $markup,
                $unit->options() + ($isLast ? ($starsPending['options'] ?? []) : []),
            );
        }
        if ($sakurupiahPresentationRecovered) {
            $effect = $this->outboundEffects->recordSakurupiahPresentationRecovery($order, $payment, $customer);
            if ($effect !== null) {
                $this->outboundEffectDispatcher->dispatch($effect);
            }
        }
        if ($wijayapayPresentationRecovered) {
            $effect = $this->outboundEffects->recordWijayaPayPresentationRecovery($order, $payment, $customer);
            if ($effect !== null) {
                $this->outboundEffectDispatcher->dispatch($effect);
            }
        }

        $messageId = data_get($lastResponse, 'result.message_id');
        if ($payment?->provider === PaymentProvider::KLIKQRIS && $paymentStatus === PaymentStatus::Pending && is_numeric($messageId)) {
            Order::query()->whereKey($order->id)->update([
                'buyer_order_detail_message_chat_id' => $chatId,
                'buyer_order_detail_message_id' => (int) $messageId,
                'buyer_order_detail_message_deleted_at' => null,
                'buyer_order_detail_message_deletion_started_at' => null,
                'buyer_order_detail_message_deletion_token' => null,
            ]);
        }
    }

    public function orderInstruction(Customer $customer, Order $order, ?Payment $payment): string
    {
        $orderStatus = $this->visibleOrderStatus($order);
        $paymentStatus = $payment?->status ?? $order->payment_status;
        if ($order->hasOpenLatePaymentReview()) {
            return $this->locale->translate(
                $customer,
                $orderStatus === OrderStatus::Cancelled
                    ? 'order.guidance.manual_review_cancelled'
                    : 'order.guidance.manual_review',
            );
        }
        if ($paymentStatus === PaymentStatus::Refunded) {
            if ($payment?->isTelegramStars()) {
                return $this->locale->translate($customer, 'stars.refunded', ['total' => $payment->amount]);
            }
            if ($payment?->provider === PaymentProvider::USDT_BALANCE) {
                return $this->locale->translate($customer, 'order.guidance.usdt_balance_refunded', [
                    'amount' => UsdtMicroAmount::fromUnits($payment->amount)->decimal(),
                ]);
            }
            if (strtolower(trim((string) $payment?->provider)) === PaymentProvider::STORE_BALANCE) {
                return $this->locale->translate($customer, 'order.guidance.store_balance_refunded', [
                    'amount' => 'Rp '.number_format((int) ($payment?->amount ?? $order->total_amount), 0, ',', '.'),
                ]);
            }

            return $this->locale->translate($customer, 'order.guidance.refunded');
        }
        if (strtolower(trim((string) $payment?->provider)) === PaymentProvider::STORE_BALANCE
            && $paymentStatus === PaymentStatus::Paid
            && $orderStatus === OrderStatus::Processing
            && $order->telegram_receipt_sent_at === null) {
            return $this->locale->translate($customer, 'order.guidance.store_balance_pending_delivery');
        }
        if ($orderStatus === OrderStatus::Expired || $orderStatus === OrderStatus::Cancelled) {
            if ($paymentStatus === PaymentStatus::Paid) {
                return $this->locale->translate(
                    $customer,
                    $orderStatus === OrderStatus::Cancelled
                        ? 'order.guidance.late_payment_cancelled'
                        : 'order.guidance.late_payment',
                );
            }
            if ($paymentStatus === PaymentStatus::Failed) {
                return $this->locale->translate($customer, 'order.guidance.payment_failed');
            }
            if ($orderStatus === OrderStatus::Expired) {
                return $this->locale->translate($customer, 'order.guidance.expired');
            }

            return $this->locale->translate($customer, 'order.guidance.cancelled');
        }
        if ($paymentStatus === PaymentStatus::Failed) {
            return $this->locale->translate($customer, 'order.guidance.payment_failed');
        }
        if ($paymentStatus === PaymentStatus::Expired) {
            return $this->locale->translate($customer, 'order.guidance.expired');
        }
        if ($orderStatus === OrderStatus::PendingPayment || $paymentStatus === PaymentStatus::Pending) {
            if ($payment?->isTelegramStars()) {
                return $this->locale->translate($customer, 'stars.status_pending');
            }
            if (CheckoutPaymentIntents::isBinancePaymentMethod($payment?->method)) {
                return $this->locale->translate($customer, 'order.guidance.crypto_pending');
            }

            return $this->locale->translate($customer, 'order.guidance.pending');
        }
        if ($orderStatus === OrderStatus::Processing) {
            return $this->locale->translate($customer, 'order.guidance.processing');
        }
        if ($orderStatus === OrderStatus::Completed) {
            return $this->locale->translate($customer, 'order.guidance.completed');
        }
        if ($paymentStatus === PaymentStatus::Paid) {
            return $this->locale->translate($customer, 'order.guidance.processing');
        }

        return $this->locale->translate($customer, 'order.guidance.support');
    }

    private function refreshProviderPaymentStatus(Customer $customer, Order $order, ?Payment $payment): ?string
    {
        return match (strtolower(trim((string) $payment?->provider))) {
            PaymentProvider::PAKASIR => $this->refreshPendingPakasirPayment($customer, $order, $payment),
            PaymentProvider::KLIKQRIS => $this->refreshEligibleKlikQrisPayment($customer, $payment),
            PaymentProvider::SAKURUPIAH => $this->refreshPendingSakurupiahPresentation($customer, $order, $payment),
            PaymentProvider::WIJAYAPAY => $this->refreshPendingWijayaPayPresentation($customer, $order, $payment),
            default => null,
        };
    }

    private function isSakurupiahRefreshable(?Payment $payment): bool
    {
        return $payment !== null
            && strtolower(trim((string) $payment->provider)) === PaymentProvider::SAKURUPIAH
            && $this->sakurupiahLifecycle->isCustomerStatusRefreshable($payment);
    }

    private function isSakurupiahPayable(?Payment $payment): bool
    {
        return $payment !== null
            && strtolower(trim((string) $payment->provider)) === PaymentProvider::SAKURUPIAH
            && $this->sakurupiahLifecycle->classify($payment)->isPayable();
    }

    private function isWijayaPayRefreshable(?Payment $payment): bool
    {
        return $payment !== null
            && strtolower(trim((string) $payment->provider)) === PaymentProvider::WIJAYAPAY
            && $this->wijayapayLifecycle->isCustomerStatusRefreshable($payment);
    }

    private function isWijayaPayPayable(?Payment $payment): bool
    {
        return $payment !== null
            && strtolower(trim((string) $payment->provider)) === PaymentProvider::WIJAYAPAY
            && $this->wijayapayLifecycle->classify($payment)->isPayable();
    }

    private function refreshEligibleKlikQrisPayment(Customer $customer, ?Payment $payment): ?string
    {
        if (! $payment
            || ! $this->klikQrisNativeQris->isCustomerStatusRefreshable($payment)
            || ! $this->klikQrisReadiness->checkout()->isReady()) {
            return null;
        }

        $key = 'klikqris:status-refresh:'.$customer->id.':'.$payment->id;
        $maxAttempts = max(1, (int) config('services.klikqris.status_refresh_rate_limit_per_minute', 3));
        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $seconds = RateLimiter::availableIn($key);

            return $this->locale->translate($customer, 'order.refresh.rate_limited', ['seconds' => $seconds]);
        }

        RateLimiter::hit($key, 60);

        return $this->queueProviderStatusRefresh($customer, $payment);
    }

    private function refreshPendingPakasirPayment(Customer $customer, Order $order, ?Payment $payment): ?string
    {
        if (! $payment
            || $payment->provider !== PaymentProvider::PAKASIR
            || $payment->status !== PaymentStatus::Pending
            || $order->order_status !== OrderStatus::PendingPayment
            || blank($payment->merchant_ref)
            || ! $this->pakasirReadiness->verification()->isReady()) {
            return null;
        }

        $key = 'pakasir:status-refresh:'.$customer->id.':'.$payment->id;
        $maxAttempts = max(1, (int) config('services.pakasir.status_refresh_rate_limit_per_minute', 3));
        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $seconds = RateLimiter::availableIn($key);

            return $this->locale->translate($customer, 'order.refresh.pakasir_rate_limited', ['seconds' => $seconds]);
        }

        RateLimiter::hit($key, 60);

        return $this->queueProviderStatusRefresh($customer, $payment);
    }

    private function queueProviderStatusRefresh(Customer $customer, Payment $payment): string
    {
        try {
            VerifyProviderPaymentStatus::dispatch(
                (string) $payment->id,
                (string) $customer->id,
                (string) $payment->provider,
                now()->toISOString(),
            )->afterCommit();
        } catch (Throwable $exception) {
            report($exception);

            return $this->locale->translate($customer, 'order.refresh.unavailable');
        }

        return $this->locale->translate($customer, 'order.refresh.pending');
    }

    private function refreshPendingSakurupiahPresentation(Customer $customer, Order $order, ?Payment $payment): ?string
    {
        if (! $this->isSakurupiahRefreshable($payment)
            || $order->order_status !== OrderStatus::PendingPayment
            || ! $this->sakurupiahReadiness->checkout()->isReady()) {
            return null;
        }

        $key = 'sakurupiah:status-refresh:'.$customer->id.':'.$payment->id;
        $maxAttempts = max(1, (int) config('services.sakurupiah.status_refresh_rate_limit_per_minute', 3));
        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $seconds = RateLimiter::availableIn($key);

            return $this->locale->translate($customer, 'order.refresh.rate_limited', ['seconds' => $seconds]);
        }

        RateLimiter::hit($key, 60);

        try {
            $result = ProviderVerificationProfiles::using(
                ProviderVerificationProfile::Interactive,
                fn () => $this->isSakurupiahPayable($payment)
                    ? $this->sakurupiahProcessor->reconcilePayment($payment)
                    : $this->sakurupiahLifecycle->tryRefreshStatus($payment),
            );
        } catch (Throwable $exception) {
            report($exception);

            return $this->locale->translate($customer, 'order.refresh.unavailable');
        }

        if ($result === SakurupiahReconciliationOutcome::Busy) {
            return $this->locale->translate($customer, 'order.refresh.unavailable');
        }
        if ($payment->fresh()->status === PaymentStatus::Paid) {
            return $this->locale->translate($customer, 'order.refresh.verified');
        }

        return $this->locale->translate($customer, 'order.refresh.not_paid');
    }

    private function refreshPendingWijayaPayPresentation(Customer $customer, Order $order, ?Payment $payment): ?string
    {
        if (! $this->isWijayaPayRefreshable($payment)
            || $order->order_status !== OrderStatus::PendingPayment
            || ! $this->wijayapayReadiness->checkout()->isReady()
        ) {
            return null;
        }

        $key = 'wijayapay:status-refresh:'.$customer->id.':'.$payment->id;
        $maxAttempts = max(1, (int) config('services.wijayapay.status_refresh_rate_limit_per_minute', 3));
        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $seconds = RateLimiter::availableIn($key);

            return $this->locale->translate($customer, 'order.refresh.rate_limited', ['seconds' => $seconds]);
        }

        RateLimiter::hit($key, 60);

        try {
            $result = ProviderVerificationProfiles::using(
                ProviderVerificationProfile::Interactive,
                fn () => $this->isWijayaPayPayable($payment)
                    ? $this->wijayapayProcessor->reconcilePayment($payment)
                    : $this->wijayapayLifecycle->tryRefreshStatus($payment),
            );
        } catch (Throwable $exception) {
            report($exception);

            return $this->locale->translate($customer, 'order.refresh.unavailable');
        }

        if ($result === null || $result === WijayaPayReconciliationOutcome::Busy) {
            return $this->locale->translate($customer, 'order.refresh.unavailable');
        }
        if ($payment->fresh()->status === PaymentStatus::Paid) {
            return $this->locale->translate($customer, 'order.refresh.verified');
        }

        return $this->locale->translate($customer, 'order.refresh.not_paid');
    }

    /** @return list<TelegramMessageUnit> */
    private function unitsFromLines(array $lines): array
    {
        return array_map(
            fn (string $chunk): TelegramMessageUnit => new TelegramMessageUnit(
                text: $chunk,
            ),
            $this->messageChunker->fromLines($lines),
        );
    }
}
