<?php

namespace App\Services\WijayaPay;

use InvalidArgumentException;

final class WijayaPayCreateInvoice
{
    public const PAYMENT_METHOD_QRIS = 'QRIS';

    public function __construct(
        public string $codeMerchant,
        public string $apiKey,
        public string $paymentMethod,
        public string $refId,
        public int $amount,
        public string $callbackUrl,
        public ?string $customerName = null,
    ) {
        foreach ([$codeMerchant, $apiKey, $paymentMethod, $refId, $callbackUrl] as $value) {
            if ($value === '' || trim($value) !== $value) {
                throw new InvalidArgumentException('WijayaPay invoice request text is invalid.');
            }
        }

        if (strtoupper($paymentMethod) !== self::PAYMENT_METHOD_QRIS) {
            throw new InvalidArgumentException('WijayaPay invoice method must be QRIS.');
        }

        if ($amount < 1) {
            throw new InvalidArgumentException('WijayaPay invoice amount is invalid.');
        }

        if ($customerName !== null) {
            $name = trim(preg_replace('/\s+/', ' ', $customerName) ?? '');
            $name = mb_substr($name, 0, 100);
            $this->customerName = $name === '' ? null : $name;
        }
    }

    /** @return array<string, int|string> */
    public function toProviderPayload(): array
    {
        $payload = [
            'code_merchant' => $this->codeMerchant,
            'api_key' => $this->apiKey,
            // The published request example uses payment_method while the
            // parameter table still documents code_payment. Both carry the same
            // value during qualification; neither is derived from customer input.
            'payment_method' => strtoupper($this->paymentMethod),
            'code_payment' => strtoupper($this->paymentMethod),
            'ref_id' => $this->refId,
            'nominal' => $this->amount,
            'callback_url' => $this->callbackUrl,
        ];

        if ($this->customerName !== null) {
            $payload['customer_name'] = $this->customerName;
        }

        return $payload;
    }

    public function signature(): string
    {
        return WijayaPaySignature::create($this->codeMerchant, $this->apiKey, $this->refId);
    }
}
