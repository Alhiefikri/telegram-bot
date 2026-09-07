<?php

namespace Tests\Unit\Services\Sakurupiah;

use App\Services\Sakurupiah\SakurupiahSignature;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class SakurupiahSignatureTest extends TestCase
{
    public function test_invoice_signature_uses_exact_submitted_amount_and_lowercase_hex(): void
    {
        $signature = SakurupiahSignature::invoice(
            apiId: 'api-test',
            method: 'QRISC',
            merchantRef: 'INV-408',
            amountSubmitted: '0012000',
            apiKey: 'secret-key',
        );

        $this->assertSame('b6bc2c4327846d2cd5c661a7ed9a5c1b2537512837a36bc6b709060776f544c2', $signature);
    }

    public function test_callback_signature_uses_raw_body_without_reencoding(): void
    {
        $rawBody = '{"trx_id":"trx-408","merchant_ref":"INV-408","status":"pending","status_kode":0}';
        $signature = SakurupiahSignature::callback($rawBody, 'secret-key');
        $reencoded = json_encode(json_decode($rawBody, true), JSON_PRETTY_PRINT);

        $this->assertTrue(SakurupiahSignature::verifyCallback($rawBody, $signature, 'secret-key'));
        $this->assertFalse(SakurupiahSignature::verifyCallback($reencoded, $signature, 'secret-key'));
        $this->assertFalse(SakurupiahSignature::verifyCallback($rawBody, strtoupper($signature), 'secret-key'));
    }

    public function test_invalid_hmac_inputs_fail_closed(): void
    {
        $this->assertFalse(SakurupiahSignature::verifyCallback('', '', 'secret-key'));
        $this->assertFalse(SakurupiahSignature::verifyCallback('body', null, 'secret-key'));
        $this->assertFalse(SakurupiahSignature::verifyCallback('body', 'signature', ''));

        $this->expectException(InvalidArgumentException::class);
        SakurupiahSignature::invoice('api-test', 'QRISC', 'INV-408', '12.50', 'secret-key');
    }
}
