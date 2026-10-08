<?php

namespace Tests\Unit\Services\UsdtTopUps;

use App\Models\CustomerUsdtTopUp;
use App\Services\UsdtTopUps\UsdtTopUpStatus;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class UsdtTopUpStatusTest extends TestCase
{
    #[DataProvider('states')]
    public function test_resolved_targets_reject_lookup_while_unresolved_targets_remain_inspectable(string $state, bool $terminal, bool $settled, bool $awaiting): void
    {
        $status = UsdtTopUpStatus::for($this->target(), $state);
        $this->assertSame($terminal, $status->isTerminal());
        $this->assertSame(! $terminal, $status->acceptsCustomerLookup());
        $this->assertSame($settled, $status->isSettled());
        $this->assertSame($awaiting, $status->isAwaitingPayment());
    }

    public static function states(): iterable
    {
        yield 'preparing' => ['preparing', false, false, false];
        yield 'waiting' => ['awaiting_payment', false, false, true];
        yield 'expired but unresolved' => ['expired_unresolved', false, false, false];
        yield 'operator review' => ['manual_review', false, false, false];
        yield 'settled' => ['settled', true, true, false];
        yield 'compensated is not settled' => ['resolved_by_compensation', true, false, false];
        yield 'refund is not credit' => ['externally_refunded', true, false, false];
        yield 'rejected' => ['rejected', true, false, false];
    }

    private function target(): CustomerUsdtTopUp
    {
        return (new CustomerUsdtTopUp)->forceFill([
            'reference' => 'usdt:unit-target', 'amount_micro' => 1234567, 'fee_micro' => 10000,
            'payable_micro' => 1244567, 'created_at' => CarbonImmutable::parse('2026-10-08 08:00:00 UTC'),
            'expires_at' => CarbonImmutable::parse('2026-10-08 08:15:00 UTC'), 'payment_snapshot' => [],
        ]);
    }
}
