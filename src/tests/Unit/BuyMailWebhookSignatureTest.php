<?php

namespace Tests\Unit;

use App\Services\Suppliers\BuyMailWebhookSignature;
use PHPUnit\Framework\TestCase;

final class BuyMailWebhookSignatureTest extends TestCase
{
    public function test_signature_covers_exact_raw_body_and_enforces_both_timestamp_directions(): void
    {
        $body = '{"id":"evt-fixture-1","type":"ping","data":{}}';
        $signature = 't=1760000000,v1=fcd688a786fecfe71d0639b5a3c19601de4cdd92d3d5b83c2b4e6c9a6d05cf28';
        $secret = 'whsec_synthetic-fixture-secret';
        $this->assertTrue(BuyMailWebhookSignature::verify($secret, $body, $signature, 1760000000));
        $this->assertFalse(BuyMailWebhookSignature::verify($secret, $body.' ', $signature, 1760000000));
        $this->assertFalse(BuyMailWebhookSignature::verify('different-secret', $body, $signature, 1760000000));
        $this->assertFalse(BuyMailWebhookSignature::verify($secret, $body, $signature, 1760000301));
        $this->assertFalse(BuyMailWebhookSignature::verify($secret, $body, $signature, 1759999699));
        $this->assertFalse(BuyMailWebhookSignature::verify($secret, $body, '', 1760000000));
    }
}
