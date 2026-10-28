<?php

namespace Tests\Unit\Services\Sakurupiah;

use App\Services\Sakurupiah\SakurupiahCreatePreRequestException;
use App\Services\Sakurupiah\SakurupiahProviderException;
use App\Services\Sakurupiah\SakurupiahRequestBackpressure;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SakurupiahRequestBackpressureTest extends TestCase
{
    public function test_waiting_checkout_gets_a_retry_window_before_the_sweep_can_reacquire(): void
    {
        $policy = new SakurupiahRequestBackpressure;
        $background = $policy->acquireRequest('transactionStatus', true, 'reconciliation_command');
        try {
            $policy->acquireRequest('createInvoice', false, 'telegram_checkout');
            $this->fail('A concurrent request must not overlap the sweep.');
        } catch (SakurupiahCreatePreRequestException $exception) {
            $this->assertSame('lookup_temporarily_unavailable', $exception->reason);
            $this->assertFalse($policy->isCoolingDown());
        } finally {
            $background->release();
        }

        try {
            $lock = $policy->acquireRequest('transactionStatus', true, 'reconciliation_command');
            $lock->release();
            $this->fail('The sweep must yield to the waiting checkout.');
        } catch (SakurupiahProviderException $exception) {
            $this->assertSame('lookup_temporarily_unavailable', $exception->reason);
        }
        $foreground = $policy->acquireRequest('createInvoice', false, 'telegram_checkout');
        $foreground->release();
        $this->travel(3)->seconds();
        $resumed = $policy->acquireRequest('transactionStatus', true, 'reconciliation_command');
        $resumed->release();
    }

    #[DataProvider('retryHeaders')]
    public function test_throttle_deadline_respects_retry_after_and_recovers_at_exact_boundary(?string $header, int $seconds): void
    {
        $this->travelTo('2026-10-28 12:00:00 UTC');
        $policy = new SakurupiahRequestBackpressure;
        $policy->observe(new Response(new PsrResponse(429, $header === null ? [] : ['Retry-After' => $header])));
        $this->assertSame(now()->timestamp + $seconds, $policy->retryAt());
        $this->travel($seconds - 1)->seconds();
        $this->assertTrue((new SakurupiahRequestBackpressure)->isCoolingDown());
        $this->travel(1)->seconds();
        $this->assertFalse((new SakurupiahRequestBackpressure)->isCoolingDown());
        $this->assertFalse($policy->isCoolingDown());
        $this->travel(1)->seconds();
        $policy->assertAvailable('listChannels', true);
        $this->assertNull($policy->retryAt());
    }

    public static function retryHeaders(): array
    {
        return ['absent' => [null, 60], 'malformed' => ['tomorrow', 60], 'negative' => ['-3', 60],
            'shorter' => ['2', 60], 'longer' => ['120', 120],
            'date' => ['Wed, 28 Oct 2026 12:02:00 GMT', 120], 'overflow' => [str_repeat('9', 30), 60]];
    }

    #[DataProvider('throttleResponses')]
    public function test_provider_throttle_is_not_confused_with_permanent_rejection(int $http, array $body, bool $throttled): void
    {
        $policy = new SakurupiahRequestBackpressure;
        $policy->observe(new Response(new PsrResponse($http, [], json_encode($body, JSON_THROW_ON_ERROR))));
        $this->assertSame($throttled, $policy->isCoolingDown());
    }

    public static function throttleResponses(): array
    {
        return ['http429' => [429, [], true],
            'http400' => [400, ['status' => 400, 'message' => 'Permintaan terlalu cepat silahkan coba lagi nanti.'], true],
            'application400' => [200, ['status' => '400', 'message' => 'Permintaan terlalu cepat silahkan coba lagi nanti.'], true],
            'application429' => [200, ['status' => '429'], true],
            'permanent400' => [400, ['status' => '400', 'message' => 'Merchant tidak aktif'], false],
            'transport503' => [503, [], false]];
    }

    public function test_shorter_observation_cannot_shorten_shared_merchant_cooldown(): void
    {
        $long = new SakurupiahRequestBackpressure;
        $long->observe(new Response(new PsrResponse(429, ['Retry-After' => '120'])));
        $expected = $long->retryAt();
        (new SakurupiahRequestBackpressure)->observe(new Response(new PsrResponse(429)));
        $this->assertSame($expected, (new SakurupiahRequestBackpressure)->retryAt());
    }

    public function test_unavailable_cache_keeps_create_undispatched_and_distinct_from_throttle(): void
    {
        Cache::shouldReceive('get')->andThrow(new \RuntimeException('down'));
        try {
            (new SakurupiahRequestBackpressure)->assertAvailable('createInvoice', false);
            $this->fail('Must not dispatch without merchant coordination.');
        } catch (SakurupiahCreatePreRequestException $exception) {
            $this->assertSame('cache_unavailable', $exception->reason);
        }
        try {
            (new SakurupiahRequestBackpressure)->assertAvailable('listChannels', true);
            $this->fail('Must not dispatch lookup without merchant coordination.');
        } catch (SakurupiahProviderException $exception) {
            $this->assertSame('cache_unavailable', $exception->reason);
            $this->assertFalse($exception->isOutcomeUnknown());
        }
    }
}
