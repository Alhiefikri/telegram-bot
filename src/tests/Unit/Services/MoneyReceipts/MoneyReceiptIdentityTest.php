<?php

namespace Tests\Unit\Services\MoneyReceipts;

use App\Services\Bsc\BscTransactionHash;
use App\Services\MoneyReceipts\MoneyReceiptAlias;
use App\Services\MoneyReceipts\MoneyReceiptIdentity;
use App\Services\MoneyReceipts\MoneyReceiptParentClaim;
use App\Services\MoneyReceipts\MoneyReceiptTarget;
use App\Services\MoneyReceipts\VerifiedMoneyReceipt;
use InvalidArgumentException;
use Tests\TestCase;

class MoneyReceiptIdentityTest extends TestCase
{
    public function test_bsc_log_identity_matches_the_legacy_transaction_hash_normalization(): void
    {
        $hash = '0x'.str_repeat('AB', 32);

        $identity = MoneyReceiptIdentity::bscLog(56, '  '.$hash.' ', 3);

        $this->assertSame(strtolower($hash), $identity->txHash());
        $this->assertSame(strtolower($hash), BscTransactionHash::normalize($hash));
        $this->assertSame('v1|bsc_log|chain:56|tx:'.strtolower($hash).'|log:3', $identity->canonicalKey());
    }

    public function test_bsc_log_identity_rejects_invalid_chain_hash_and_log_index(): void
    {
        foreach ([[0, '0x'.str_repeat('ab', 32), 0], [56, '0x1234', 0], [56, '0x'.str_repeat('ab', 32), -1]] as [$chainId, $hash, $logIndex]) {
            try {
                MoneyReceiptIdentity::bscLog($chainId, $hash, $logIndex);
                $this->fail('Expected the BSC receipt identity to be rejected.');
            } catch (InvalidArgumentException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function test_binance_receiver_namespace_stays_stable_when_api_credentials_rotate(): void
    {
        $before = MoneyReceiptIdentity::binancePay('pay_id', '123456789', 'TXN-1');

        config([
            'services.binance.api_key' => 'rotated-api-key',
            'services.binance.api_secret' => 'rotated-api-secret',
        ]);

        $after = MoneyReceiptIdentity::binancePay('pay_id', '123456789', 'TXN-1');

        $this->assertSame('rotated-api-key', config('services.binance.api_key'));
        $this->assertSame('rotated-api-secret', config('services.binance.api_secret'));
        $this->assertSame($before->canonicalKey(), $after->canonicalKey());
        $this->assertSame('v1|provider_reference|binance|pay_id:123456789|TXN-1', $after->canonicalKey());
        $this->assertNotSame(
            $after->canonicalKey(),
            MoneyReceiptIdentity::binancePay('uid', '123456789', 'TXN-1')->canonicalKey(),
        );
        $this->assertNotSame(
            $after->canonicalKey(),
            MoneyReceiptIdentity::binancePay('pay_id', '987654321', 'TXN-1')->canonicalKey(),
        );
        $this->assertNotSame(
            $after->canonicalKey(),
            MoneyReceiptIdentity::binancePay('pay_id', '123456789', 'TXN-2')->canonicalKey(),
        );
    }

    public function test_provider_reference_identity_requires_a_clean_namespace_and_reference(): void
    {
        foreach (['', 'pay|id', 'pay id', str_repeat('a', 65)] as $namespace) {
            try {
                MoneyReceiptIdentity::providerReference('binance', $namespace, 'TXN-1');
                $this->fail('Expected the provider reference namespace to be rejected.');
            } catch (InvalidArgumentException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
        }

        foreach (['', 'ref|erence', 'ref erence', str_repeat('a', 65)] as $reference) {
            try {
                MoneyReceiptIdentity::providerReference('binance', 'account', $reference);
                $this->fail('Expected the provider reference to be rejected.');
            } catch (InvalidArgumentException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
        }

        $this->assertSame(
            'v1|provider_reference|qris|merchant-1|INV-9',
            MoneyReceiptIdentity::providerReference('QRIS', 'merchant-1', 'INV-9')->canonicalKey(),
        );
    }

    public function test_parent_claim_normalizes_the_transaction_hash(): void
    {
        $hash = '0x'.str_repeat('CD', 32);

        $claim = MoneyReceiptParentClaim::for(56, strtoupper($hash));

        $this->assertSame(56, $claim->chainId);
        $this->assertSame(strtolower($hash), $claim->txHash);
    }

    public function test_verified_receipt_fingerprint_binds_beneficiary_amount_currency_precision_and_target(): void
    {
        $base = $this->receipt();
        $fingerprint = $base->fingerprint();

        $variants = [
            $this->receipt(beneficiary: '0x'.str_repeat('b', 40)),
            $this->receipt(amountUnits: '2500001'),
            $this->receipt(currency: 'IDR'),
            $this->receipt(decimals: 18),
            $this->receipt(target: MoneyReceiptTarget::storeBalanceTopUp('top-up-1')),
            $this->receipt(target: MoneyReceiptTarget::orderPayment('order-2')),
        ];

        foreach ($variants as $variant) {
            $this->assertNotSame($fingerprint, $variant->fingerprint());
        }
    }

    public function test_verified_receipt_fingerprint_ignores_volatile_evidence_and_added_aliases(): void
    {
        $first = $this->receipt(evidence: ['confirmations' => 3]);
        $second = $this->receipt(
            evidence: ['confirmations' => 12, 'block_number' => 44_000_000],
            aliases: [new MoneyReceiptAlias('custody_deposit', MoneyReceiptIdentity::providerReference('custody', 'account-1', 'deposit-1'))],
        );

        $this->assertSame($first->fingerprint(), $second->fingerprint());
        $this->assertSame($first->canonicalKey(), $second->canonicalKey());
    }

    public function test_verified_receipt_rejects_unsafe_money_values(): void
    {
        $cases = [
            fn () => $this->receipt(beneficiary: ''),
            fn () => $this->receipt(beneficiary: 'receiver|address'),
            fn () => $this->receipt(currency: 'usdt'),
            fn () => $this->receipt(currency: 'U'),
            fn () => $this->receipt(amountUnits: '0'),
            fn () => $this->receipt(amountUnits: '0250000'),
            fn () => $this->receipt(amountUnits: '-25'),
            fn () => $this->receipt(amountUnits: '25.5'),
            fn () => $this->receipt(amountUnits: '25e6'),
        ];

        foreach ($cases as $case) {
            try {
                $case();
                $this->fail('Expected the verified money receipt to be rejected.');
            } catch (InvalidArgumentException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function test_bsc_log_receipts_require_their_matching_parent_transaction_claim(): void
    {
        $hash = '0x'.str_repeat('ab', 32);
        $identity = MoneyReceiptIdentity::bscLog(56, $hash, 3);
        $target = MoneyReceiptTarget::orderPayment('order-1');
        $build = fn (MoneyReceiptIdentity $identity, ?MoneyReceiptParentClaim $parent) => new VerifiedMoneyReceipt(
            identity: $identity,
            beneficiary: '0x'.str_repeat('a', 40),
            currency: 'USDT',
            amountUnits: '2500000',
            decimals: 6,
            target: $target,
            parentClaim: $parent,
        );

        $cases = [
            fn () => $build($identity, null),
            fn () => $build($identity, MoneyReceiptParentClaim::for(56, '0x'.str_repeat('cd', 32))),
            fn () => $build($identity, MoneyReceiptParentClaim::for(1, $hash)),
            fn () => $build(MoneyReceiptIdentity::providerReference('binance', 'pay_id:1', 'TXN-1'), MoneyReceiptParentClaim::for(56, $hash)),
        ];

        foreach ($cases as $case) {
            try {
                $case();
                $this->fail('Expected the BSC parent transaction claim to be rejected.');
            } catch (InvalidArgumentException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
        }
    }

    private function receipt(
        string $beneficiary = '0x'.'1111111111111111111111111111111111111111',
        string $currency = 'USDT',
        string $amountUnits = '2500000',
        ?int $decimals = 6,
        ?MoneyReceiptTarget $target = null,
        array $evidence = [],
        array $aliases = [],
    ): VerifiedMoneyReceipt {
        $hash = '0x'.str_repeat('ab', 32);

        return new VerifiedMoneyReceipt(
            identity: MoneyReceiptIdentity::bscLog(56, $hash, 3),
            beneficiary: $beneficiary,
            currency: $currency,
            amountUnits: $amountUnits,
            decimals: $decimals,
            target: $target ?? MoneyReceiptTarget::orderPayment('order-1'),
            evidence: $evidence,
            aliases: $aliases,
            parentClaim: MoneyReceiptParentClaim::for(56, $hash),
        );
    }
}
