<?php

namespace App\Storefront;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Services\Binance\BinancePaymentVerification;
use App\Services\Binance\BinanceVerificationStatus;
use App\Services\Bsc\BscExplorerUrl;
use App\Services\Bsc\BscPaymentVerification;
use App\Services\Bsc\BscVerificationStatus;
use App\Services\Credentials\CredentialDelivery;
use App\Services\Credentials\CredentialDeliveryPresentation;
use App\Services\KlikQris\KlikQrisNativeQrisLifecycleResult;
use App\Services\KlikQris\KlikQrisNativeQrisOutcome;
use App\Services\KlikQris\KlikQrisNativeQrisService;
use App\Services\KlikQris\KlikQrisPayableNativeQris;
use App\Services\Sakurupiah\SakurupiahInvoiceLifecycleResult;
use App\Services\Sakurupiah\SakurupiahInvoiceLifecycleService;
use App\Services\Sakurupiah\SakurupiahInvoiceOutcome;
use App\Services\Sakurupiah\SakurupiahPayableInvoice;
use App\Support\PaymentProvider;
use App\Services\OrderLifecycle;
use App\Services\OrderLifecycleProjection;
use App\Services\Telegram\TelegramQrCodeRenderer;

class StorefrontShopSignedInvoice
{
    public function __construct(
        private readonly TelegramQrCodeRenderer $qrCodes,
        private readonly StorefrontInvoiceUrl $invoiceUrls,
        private readonly CredentialDeliveryPresentation $credentialPresentation,
        private readonly OrderLifecycle $orders,
        private readonly StorefrontPakasirHostedPaymentUrl $hostedPaymentUrls,
        private readonly StorefrontPaymentTerminalCopy $terminalCopy,
        private readonly BinancePaymentVerification $verification,
        private readonly BscPaymentVerification $bscVerification,
        private readonly BscExplorerUrl $bscExplorer,
        private readonly KlikQrisNativeQrisService $klikQris,
        private readonly SakurupiahInvoiceLifecycleService $sakurupiah,
    ) {}

    public function read(Order $order): StorefrontShopSignedInvoiceReadResult
    {
        $order->loadMissing(['customer', 'orderItems.stockItems', 'orderItems.product']);

        $payment = $this->latestPayment($order);
        $payment?->loadMissing(['bscDetail.transferEvidence']);
        $projection = $this->orders->project($order, $payment);
        $verificationStatus = $this->binanceVerificationStatus($payment);
        $bscVerificationStatus = $this->bscVerificationStatus($payment);
        $state = $this->state($projection);
        $redirectUrl = $this->redirectUrl($order);
        $klikQrisResult = $this->isKlikQrisPayment($payment) ? $this->klikQris->classify($payment) : null;
        $sakurupiahResult = $this->isSakurupiahPayment($payment) ? $this->sakurupiah->classify($payment) : null;
        $qrisReadiness = $this->qrisReadiness($projection, $payment, $klikQrisResult, $sakurupiahResult);
        $delivery = $this->credentialPresentation->forOrder($order, $payment);
        $accountGroups = $this->accountGroups($delivery);
        $klikPayable = $projection->isPendingPayment ? $klikQrisResult?->payable() : null;
        $sakurupiahPayable = $projection->isPendingPayment ? $sakurupiahResult?->payable() : null;
        $payable = $klikPayable ?? $sakurupiahPayable;

        return new StorefrontShopSignedInvoiceReadResult(
            order: $order,
            payment: $payment,
            state: $state,
            statusUrl: $this->invoiceUrls->status($order),
            binanceOrderIdUrl: $this->invoiceUrls->binanceOrderId($order),
            bscTxHashUrl: $this->invoiceUrls->bscTxHash($order),
            redirectUrl: $redirectUrl,
            cancelUrl: $this->invoiceUrls->cancel($order),
            canCancel: $this->canCancel($order, $projection),
            payableAmount: $payable?->totalAmount() ?? ($qrisReadiness['is_payable'] ? $payment?->provider_total_payment : null),
            payableAmountFromProvider: $qrisReadiness['is_payable'],
            isQrisPayable: $qrisReadiness['is_payable'],
            qrisReadinessState: $qrisReadiness['state'],
            qrisReadinessTitle: $qrisReadiness['title'],
            qrisReadinessMessage: $qrisReadiness['message'],
            qrisRetryLabel: $qrisReadiness['retry_label'],
            qrisImageDataUri: $this->qrisImageDataUri($projection, $payment),
            qrisImageSource: $this->qrisImageSource($order, $projection, $payment, $klikPayable, $sakurupiahPayable),
            payableAmountDisplay: $payable?->totalAmountDisplay(),
            qrisEffectiveExpiresAt: $payable?->effectiveExpiresAt(),
            hostedPaymentUrl: $this->hostedPaymentUrl($projection, $payment, $sakurupiahPayable),
            accountGroups: $accountGroups,
            usageGuides: $state === StorefrontShopSignedInvoiceState::Paid ? $delivery->usageGuides() : [],
            accountCopyText: $this->accountCopyText($accountGroups),
            paidDeliveryType: $this->paidDeliveryType($order, $state, $accountGroups),
            manualFulfillmentStatus: $this->manualFulfillmentStatus($order, $payment),
            statusMessage: $this->statusMessage($order, $payment, $verificationStatus, $bscVerificationStatus),
            binanceVerificationStatus: $verificationStatus,
            bscVerificationStatus: $bscVerificationStatus,
            bscExplorerUrl: $this->bscExplorerUrl($payment),
        );
    }

    private function latestPayment(Order $order): ?Payment
    {
        /** @var Payment|null */
        return $order->payments()
            ->latest('created_at')
            ->first();
    }

    private function binanceVerificationStatus(?Payment $payment): ?BinanceVerificationStatus
    {
        if (strtolower((string) $payment?->provider) !== 'manual'
            || strtoupper((string) $payment?->method) !== 'BINANCE_PAY_ID') {
            return null;
        }

        return $this->verification->status($payment);
    }

    private function bscVerificationStatus(?Payment $payment): ?BscVerificationStatus
    {
        if (strtolower((string) $payment?->provider) !== 'manual'
            || strtoupper((string) $payment?->method) !== 'USDT_BEP20') {
            return null;
        }

        return $this->bscVerification->status($payment);
    }

    private function bscExplorerUrl(?Payment $payment): ?string
    {
        if (strtolower((string) $payment?->provider) !== 'manual'
            || strtoupper((string) $payment?->method) !== 'USDT_BEP20') {
            return null;
        }

        $evidence = $payment?->bscDetail?->transferEvidence
            ?->sortByDesc(fn ($transfer): int => $transfer->last_verified_at?->getTimestamp() ?? 0)
            ->first();

        return $this->bscExplorer->forTransaction($evidence?->tx_hash);
    }

    private function state(OrderLifecycleProjection $projection): StorefrontShopSignedInvoiceState
    {
        if ($projection->isPaid) {
            return StorefrontShopSignedInvoiceState::Paid;
        }

        if ($projection->isPendingPayment) {
            return StorefrontShopSignedInvoiceState::Pending;
        }

        return StorefrontShopSignedInvoiceState::Terminal;
    }

    private function canCancel(Order $order, OrderLifecycleProjection $projection): bool
    {
        return $projection->isPendingPayment
            && ! $order->payments()->whereNotNull('payment_proof_submitted_at')->exists();
    }

    private function isPakasirPayment(?Payment $payment): bool
    {
        return strtolower((string) $payment?->provider) === 'pakasir';
    }

    private function isKlikQrisPayment(?Payment $payment): bool
    {
        return strtolower((string) $payment?->provider) === 'klikqris';
    }

    private function isSakurupiahPayment(?Payment $payment): bool
    {
        return strtolower((string) $payment?->provider) === PaymentProvider::SAKURUPIAH;
    }

    private function qrisImageDataUri(OrderLifecycleProjection $projection, ?Payment $payment): ?string
    {
        if (! $projection->isPendingPayment || $payment?->isPayableNativePakasirQris() !== true) {
            return null;
        }

        return 'data:image/png;base64,'.base64_encode($this->qrCodes->png($payment->provider_payment_number));
    }

    private function qrisImageSource(
        Order $order,
        OrderLifecycleProjection $projection,
        ?Payment $payment,
        ?KlikQrisPayableNativeQris $klikPayable,
        ?SakurupiahPayableInvoice $sakurupiahPayable,
    ): ?string {
        if (! $projection->isPendingPayment) {
            return null;
        }

        if ($klikPayable !== null) {
            return $klikPayable->qrisUrl();
        }

        if ($sakurupiahPayable?->isDirectQris()) {
            return $this->invoiceUrls->qrisImage($order);
        }

        if (! $this->hasPayableNativePakasirQris($payment)) {
            return null;
        }

        return $this->qrisImageDataUri($projection, $payment);
    }

    private function hostedPaymentUrl(
        OrderLifecycleProjection $projection,
        ?Payment $payment,
        ?SakurupiahPayableInvoice $sakurupiahPayable,
    ): ?string {
        if (! $projection->isPendingPayment) {
            return null;
        }

        if ($this->isSakurupiahPayment($payment)) {
            return $sakurupiahPayable?->isHosted() === true
                ? $sakurupiahPayable->checkoutUrl()
                : null;
        }

        if (! $this->isPakasirPayment($payment)) {
            return null;
        }

        return $this->hostedPaymentUrls->for($payment);
    }

    public function statusMessage(
        Order $order,
        ?Payment $payment,
        ?BinanceVerificationStatus $verificationStatus = null,
        ?BscVerificationStatus $bscVerificationStatus = null,
    ): string {
        $projection = $this->orders->project($order, $payment);

        if ($projection->isTerminal) {
            if ($bscVerificationStatus !== null) {
                return $this->terminalCopy->bscInvoiceStatusMessage($bscVerificationStatus);
            }

            return $this->terminalCopy->message($projection, $payment);
        }

        if ($bscVerificationStatus !== null) {
            return $this->terminalCopy->bscInvoiceStatusMessage($bscVerificationStatus);
        }

        $verificationStatus ??= $this->binanceVerificationStatus($payment);
        if ($verificationStatus === null) {
            return $this->terminalCopy->message($projection, $payment);
        }

        return $this->terminalCopy->binanceStatusMessage($projection, $payment, $verificationStatus);
    }

    /**
     * @return array{is_payable: bool, state: string, title: string, message: string, retry_label: string}
     */
    private function qrisReadiness(
        OrderLifecycleProjection $projection,
        ?Payment $payment,
        ?KlikQrisNativeQrisLifecycleResult $klikQrisResult = null,
        ?SakurupiahInvoiceLifecycleResult $sakurupiahResult = null,
    ): array {
        if (! $projection->isPendingPayment) {
            return ['is_payable' => false] + $this->terminalCopy->qrisReadiness('terminal');
        }

        if ($payment?->provider === 'manual' && $payment->method === 'QR_MANUAL') {
            return ['is_payable' => false] + $this->terminalCopy->qrisReadiness('manual');
        }

        if ($payment?->provider === 'pakasir' && $payment->method === 'hosted') {
            return ['is_payable' => false] + $this->terminalCopy->qrisReadiness('hosted');
        }

        if ($sakurupiahResult !== null) {
            return match ($sakurupiahResult->outcome()) {
                SakurupiahInvoiceOutcome::Payable => ['is_payable' => true] + $this->terminalCopy->qrisReadiness('ready'),
                SakurupiahInvoiceOutcome::Creating => ['is_payable' => false] + $this->terminalCopy->qrisReadiness('creating'),
                SakurupiahInvoiceOutcome::RecoveryPending => ['is_payable' => false] + $this->terminalCopy->qrisReadiness('recovery_pending'),
                SakurupiahInvoiceOutcome::ProviderTotalPending => ['is_payable' => false] + $this->terminalCopy->qrisReadiness('provider_total_pending'),
                SakurupiahInvoiceOutcome::InvalidProviderResult => ['is_payable' => false] + $this->terminalCopy->qrisReadiness('failed'),
                SakurupiahInvoiceOutcome::Expired => ['is_payable' => false] + $this->terminalCopy->qrisReadiness('expired'),
            };
        }

        if ($klikQrisResult !== null) {
            return match ($klikQrisResult->outcome()) {
                KlikQrisNativeQrisOutcome::Payable => ['is_payable' => true] + $this->terminalCopy->qrisReadiness('ready'),
                KlikQrisNativeQrisOutcome::Creating => ['is_payable' => false] + $this->terminalCopy->qrisReadiness('creating'),
                KlikQrisNativeQrisOutcome::RecoveryPending => ['is_payable' => false] + $this->terminalCopy->qrisReadiness('recovery_pending'),
                KlikQrisNativeQrisOutcome::ProviderTotalPending => ['is_payable' => false] + $this->terminalCopy->qrisReadiness('provider_total_pending'),
                KlikQrisNativeQrisOutcome::InvalidProviderResult => ['is_payable' => false] + $this->terminalCopy->qrisReadiness('failed'),
                KlikQrisNativeQrisOutcome::Expired => ['is_payable' => false] + $this->terminalCopy->qrisReadiness('expired'),
            };
        }

        if ($this->hasPayableNativePakasirQris($payment)) {
            return ['is_payable' => true] + $this->terminalCopy->qrisReadiness('ready');
        }

        if (filled($payment?->provider_payment_number) && $payment?->provider_total_payment === null) {
            return ['is_payable' => false] + $this->terminalCopy->qrisReadiness('provider_total_pending');
        }

        if ($this->isPakasirPayment($payment)
            && in_array($payment?->provider_create_state, ['outcome_unknown', 'provider_total_pending', 'financial_integrity_failed'], true)) {
            return ['is_payable' => false] + $this->terminalCopy->qrisReadiness('failed');
        }

        return ['is_payable' => false] + $this->terminalCopy->qrisReadiness('creating');
    }

    private function hasPayableNativePakasirQris(?Payment $payment): bool
    {
        return $payment?->isPayableNativePakasirQris() === true;
    }

    /**
     * Keep the legacy secret shape stable while adding structured-account
     * fields only to structured entries. The view consumes this as a safe
     * channel projection; it never reads ProductStockItem payloads directly.
     *
     * @return list<array{product_name: string, secrets: list<array<string, mixed>>, copy_text: string, delivery_note: ?string, usage_guide: null|array{title: string, url: string}}>
     */
    private function accountGroups(CredentialDelivery $delivery): array
    {
        return array_map(function ($group): array {
            $secrets = array_map(function ($account): array {
                $secret = [
                    'text' => $account->text(),
                    'parts' => $account->legacyParts(),
                    'label' => $account->label(),
                ];

                if ($account->isStructured()) {
                    $accessMethod = $account->accessMethod()?->value;
                    $accessValue = $account->accessValue();

                    $secret += [
                        'structured' => true,
                        'number' => $account->number(),
                        'email' => $account->email(),
                        'password' => $account->password(),
                        'access_method' => $accessMethod,
                        'access_value' => $accessValue,
                        'access_domain' => $accessMethod === 'otp_mail_link'
                            ? $this->otpDomain($accessValue)
                            : null,
                        'tutorial' => $account->tutorial(),
                    ];
                }

                return $secret;
            }, $group->accounts());

            return [
                'product_name' => $group->productName(),
                'delivery_note' => $group->defaultTutorial(),
                'usage_guide' => $group->usageGuide(),
                'secrets' => $secrets,
                'copy_text' => collect($secrets)
                    ->map(fn (array $secret, int $index): string => ($index + 1).".\n".$secret['text'])
                    ->implode("\n\n"),
            ];
        }, $delivery->groups());
    }

    /**
     * @param  list<array{product_name: string, secrets: list<array<string, mixed>>, copy_text: string, delivery_note: ?string, usage_guide: null|array{title: string, url: string}}>  $accountGroups
     */
    private function accountCopyText(array $accountGroups): string
    {
        return collect($accountGroups)
            ->map(fn (array $group): string => $group['product_name']."\n".$group['copy_text'])
            ->implode("\n\n");
    }

    /**
     * @param  list<array{product_name: string, secrets: list<array<string, mixed>>, copy_text: string, delivery_note: ?string, usage_guide: null|array{title: string, url: string}}>  $accountGroups
     */
    private function paidDeliveryType(Order $order, StorefrontShopSignedInvoiceState $state, array $accountGroups): string
    {
        if ($state !== StorefrontShopSignedInvoiceState::Paid) {
            return 'none';
        }

        if ($accountGroups !== []) {
            return 'automatic_available';
        }

        return $this->expectsAutomaticCredentials($order) ? 'credentials_unavailable' : 'manual';
    }

    private function manualFulfillmentStatus(Order $order, ?Payment $payment): string
    {
        if ($order->order_status === OrderStatus::Completed && strtolower((string) $payment?->provider) !== 'pakasir') {
            return 'completed';
        }

        return 'processing';
    }

    private function expectsAutomaticCredentials(Order $order): bool
    {
        return $order->orderItems->contains(fn (OrderItem $item): bool => $item->product?->isCredentialManaged() === true);
    }

    private function redirectUrl(Order $order): string
    {
        return $this->invoiceUrls->show($order);
    }

    private function otpDomain(?string $url): ?string
    {
        if ($url === null || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($url);
        $host = is_array($parts) ? ($parts['host'] ?? null) : null;
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';

        if ($scheme !== 'https'
            || ! is_string($host)
            || $host === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            return null;
        }

        return strtolower($host);
    }
}
