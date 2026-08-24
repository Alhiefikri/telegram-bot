<?php

namespace Tests\Unit\Services\Bsc;

use App\Services\Bsc\BscEoaSignatureVerifier;
use Tests\TestCase;

class BscEoaSignatureVerifierTest extends TestCase
{
    public function test_it_recovers_the_eip191_signer_address(): void
    {
        $signature = '0xc6047f9441ed7d6d3045406e95c07cd85c778e4b8cef3ca7abac09b95c709ee5376be76766472fbda215d0cf67a763c77105cc582d528d7c51fea6a0584c37811b';

        $this->assertTrue((new BscEoaSignatureVerifier)->matchesAddress(
            'hello BSC',
            $signature,
            '0x7e5f4552091a69125d5dfcb7b8c2659029395bdf',
        ));
    }

    public function test_wrong_message_or_malformed_signature_does_not_verify(): void
    {
        $verifier = new BscEoaSignatureVerifier;
        $signature = '0xc6047f9441ed7d6d3045406e95c07cd85c778e4b8cef3ca7abac09b95c709ee5376be76766472fbda215d0cf67a763c77105cc582d528d7c51fea6a0584c37811b';

        $this->assertFalse($verifier->matchesAddress('different message', $signature, '0x7e5f4552091a69125d5dfcb7b8c2659029395bdf'));
        $this->assertNull($verifier->recoverAddress('hello BSC', '0x'.str_repeat('0', 130)));
    }
}
