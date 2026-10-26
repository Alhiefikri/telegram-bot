<?php

namespace App\Services\Checkout\PaymentPlan;

use App\Models\Payment;
use App\Services\Sakurupiah\SakurupiahChannelPolicy;
use App\Services\Sakurupiah\SakurupiahCreateInvoice;
use App\Support\PaymentProvider;
use Carbon\CarbonInterface;
use InvalidArgumentException;

final readonly class SakurupiahCheckoutPaymentSnapshot extends AbstractCheckoutPaymentSnapshot
{
    public function __construct(
        string $provider,
        string $method,
        string $paymentCurrency,
        int $amount,
        CarbonInterface $expiresAt,
        public string $channel = SakurupiahCreateInvoice::METHOD_QRISC,
    ) {
        parent::__construct($provider, $method, $paymentCurrency, $amount, $expiresAt);

        if ($provider !== PaymentProvider::SAKURUPIAH || $method !== 'QRIS' || $paymentCurrency !== 'IDR') {
            throw new InvalidArgumentException('Sakurupiah QRIS payment snapshot has an invalid rail identity.');
        }
        if (! SakurupiahChannelPolicy::supports($channel)) {
            throw new InvalidArgumentException('Sakurupiah QRIS payment snapshot has an invalid channel.');
        }
    }

    public function persist(Payment $payment): void
    {
        $payment->update(['sakurupiah_channel' => $this->channel]);
    }
}
