<?php

namespace Tests\Feature;

use App\Enums\TelegramOutboundEffectState;
use App\Services\Telegram\TelegramOutboundEffectDispatcher;
use App\Services\Telegram\TelegramOutboundEffectService;
use App\Services\Warranty\RefundProof;
use App\Services\Warranty\WarrantyClaimService;
use App\Services\Warranty\WarrantyNotificationPipeline;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsBuyMailWarrantyFixture;
use Tests\Support\NonTransactionalDatabaseTestCase;

final class WarrantyNotificationTest extends NonTransactionalDatabaseTestCase
{
    use BuildsBuyMailWarrantyFixture;

    public function test_claim_decision_notifies_buyer_once_in_english_with_private_support_access(): void
    {
        Queue::fake();
        Storage::fake('local');
        [$customer, $unit] = $this->fixture();
        $customer->update(['locale' => 'en', 'telegram_id' => 790001]);
        $claim = app(WarrantyClaimService::class)->submit($customer, $unit->public_id, RefundProof::fromBytes(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAC0lEQVQImWNgQAYAAA4AAbGa6gYAAAAASUVORK5CYII=')), 'Wrong password');
        $claim->update(['state' => 'approved', 'verified_at' => now(), 'settlement_state' => 'awaiting_choice']);
        $effects = app(TelegramOutboundEffectService::class);
        $effect = $effects->recordWarrantyNotification($claim);
        $this->assertSame($effect->id, $effects->recordWarrantyNotification($claim)->id);
        Http::fake(function ($request) {
            $this->assertStringContainsString('Your claim is verified', $request['text']);
            $this->assertStringNotContainsString('PR1', $request['text']);
            $this->assertStringNotContainsString('synthetic-password', $request['text']);

            return Http::response(['ok' => true, 'result' => ['message_id' => 12345]]);
        });
        app(WarrantyNotificationPipeline::class)->deliver($effect);
        app(WarrantyNotificationPipeline::class)->deliver($effect);
        Http::assertSentCount(1);
        $this->assertSame(TelegramOutboundEffectState::Sent, $effect->fresh()->state);
        app(TelegramOutboundEffectDispatcher::class)->dispatch($effect->fresh());
    }

    public function test_ambiguous_telegram_notice_is_not_automatically_sent_again(): void
    {
        Queue::fake();
        Storage::fake('local');
        [$customer, $unit] = $this->fixture();
        $customer->update(['locale' => 'en', 'telegram_id' => 790001]);
        $claim = app(WarrantyClaimService::class)->submit($customer, $unit->public_id, RefundProof::fromBytes(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAC0lEQVQImWNgQAYAAA4AAbGa6gYAAAAASUVORK5CYII=')), 'Wrong password');
        $effect = app(TelegramOutboundEffectService::class)->recordWarrantyNotification($claim);
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            throw new ConnectionException('Synthetic timeout.');
        });
        app(WarrantyNotificationPipeline::class)->deliver($effect);
        app(WarrantyNotificationPipeline::class)->deliver($effect);
        $this->assertSame(1, $attempts);
        $this->assertSame(TelegramOutboundEffectState::Unknown, $effect->fresh()->state);
        $this->assertSame('received', $claim->fresh()->state);
        $this->assertSame('awaiting_verification', $claim->fresh()->settlement_state);
    }

    public function test_queued_old_status_is_skipped_when_a_newer_decision_is_available(): void
    {
        Queue::fake();
        Storage::fake('local');
        [$customer, $unit] = $this->fixture();
        $customer->update(['locale' => 'en', 'telegram_id' => 790001]);
        $claim = app(WarrantyClaimService::class)->submit($customer, $unit->public_id, RefundProof::fromBytes(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAC0lEQVQImWNgQAYAAA4AAbGa6gYAAAAASUVORK5CYII=')), 'Wrong password');
        $old = app(TelegramOutboundEffectService::class)->recordWarrantyNotification($claim);
        $claim->update(['state' => 'approved', 'verified_at' => now(), 'settlement_state' => 'awaiting_choice']);
        $latest = app(TelegramOutboundEffectService::class)->recordWarrantyNotification($claim);
        Http::fake(fn () => Http::response(['ok' => true, 'result' => ['message_id' => 12345]]));
        $pipeline = app(WarrantyNotificationPipeline::class);
        $pipeline->deliver($old);
        Http::assertNothingSent();
        $pipeline->deliver($latest);
        Http::assertSentCount(1);
        $this->assertSame(TelegramOutboundEffectState::Failed, $old->fresh()->state);
        $this->assertSame(TelegramOutboundEffectState::Sent, $latest->fresh()->state);
    }
}
