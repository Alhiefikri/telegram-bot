<?php

namespace App\Console\Commands;

use App\Enums\PaymentEventType;
use App\Enums\PaymentStatus;
use App\Models\ManualPaymentSetting;
use App\Models\Payment;
use App\Services\Pakasir\PakasirCallbackProcessor;
use App\Services\Pakasir\PakasirNativeQrisService;
use App\Services\Pakasir\PakasirPayloadSanitizer;
use App\Services\Pakasir\PakasirProviderAdapter;
use App\Services\Pakasir\PakasirProviderReadiness;
use App\Services\Pakasir\PakasirQrisTransaction;
use App\Services\Pakasir\PakasirTransaction;
use App\Support\PakasirCheckoutFlow;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Throwable;

class ValidatePakasirNativeQris extends Command
{
    protected $signature = 'pakasir:validate-native-qris {payment : Payment UUID or merchant_ref from staging} {--create : Create/recover native QRIS through the app idempotency service before validation} {--reconcile : Apply reconciliation when Transaction Detail is completed} {--duplicate-provider-create : Staging-only probe that intentionally calls transactioncreate/qris again for the same payment} {--json : Emit a sanitized JSON report}';

    protected $description = 'Validate a staging Pakasir native QRIS payment without exposing secrets or raw QR payment numbers.';

    public function handle(PakasirNativeQrisService $nativeQris, PakasirProviderAdapter $pakasir, PakasirCallbackProcessor $processor, PakasirProviderReadiness $pakasirReadiness): int
    {
        $report = [
            'command' => 'pakasir:validate-native-qris',
            'generated_at' => now()->toAtomString(),
            'payment' => null,
            'checks' => [],
            'manual_follow_up' => [
                'Confirm Telegram shows a QRIS image generated from payment_number, not a hosted URL.',
                'Trigger Pakasir payment simulation or a real staging payment and confirm webhook reaches /api/webhook/pakasir.',
                'Record the observed duplicate transactioncreate/qris behavior if --duplicate-provider-create is used.',
                'After provider expired_at, confirm local expiry releases stock with orders:expire-pending.',
                'Do not paste API keys, bot tokens, private chat IDs, private URLs, or raw payment_number values into the issue.',
            ],
        ];

        $this->validateConfiguration($report, $pakasirReadiness);

        if ($this->hasHardFailure($report)) {
            return $this->finish($report);
        }

        $payment = $this->findPayment((string) $this->argument('payment'));

        if (! $payment) {
            $this->addCheck($report, 'blocked', 'staging payment lookup', 'No payment matched the supplied UUID/merchant_ref.');

            return $this->finish($report);
        }

        $report['payment'] = $this->paymentSummary($payment);
        $this->validatePaymentShape($report, $payment);

        if ($this->hasHardFailure($report)) {
            return $this->finish($report);
        }

        $nativeTransaction = $this->validateNativeCreate($report, $nativeQris, $payment);
        if ($this->hasHardFailure($report)) {
            return $this->finish($report);
        }

        $payment->refresh();
        $this->validateHostedFallback($report, $pakasir, $payment);

        $detailPayload = $this->validateTransactionDetail($report, $pakasir, $payment, $nativeTransaction);

        if ($detailPayload !== null && (bool) $this->option('reconcile')) {
            $this->validateReconciliation($report, $processor, $payment->fresh(), $detailPayload);
        } elseif ($detailPayload !== null) {
            $transaction = PakasirTransaction::fromPayload($detailPayload);
            if ($transaction?->isCompleted()) {
                $this->addCheck($report, 'manual', 'reconciliation state change', 'Transaction Detail is completed; rerun with --reconcile or run pakasir:reconcile to prove paid transition.');
            }
        }

        if ((bool) $this->option('duplicate-provider-create')) {
            $this->probeDuplicateProviderCreate($report, $pakasir, $payment->fresh(), $nativeTransaction);
        }

        $this->validateStoredPayloadsAreSanitized($report, $payment->fresh());

        return $this->finish($report);
    }

    private function validateConfiguration(array &$report, PakasirProviderReadiness $pakasirReadiness): void
    {
        $setting = ManualPaymentSetting::current();
        $paymentMode = (string) ($setting?->payment_mode ?: 'manual');
        $checkoutFlow = (string) ($setting?->pakasir_checkout_flow ?: PakasirCheckoutFlow::HOSTED);
        $readiness = $pakasirReadiness->checkout($checkoutFlow);

        if ($paymentMode !== 'pakasir') {
            $this->addCheck($report, 'fail', 'dashboard payment_mode=pakasir', "Configured dashboard value is {$paymentMode}.");
        } else {
            $this->addCheck($report, 'pass', 'dashboard payment_mode=pakasir');
        }

        if ($checkoutFlow !== PakasirCheckoutFlow::API_QRIS) {
            $this->addCheck($report, 'fail', 'dashboard pakasir_checkout_flow=api_qris', "Configured dashboard value is {$checkoutFlow}.");
        } else {
            $this->addCheck($report, 'pass', 'dashboard pakasir_checkout_flow=api_qris');
        }

        if ($checkoutFlowMessage = $readiness->invalidMessage('pakasir_checkout_flow')) {
            $this->addCheck($report, 'fail', 'dashboard pakasir_checkout_flow valid', $checkoutFlowMessage);
        }

        foreach (['PAKASIR_SLUG', 'PAKASIR_API_KEY'] as $label) {
            if ($readiness->isMissing($label)) {
                $this->addCheck($report, 'fail', "{$label} configured", 'Value is blank.');
            } else {
                $this->addCheck($report, 'pass', "{$label} configured", 'Value present; raw value redacted.');
            }
        }

        if ($readiness->isMissing('PAKASIR_BASE_URL')) {
            $this->addCheck($report, 'fail', 'PAKASIR_BASE_URL configured', 'Value is blank.');

            return;
        }

        $baseUrl = config('services.pakasir.base_url', '');
        if (! is_string($baseUrl) || ! str_starts_with($baseUrl, 'https://')) {
            $this->addCheck($report, 'fail', 'PAKASIR_BASE_URL uses HTTPS', 'Configured base URL is not HTTPS.');
        } else {
            $this->addCheck($report, 'pass', 'PAKASIR_BASE_URL uses HTTPS', 'URL redacted.');
        }
    }

    private function findPayment(string $lookup): ?Payment
    {
        return Payment::query()
            ->whereKey($lookup)
            ->orWhere('merchant_ref', $lookup)
            ->first();
    }

    private function validatePaymentShape(array &$report, Payment $payment): void
    {
        if ($payment->provider !== 'pakasir') {
            $this->addCheck($report, 'fail', 'payment provider is pakasir', "Provider is {$payment->provider}.");
        } else {
            $this->addCheck($report, 'pass', 'payment provider is pakasir');
        }

        if (! in_array($payment->status, [PaymentStatus::Pending, PaymentStatus::Paid], true)) {
            $this->addCheck($report, 'fail', 'payment status is pending or paid', 'Status is '.$payment->status->value.'.');
        } else {
            $this->addCheck($report, 'pass', 'payment status is pending or paid', 'Status is '.$payment->status->value.'.');
        }

        if (blank($payment->merchant_ref)) {
            $this->addCheck($report, 'fail', 'payment has merchant_ref', 'merchant_ref is blank.');
        } else {
            $this->addCheck($report, 'pass', 'payment has merchant_ref', 'merchant_ref hash='.$this->hashValue((string) $payment->merchant_ref).'.');
        }

        if ($payment->amount <= 0) {
            $this->addCheck($report, 'fail', 'payment amount is positive', 'Amount is '.$payment->amount.'.');
        } else {
            $this->addCheck($report, 'pass', 'payment amount is positive', 'amount='.$payment->amount.'.');
        }
    }

    private function validateNativeCreate(array &$report, PakasirNativeQrisService $nativeQris, Payment $payment): ?PakasirQrisTransaction
    {
        $hadPersistedQris = $this->hasPersistedQris($payment);

        if (! $hadPersistedQris && ! (bool) $this->option('create')) {
            $this->addCheck($report, 'blocked', 'native QRIS transaction exists', 'No persisted native QRIS fields. Rerun with --create against a disposable staging payment.');

            return null;
        }

        $providerCreateCountBefore = $this->providerCreateCount($payment);

        try {
            $first = $nativeQris->ensureCreated($payment);
            $payment->refresh();
            $second = $nativeQris->ensureCreated($payment);
            $payment->refresh();
        } catch (Throwable $exception) {
            $this->addCheck($report, 'blocked', 'native QRIS create/detail recovery', $this->safeException($exception));

            return null;
        }

        $providerCreateCountAfter = $this->providerCreateCount($payment);
        $createdDelta = $providerCreateCountAfter - $providerCreateCountBefore;

        if ($createdDelta > 1) {
            $this->addCheck($report, 'fail', 'normal app flow avoids duplicate provider create', "provider_create event delta={$createdDelta}.");
        } else {
            $this->addCheck($report, 'pass', 'normal app flow avoids duplicate provider create', "provider_create event delta={$createdDelta}.");
        }

        if ($first->paymentNumber() !== $second->paymentNumber()) {
            $this->addCheck($report, 'fail', 'idempotent native QRIS payment_number stable', 'Repeated app create returned different redacted payment_number hashes.');
        } else {
            $this->addCheck($report, 'pass', 'idempotent native QRIS payment_number stable', 'payment_number hash='.$this->hashValue($first->paymentNumber()).'.');
        }

        $this->validateNativeTransactionFields($report, $first, 'native create response');

        $paymentNumber = (string) $payment->provider_payment_number;
        if ($paymentNumber === '') {
            $this->addCheck($report, 'fail', 'native QRIS payment_number persisted encrypted', 'provider_payment_number is blank.');
        } elseif (preg_match('#^https?://#i', $paymentNumber) === 1) {
            $this->addCheck($report, 'fail', 'native QRIS payment_number is not a hosted URL', 'provider_payment_number is URL-shaped; raw value redacted.');
        } else {
            $this->addCheck($report, 'pass', 'native QRIS payment_number persisted as redacted non-URL string', 'hash='.$this->hashValue($paymentNumber).'.');
        }

        if ($payment->provider_payment_number_hash !== hash('sha256', $paymentNumber)) {
            $this->addCheck($report, 'fail', 'native QRIS payment_number hash matches encrypted value', 'Stored hash does not match encrypted value.');
        } else {
            $this->addCheck($report, 'pass', 'native QRIS payment_number hash matches encrypted value');
        }

        return $first;
    }

    private function validateNativeTransactionFields(array &$report, PakasirQrisTransaction $transaction, string $source): void
    {
        if ($transaction->paymentMethod() !== 'qris') {
            $this->addCheck($report, 'fail', "{$source} method is qris", 'method='.$transaction->paymentMethod().'.');
        } else {
            $this->addCheck($report, 'pass', "{$source} method is qris");
        }

        $fee = $transaction->fee();
        $totalPayment = $transaction->totalPayment();

        if ($fee === null) {
            $this->addCheck($report, 'fail', "{$source} includes fee", 'fee missing.');
        } else {
            $this->addCheck($report, 'pass', "{$source} includes fee", 'fee='.$fee.'.');
        }

        if ($totalPayment === null) {
            $this->addCheck($report, 'fail', "{$source} includes total_payment", 'total_payment missing.');
        } elseif ($fee !== null && $totalPayment !== $transaction->amount() + $fee) {
            $this->addCheck($report, 'fail', "{$source} total_payment equals amount + fee", 'amount='.$transaction->amount()." fee={$fee} total_payment={$totalPayment}.");
        } else {
            $this->addCheck($report, 'pass', "{$source} total_payment equals amount + fee", 'amount='.$transaction->amount()." fee={$fee} total_payment={$totalPayment}.");
        }

        if ($transaction->expiresAt() === null) {
            $this->addCheck($report, 'fail', "{$source} includes expired_at", 'expired_at missing.');
        } else {
            $this->addCheck($report, 'pass', "{$source} includes expired_at", 'expired_at='.$transaction->expiresAt()->toAtomString().'.');
        }
    }

    private function validateHostedFallback(array &$report, PakasirProviderAdapter $pakasir, Payment $payment): void
    {
        try {
            $hostedUrl = $pakasir->hostedPaymentUrl($payment);
        } catch (Throwable $exception) {
            $this->addCheck($report, 'fail', 'hosted fallback URL generated for same payment', $this->safeException($exception));

            return;
        }

        $query = [];
        parse_str((string) parse_url($hostedUrl, PHP_URL_QUERY), $query);
        $path = (string) parse_url($hostedUrl, PHP_URL_PATH);

        $valid = str_contains($path, '/pay/')
            && str_contains($path, '/'.((string) $payment->amount))
            && ($query['order_id'] ?? null) === $payment->merchant_ref;

        if (! $valid) {
            $this->addCheck($report, 'fail', 'hosted fallback URL generated for same payment', 'URL shape did not match amount/order_id; raw URL redacted.');
        } else {
            $this->addCheck($report, 'pass', 'hosted fallback URL generated for same payment', 'url_hash='.$this->hashValue($hostedUrl).'.');
        }
    }

    private function validateTransactionDetail(array &$report, PakasirProviderAdapter $pakasir, Payment $payment, ?PakasirQrisTransaction $nativeTransaction): ?array
    {
        try {
            $payload = $pakasir->transactionDetail($payment);
        } catch (Throwable $exception) {
            $this->addCheck($report, 'blocked', 'Transaction Detail API reachable', $this->safeException($exception));

            return null;
        }

        $this->addCheck($report, 'pass', 'Transaction Detail API reachable');

        $transaction = PakasirTransaction::fromPayload($payload);
        $transactionPayload = $this->transactionPayload($payload);

        if (! $transaction || ! $transactionPayload) {
            $this->addCheck($report, 'fail', 'Transaction Detail response has transaction payload', 'No transaction payload found.');

            return $payload;
        }

        if ($transaction->project() !== config('services.pakasir.slug')) {
            $this->addCheck($report, 'fail', 'Transaction Detail project matches configured Pakasir slug', 'Project mismatch; raw project redacted.');
        } else {
            $this->addCheck($report, 'pass', 'Transaction Detail project matches configured Pakasir slug');
        }

        if ($transaction->orderId() !== $payment->merchant_ref) {
            $this->addCheck($report, 'fail', 'Transaction Detail order_id matches payment merchant_ref', 'order_id mismatch; raw value redacted.');
        } else {
            $this->addCheck($report, 'pass', 'Transaction Detail order_id matches payment merchant_ref', 'merchant_ref hash='.$this->hashValue((string) $payment->merchant_ref).'.');
        }

        if ($transaction->amount() !== $payment->amount) {
            $relation = $nativeTransaction?->totalPayment() === $transaction->amount() ? 'detail amount equals provider total_payment' : 'detail amount differs from local amount and total_payment';
            $this->addCheck($report, 'fail', 'Transaction Detail amount equals original payment amount', $relation.'. detail_amount='.$transaction->amount().' local_amount='.$payment->amount.'.');
        } else {
            $this->addCheck($report, 'pass', 'Transaction Detail amount equals original payment amount', 'amount='.$transaction->amount().'.');
        }

        if ($transaction->paymentMethod() !== 'qris') {
            $this->addCheck($report, 'fail', 'Transaction Detail payment_method is qris', 'payment_method='.($transaction->paymentMethod() ?? 'missing').'.');
        } else {
            $this->addCheck($report, 'pass', 'Transaction Detail payment_method is qris');
        }

        $this->validateDetailNativeFields($report, $transactionPayload, $payment);

        return $payload;
    }

    private function validateDetailNativeFields(array &$report, array $transactionPayload, Payment $payment): void
    {
        $fee = $this->nullableInt($transactionPayload, 'fee');
        $totalPayment = $this->nullableInt($transactionPayload, 'total_payment');
        $paymentNumber = $this->nullableString($transactionPayload, 'payment_number');
        $expiredAt = $this->nullableString($transactionPayload, 'expired_at');

        if ($fee === null) {
            $this->addCheck($report, 'fail', 'Transaction Detail includes fee for pending native QRIS', 'fee missing.');
        } else {
            $this->addCheck($report, 'pass', 'Transaction Detail includes fee for pending native QRIS', 'fee='.$fee.'.');
        }

        if ($totalPayment === null) {
            $this->addCheck($report, 'fail', 'Transaction Detail includes total_payment for pending native QRIS', 'total_payment missing.');
        } elseif ($fee !== null && $totalPayment !== $payment->amount + $fee) {
            $this->addCheck($report, 'fail', 'Transaction Detail total_payment equals amount + fee', "amount={$payment->amount} fee={$fee} total_payment={$totalPayment}.");
        } else {
            $this->addCheck($report, 'pass', 'Transaction Detail total_payment equals amount + fee', "amount={$payment->amount} fee={$fee} total_payment={$totalPayment}.");
        }

        if ($paymentNumber === null) {
            $this->addCheck($report, 'fail', 'Transaction Detail includes redacted payment_number for pending native QRIS', 'payment_number missing.');
        } elseif (preg_match('#^https?://#i', $paymentNumber) === 1) {
            $this->addCheck($report, 'fail', 'Transaction Detail payment_number is not a hosted URL', 'payment_number is URL-shaped; raw value redacted.');
        } else {
            $this->addCheck($report, 'pass', 'Transaction Detail includes redacted payment_number for pending native QRIS', 'hash='.$this->hashValue($paymentNumber).'.');
        }

        if ($expiredAt === null) {
            $this->addCheck($report, 'fail', 'Transaction Detail includes expired_at for pending native QRIS', 'expired_at missing.');
        } else {
            $this->addCheck($report, 'pass', 'Transaction Detail includes expired_at for pending native QRIS', 'expired_at present.');
        }
    }

    private function validateReconciliation(array &$report, PakasirCallbackProcessor $processor, Payment $payment, array $detailPayload): void
    {
        try {
            $event = $processor->processReconciliationForPayment($payment, $detailPayload);
        } catch (Throwable $exception) {
            $this->addCheck($report, 'fail', 'reconciliation can mark completed native QRIS paid', $this->safeException($exception));

            return;
        }

        $payment->refresh();

        if ($payment->status === PaymentStatus::Paid && $event->processed_at !== null) {
            $this->addCheck($report, 'pass', 'reconciliation can mark completed native QRIS paid', 'payment_status=paid event_processed=true.');
        } else {
            $this->addCheck($report, 'manual', 'reconciliation can mark completed native QRIS paid', 'Transaction Detail was not completed; payment remains '.$payment->status->value.'.');
        }
    }

    private function probeDuplicateProviderCreate(array &$report, PakasirProviderAdapter $pakasir, Payment $payment, ?PakasirQrisTransaction $nativeTransaction): void
    {
        if (app()->environment('production')) {
            $this->addCheck($report, 'fail', 'duplicate provider create probe is staging-only', 'Refusing to run in production.');

            return;
        }

        try {
            $duplicate = $pakasir->createQrisTransaction($payment);
        } catch (Throwable $exception) {
            $this->addCheck($report, 'manual', 'duplicate transactioncreate/qris behavior observed', 'Provider rejected duplicate create: '.$this->safeException($exception));

            return;
        }

        $samePaymentNumber = $nativeTransaction !== null && $duplicate->paymentNumber() === $nativeTransaction->paymentNumber();
        $sameTotal = $nativeTransaction !== null && $duplicate->totalPayment() === $nativeTransaction->totalPayment();

        $this->addCheck(
            $report,
            'manual',
            'duplicate transactioncreate/qris behavior observed',
            'Provider accepted duplicate create; same_payment_number='.($samePaymentNumber ? 'true' : 'false').' same_total_payment='.($sameTotal ? 'true' : 'false').' duplicate_payment_number_hash='.$this->hashValue($duplicate->paymentNumber()).'.'
        );
    }

    private function validateStoredPayloadsAreSanitized(array &$report, Payment $payment): void
    {
        $paymentNumber = (string) $payment->provider_payment_number;
        $providerPayload = is_array($payment->provider_payload) ? $payment->provider_payload : [];
        $apiKey = (string) config('services.pakasir.api_key', '');

        if (PakasirPayloadSanitizer::containsStringValue($providerPayload, $paymentNumber)) {
            $this->addCheck($report, 'fail', 'stored provider_payload omits raw payment_number', 'provider_payload contains raw payment_number.');
        } else {
            $this->addCheck($report, 'pass', 'stored provider_payload omits raw payment_number');
        }

        if (PakasirPayloadSanitizer::containsStringValue($providerPayload, $apiKey)) {
            $this->addCheck($report, 'fail', 'stored provider_payload omits raw Pakasir API key', 'provider_payload contains raw Pakasir API key.');
        } else {
            $this->addCheck($report, 'pass', 'stored provider_payload omits raw Pakasir API key');
        }

        if (PakasirPayloadSanitizer::containsSensitiveKeys($providerPayload)) {
            $this->addCheck($report, 'fail', 'stored provider_payload omits sensitive Pakasir fields', 'provider_payload still contains sensitive provider keys.');
        } else {
            $this->addCheck($report, 'pass', 'stored provider_payload omits sensitive Pakasir fields');
        }

        $eventPayloads = $payment->paymentEvents()
            ->where('provider', 'pakasir')
            ->pluck('raw_payload')
            ->all();

        $eventPaymentNumberPresent = false;
        $eventApiKeyPresent = false;
        $eventSensitiveKeysPresent = false;
        foreach ($eventPayloads as $payload) {
            if (! is_array($payload)) {
                continue;
            }

            if (PakasirPayloadSanitizer::containsStringValue($payload, $paymentNumber)) {
                $eventPaymentNumberPresent = true;
            }

            if (PakasirPayloadSanitizer::containsStringValue($payload, $apiKey)) {
                $eventApiKeyPresent = true;
            }

            if (PakasirPayloadSanitizer::containsSensitiveKeys($payload)) {
                $eventSensitiveKeysPresent = true;
            }
        }

        if ($eventPaymentNumberPresent) {
            $this->addCheck($report, 'fail', 'Pakasir payment events omit raw payment_number', 'payment_events.raw_payload contains raw payment_number.');
        } else {
            $this->addCheck($report, 'pass', 'Pakasir payment events omit raw payment_number');
        }

        if ($eventApiKeyPresent) {
            $this->addCheck($report, 'fail', 'Pakasir payment events omit raw Pakasir API key', 'payment_events.raw_payload contains raw Pakasir API key.');
        } else {
            $this->addCheck($report, 'pass', 'Pakasir payment events omit raw Pakasir API key');
        }

        if ($eventSensitiveKeysPresent) {
            $this->addCheck($report, 'fail', 'Pakasir payment events omit sensitive provider fields', 'payment_events.raw_payload still contains sensitive provider keys.');
        } else {
            $this->addCheck($report, 'pass', 'Pakasir payment events omit sensitive provider fields');
        }
    }

    private function addCheck(array &$report, string $status, string $label, ?string $evidence = null): void
    {
        $report['checks'][] = array_filter([
            'status' => $status,
            'label' => $label,
            'evidence' => $evidence,
        ], fn ($value) => $value !== null);
    }

    private function finish(array $report): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->line('Pakasir native QRIS staging validation (redacted)');

            if (is_array($report['payment'])) {
                $this->line('Payment: '.json_encode($report['payment'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            }

            foreach ($report['checks'] as $check) {
                $status = strtoupper((string) $check['status']);
                $line = "{$status} {$check['label']}";
                if (isset($check['evidence'])) {
                    $line .= ' - '.$check['evidence'];
                }

                match ($check['status']) {
                    'pass' => $this->info($line),
                    'fail', 'blocked' => $this->error($line),
                    'manual' => $this->warn($line),
                    default => $this->line($line),
                };
            }

            $this->warn('Manual follow-up still required before production cutover:');
            foreach ($report['manual_follow_up'] as $item) {
                $this->line('- '.$item);
            }
        }

        return $this->hasHardFailure($report) ? self::FAILURE : self::SUCCESS;
    }

    private function hasHardFailure(array $report): bool
    {
        foreach ($report['checks'] as $check) {
            if (in_array($check['status'], ['fail', 'blocked'], true)) {
                return true;
            }
        }

        return false;
    }

    private function paymentSummary(Payment $payment): array
    {
        return [
            'id' => (string) $payment->getKey(),
            'provider' => $payment->provider,
            'status' => $payment->status->value,
            'merchant_ref_hash' => $this->hashValue((string) $payment->merchant_ref),
            'amount' => $payment->amount,
            'provider_payment_number_hash' => $payment->provider_payment_number_hash,
        ];
    }

    private function providerCreateCount(Payment $payment): int
    {
        return $payment->paymentEvents()
            ->where('provider', 'pakasir')
            ->where('event_type', PaymentEventType::ProviderCreate)
            ->count();
    }

    private function hasPersistedQris(Payment $payment): bool
    {
        return $payment->provider_payment_method === 'qris' && filled($payment->provider_payment_number);
    }

    private function transactionPayload(array $payload): ?array
    {
        $transaction = Arr::get($payload, 'transaction', Arr::get($payload, 'data', $payload));

        if (is_array($transaction) && isset($transaction['transaction']) && is_array($transaction['transaction'])) {
            $transaction = $transaction['transaction'];
        }

        return is_array($transaction) ? $transaction : null;
    }

    private function nullableInt(array $payload, string $key): ?int
    {
        $value = Arr::get($payload, $key);

        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return (int) $value;
        }

        return null;
    }

    private function nullableString(array $payload, string $key): ?string
    {
        $value = Arr::get($payload, $key);

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private function hashValue(string $value): string
    {
        return substr(hash('sha256', $value), 0, 16);
    }

    private function safeException(Throwable $exception): string
    {
        $status = method_exists($exception, 'response') ? $exception->response?->status() : null;

        return $exception::class.($status === null ? '' : " HTTP {$status}");
    }
}
