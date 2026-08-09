<?php

namespace App\Console\Commands;

use App\Enums\KlikQrisEnvironment;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Services\KlikQris\KlikQrisCallbackProcessor;
use App\Services\KlikQris\KlikQrisNativeQrisService;
use App\Services\KlikQris\KlikQrisProviderAdapter;
use App\Services\KlikQris\KlikQrisProviderReadiness;
use App\Support\PaymentProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

class ValidateKlikQrisSandbox extends Command
{
    protected $signature = 'klikqris:validate-sandbox {payment : Payment UUID or merchant_ref} {--create : Create/recover KlikQRIS QRIS through the app idempotency service before validation} {--reconcile : Apply authoritative status processing}';

    protected $description = 'Validate a non-production KlikQRIS sandbox payment without exposing secrets or raw provider payloads.';

    public function handle(
        KlikQrisProviderReadiness $readiness,
        KlikQrisNativeQrisService $nativeQris,
        KlikQrisProviderAdapter $client,
        KlikQrisCallbackProcessor $processor,
    ): int {
        $checks = [];

        if (app()->environment('production') || config('app.env') === 'production') {
            $this->addCheck($checks, 'fail', 'production execution refused', 'KlikQRIS sandbox validation is never authorized in production.');

            return $this->finish($checks, null);
        }
        $readinessReport = $readiness->forEnvironment(KlikQrisEnvironment::Sandbox);
        foreach ($readinessReport->missing() as $label) {
            $this->addCheck($checks, 'fail', $label.' configured', 'Value is blank or disabled.');
        }
        foreach ($readinessReport->invalid() as $label => $message) {
            $this->addCheck($checks, 'fail', $label.' valid', $this->safeMessage($message));
        }
        if (! $readinessReport->isReady()) {
            return $this->finish($checks, null);
        }
        $this->addCheck($checks, 'pass', 'KlikQRIS sandbox readiness');

        $payment = $this->findPayment((string) $this->argument('payment'));
        if (! $payment) {
            $this->addCheck($checks, 'fail', 'payment lookup', 'No payment matched the supplied UUID/merchant_ref.');

            return $this->finish($checks, null);
        }

        if (strtolower(trim((string) $payment->provider)) !== PaymentProvider::KLIKQRIS) {
            $this->addCheck($checks, 'fail', 'payment provider is klikqris', 'Provider is '.$this->safeLabel((string) $payment->provider).'.');

            return $this->finish($checks, $payment);
        }
        $this->addCheck($checks, 'pass', 'payment provider is klikqris');

        if (blank($payment->merchant_ref)) {
            $this->addCheck($checks, 'fail', 'payment has merchant_ref', 'merchant_ref is blank.');
        } else {
            $this->addCheck($checks, 'pass', 'payment has merchant_ref', 'merchant_ref_hash='.$this->hashValue((string) $payment->merchant_ref).'.');
        }

        if (! in_array($payment->status, [PaymentStatus::Pending, PaymentStatus::Paid], true)) {
            $this->addCheck($checks, 'fail', 'payment status is pending or paid', 'Status is '.$payment->status->value.'.');
        } else {
            $this->addCheck($checks, 'pass', 'payment status is pending or paid', 'Status is '.$payment->status->value.'.');
        }

        if ((bool) $this->option('create')) {
            try {
                $first = $nativeQris->lifecycle($payment);
                $payment->refresh();
                $second = $nativeQris->lifecycle($payment);
                $payment->refresh();

                $firstPayable = $first->payable();
                $secondPayable = $second->payable();
                if ($firstPayable !== null
                    && $secondPayable !== null
                    && $firstPayable->qrisUrl() === $secondPayable->qrisUrl()
                    && $firstPayable->totalAmount() === $secondPayable->totalAmount()) {
                    $this->addCheck($checks, 'pass', 'idempotent KlikQRIS create returned stable transaction');
                } elseif (! $first->isPayable() && ! $second->isPayable()) {
                    $this->addCheck($checks, 'fail', 'idempotent KlikQRIS create returned stable transaction', 'Repeated app create did not become payable (outcome='.$second->outcome()->value.').');
                } else {
                    $this->addCheck($checks, 'fail', 'idempotent KlikQRIS create returned stable transaction', 'Repeated app create returned inconsistent redacted fields.');
                }
            } catch (Throwable $exception) {
                $this->addCheck($checks, 'fail', 'idempotent KlikQRIS create returned stable transaction', $this->safeException($exception));
            }
        }

        $payment->refresh();
        $this->validateStoredTransaction($checks, $payment);

        if ((bool) $this->option('reconcile')) {
            try {
                $event = $processor->processReconciliationForPayment($payment, $client->transactionStatus($payment));
                $payment->refresh();
                $this->addCheck(
                    $checks,
                    $event->processed_at !== null && $payment->status === PaymentStatus::Paid ? 'pass' : 'fail',
                    'authoritative status processing marked paid',
                    'payment_status='.$payment->status->value.' event_processed='.($event->processed_at !== null ? 'true' : 'false').'. normalized_status='.$payment->status->value.'.'
                );
            } catch (Throwable $exception) {
                $this->addCheck($checks, 'fail', 'authoritative status processing marked paid', $this->safeException($exception));
            }
        }

        return $this->finish($checks, $payment->fresh());
    }

    private function findPayment(string $lookup): ?Payment
    {
        if (Str::isUuid($lookup)) {
            $byUuid = Payment::query()->whereKey($lookup)->first();
            if ($byUuid !== null) {
                return $byUuid;
            }
        }

        return Payment::query()
            ->where('provider', PaymentProvider::KLIKQRIS)
            ->where('merchant_ref', $lookup)
            ->orderBy('id')
            ->first();
    }

    /** @param list<array{status:string,label:string,message:?string}> $checks */
    private function validateStoredTransaction(array &$checks, Payment $payment): void
    {
        if (! $payment->isNativeKlikQris()) {
            $this->addCheck($checks, 'fail', 'stored KlikQRIS QRIS fields match provider shape', 'Stored QRIS fields are missing or not sandbox-trusted.');

            return;
        }
        $this->addCheck($checks, 'pass', 'stored KlikQRIS QRIS fields match provider shape');

        if ($payment->status === PaymentStatus::Pending && ! $payment->isPayableNativeKlikQris()) {
            $this->addCheck($checks, 'fail', 'QR is payable or paid as expected', 'Pending QRIS is not payable.');
        } elseif ($payment->status === PaymentStatus::Paid || $payment->isPayableNativeKlikQris()) {
            $this->addCheck($checks, 'pass', 'QR is payable or paid as expected', 'normalized_status='.$payment->status->value.'.');
        }
    }

    /** @param list<array{status:string,label:string,message:?string}> $checks */
    private function addCheck(array &$checks, string $status, string $label, ?string $message = null): void
    {
        $checks[] = ['status' => $status, 'label' => $label, 'message' => $message];
    }

    /** @param list<array{status:string,label:string,message:?string}> $checks */
    private function finish(array $checks, ?Payment $payment): int
    {
        foreach ($checks as $check) {
            $line = strtoupper($check['status']).' '.$check['label'];
            if ($check['message'] !== null && $check['message'] !== '') {
                $line .= ' - '.$check['message'];
            }
            $this->line($line);
        }

        if ($payment !== null) {
            $this->line('SUMMARY '.$this->paymentSummary($payment));
        }

        return $this->hasHardFailure($checks) ? self::FAILURE : self::SUCCESS;
    }

    /** @param list<array{status:string,label:string,message:?string}> $checks */
    private function hasHardFailure(array $checks): bool
    {
        foreach ($checks as $check) {
            if ($check['status'] === 'fail') {
                return true;
            }
        }

        return false;
    }

    private function paymentSummary(Payment $payment): string
    {
        return implode(' ', array_filter([
            'payment_uuid='.$payment->id,
            filled($payment->merchant_ref) ? 'merchant_ref_hash='.$this->hashValue((string) $payment->merchant_ref) : null,
            'normalized_status='.$payment->status->value,
            $payment->klikQrisTotalAmountDisplay() !== null ? 'total='.$payment->klikQrisTotalAmountDisplay() : null,
            $payment->provider_expires_at !== null ? 'expires_at='.$payment->provider_expires_at->copy()->utc()->toAtomString() : null,
        ]));
    }

    private function hashValue(string $value): string
    {
        return substr(hash('sha256', $value), 0, 12);
    }

    private function safeException(Throwable $exception): string
    {
        return $exception::class;
    }

    private function safeMessage(string $message): string
    {
        return preg_replace('/[^A-Za-z0-9 .:_=\-\/]/', '', $message) ?: 'Invalid value.';
    }

    private function safeLabel(string $value): string
    {
        $value = strtolower(trim($value));

        return preg_match('/^[a-z0-9_-]{1,40}$/', $value) === 1 ? $value : 'redacted';
    }
}
