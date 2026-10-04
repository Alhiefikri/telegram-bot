<?php

namespace Tests\Feature\Telegram;

use App\Enums\TelegramOutboundEffectState;
use App\Exceptions\TelegramOutboundPreSendException;
use App\Models\TelegramOutboundEffect;
use App\Services\Telegram\TelegramOutboundEffectLedgerProtocol;
use App\Services\Telegram\TelegramOutboundEffectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class TelegramOutboundEffectLedgerProtocolTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The ledger protocol owns durable state transitions. Effect-family
     * adapters own content rendering and read-projection synchronization.
     */
    public function test_claim_has_exclusive_ownership_and_sets_a_lease_token(): void
    {
        $effect = TelegramOutboundEffect::factory()->create();
        $protocol = app(TelegramOutboundEffectLedgerProtocol::class);

        $claimed = $protocol->claim($effect->id);

        $this->assertNotNull($claimed);
        $this->assertSame(TelegramOutboundEffectState::Sending, $claimed->state);
        $this->assertSame(1, $claimed->attempts);
        $this->assertNotNull($claimed->lease_until);
        $this->assertIsString($claimed->claim_token);
        $this->assertNull($protocol->claim($effect->id));
    }

    public function test_claim_token_mismatch_cannot_finalize_current_owner(): void
    {
        $effect = TelegramOutboundEffect::factory()->create();
        $protocol = app(TelegramOutboundEffectLedgerProtocol::class);
        $claimed = $protocol->claim($effect->id);

        $this->assertNull($protocol->finalizeSent($effect->id, 'not-the-owner-token', '100001'));

        $effect->refresh();
        $this->assertSame(TelegramOutboundEffectState::Sending, $effect->state);
        $this->assertSame($claimed->claim_token, $effect->claim_token);
        $this->assertNull($effect->telegram_message_id);
    }

    public function test_successful_finalization_requires_message_id_and_clears_ownership(): void
    {
        $effect = TelegramOutboundEffect::factory()->create();
        $protocol = app(TelegramOutboundEffectLedgerProtocol::class);
        $claimed = $protocol->claim($effect->id);

        $finalized = $protocol->finalizeSent($effect->id, (string) $claimed->claim_token, '100001', [
            'delivery_outcome' => 'retry_notice_sent',
        ]);

        $this->assertNotNull($finalized);
        $this->assertSame(TelegramOutboundEffectState::Sent, $finalized->state);
        $this->assertSame('100001', $finalized->telegram_message_id);
        $this->assertNull($finalized->claim_token);
        $this->assertNull($finalized->lease_until);
        $this->assertNull($finalized->next_attempt_at);
        $this->assertSame('ok', $finalized->outcome_metadata['telegram_response']);
        $this->assertSame('retry_notice_sent', $finalized->outcome_metadata['delivery_outcome']);
    }

    public function test_operator_resolution_uses_the_protocol_for_review_states(): void
    {
        $effect = TelegramOutboundEffect::factory()->create([
            'state' => TelegramOutboundEffectState::Unknown,
            'unknown_at' => now()->subMinute(),
        ]);
        $protocol = app(TelegramOutboundEffectLedgerProtocol::class);

        $resolved = $protocol->finalizeOperatorSent($effect->id, '100002', [
            'resolution' => 'operator_marked_sent',
        ]);

        $this->assertNotNull($resolved);
        $this->assertSame(TelegramOutboundEffectState::Sent, $resolved->state);
        $this->assertSame('100002', $resolved->telegram_message_id);
        $this->assertSame('operator_marked_sent', $resolved->outcome_metadata['resolution']);
        $this->assertNull($resolved->unknown_at);
        $this->assertNull($resolved->next_attempt_at);
    }

    #[DataProvider('invalidMessageIds')]
    public function test_successful_finalization_rejects_invalid_message_ids(mixed $messageId): void
    {
        $effect = TelegramOutboundEffect::factory()->create();
        $protocol = app(TelegramOutboundEffectLedgerProtocol::class);
        $claimed = $protocol->claim($effect->id);

        $this->assertNull($protocol->finalizeSent($effect->id, (string) $claimed->claim_token, (string) $messageId));
        $this->assertSame(TelegramOutboundEffectState::Sending, $effect->fresh()->state);
        $this->assertNull($effect->fresh()->telegram_message_id);
    }

    #[DataProvider('invalidMessageIds')]
    public function test_operator_resolution_rejects_invalid_message_ids(mixed $messageId): void
    {
        $effect = TelegramOutboundEffect::factory()->create([
            'state' => TelegramOutboundEffectState::Unknown,
            'unknown_at' => now()->subMinute(),
        ]);
        $protocol = app(TelegramOutboundEffectLedgerProtocol::class);

        $this->assertNull($protocol->finalizeOperatorSent($effect->id, (string) $messageId));
        $this->assertSame(TelegramOutboundEffectState::Unknown, $effect->fresh()->state);
        $this->assertNull($effect->fresh()->telegram_message_id);
    }

    public function test_definite_failure_records_retry_schedule_until_terminal(): void
    {
        $effect = TelegramOutboundEffect::factory()->create();
        $protocol = app(TelegramOutboundEffectLedgerProtocol::class);
        $claimed = $protocol->claim($effect->id);

        $failed = $protocol->finalizeFailed(
            $effect->id,
            (string) $claimed->claim_token,
            new RuntimeException('Telegram response missing message id'),
        );

        $this->assertNotNull($failed);
        $this->assertSame(TelegramOutboundEffectState::Failed, $failed->state);
        $this->assertSame('telegram_response_missing_message_id', $failed->last_error_code);
        $this->assertNotNull($failed->failed_at);
        $this->assertNotNull($failed->next_attempt_at);
        $this->assertFalse($protocol->isTerminalFailure($failed));
    }

    public function test_receipt_retries_are_bounded_to_three_definite_attempts(): void
    {
        $effect = TelegramOutboundEffect::factory()->create([
            'effect_type' => TelegramOutboundEffectService::TYPE_RECEIPT_CREDENTIALS,
        ]);
        $protocol = app(TelegramOutboundEffectLedgerProtocol::class);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $claimed = $protocol->claim((string) $effect->id);
            $this->assertNotNull($claimed);

            $failed = $protocol->finalizeFailed(
                (string) $effect->id,
                (string) $claimed->claim_token,
                new RuntimeException('Telegram response missing message id'),
            );
            $this->assertNotNull($failed);

            if ($attempt < 3) {
                $this->assertSame('telegram_response_missing_message_id', $failed->last_error_code);
                $this->assertNotNull($failed->next_attempt_at);
                $effect->fresh()->forceFill(['next_attempt_at' => now()->subSecond()])->save();
            }
        }

        $failed = $effect->fresh();
        $this->assertSame(TelegramOutboundEffectState::Failed, $failed->state);
        $this->assertSame('receipt_attempts_exhausted', $failed->last_error_code);
        $this->assertNull($failed->next_attempt_at);
        $this->assertTrue($protocol->isTerminalFailure($failed));
        $this->assertFalse($protocol->readyToDispatchQuery(now()->addDay())->whereKey($effect->id)->exists());
    }

    public function test_retryable_query_keeps_scheduled_failures_visible_without_dispatching_them_early(): void
    {
        $effect = TelegramOutboundEffect::factory()->create();
        $protocol = app(TelegramOutboundEffectLedgerProtocol::class);
        $claimed = $protocol->claim($effect->id);

        $failed = $protocol->finalizeFailed(
            $effect->id,
            (string) $claimed->claim_token,
            new RuntimeException('Telegram response missing message id'),
        );

        $this->assertNotNull($failed);
        $this->assertTrue($protocol->retryableQuery()->whereKey($effect->id)->exists());
        $this->assertFalse($protocol->readyToDispatchQuery(now())->whereKey($effect->id)->exists());
        $this->assertTrue($protocol->readyToDispatchQuery(now()->addMinutes(1))->whereKey($effect->id)->exists());
    }

    public function test_stock_family_discovery_excludes_terminal_exhausted_future_and_ambiguous_deliveries(): void
    {
        $type = TelegramOutboundEffectService::TYPE_STOCK_NOTIFICATION_RECIPIENT;
        $due = TelegramOutboundEffect::factory()->create(['effect_type' => $type, 'attempts' => 4]);
        foreach ([
            ['state' => TelegramOutboundEffectState::Failed, 'last_error_code' => 'telegram_http_403'],
            ['state' => TelegramOutboundEffectState::Failed, 'attempts' => 5],
            ['next_attempt_at' => now()->addMinute()],
            ['state' => TelegramOutboundEffectState::Unknown],
            ['effect_type' => TelegramOutboundEffectService::TYPE_RECEIPT_CREDENTIALS],
        ] as $overrides) {
            TelegramOutboundEffect::factory()->create(array_merge(['effect_type' => $type], $overrides));
        }

        $ids = app(TelegramOutboundEffectLedgerProtocol::class)
            ->readyToDispatchQuery(effectType: $type)->pluck('id')->all();

        $this->assertSame([$due->id], $ids);
    }

    public function test_non_retryable_failure_is_terminal_and_not_dispatchable(): void
    {
        $effect = TelegramOutboundEffect::factory()->create();
        $protocol = app(TelegramOutboundEffectLedgerProtocol::class);
        $claimed = $protocol->claim($effect->id);

        $failed = $protocol->finalizeFailed(
            $effect->id,
            (string) $claimed->claim_token,
            new TelegramOutboundPreSendException('Receipt is no longer renderable'),
        );

        $this->assertNotNull($failed);
        $this->assertSame(TelegramOutboundEffectState::Failed, $failed->state);
        $this->assertSame('receipt_render_changed', $failed->last_error_code);
        $this->assertNull($failed->next_attempt_at);
        $this->assertTrue($protocol->isTerminalFailure($failed));
        $this->assertFalse($protocol->isDispatchable($failed));
    }

    public function test_ambiguous_delivery_becomes_unknown_and_is_not_retried(): void
    {
        $effect = TelegramOutboundEffect::factory()->create();
        $protocol = app(TelegramOutboundEffectLedgerProtocol::class);
        $claimed = $protocol->claim($effect->id);

        $unknown = $protocol->finalizeUnknown(
            $effect->id,
            (string) $claimed->claim_token,
            new ConnectionException('connection closed after Telegram accepted the request'),
        );

        $this->assertNotNull($unknown);
        $this->assertSame(TelegramOutboundEffectState::Unknown, $unknown->state);
        $this->assertSame('telegram_transport_ambiguous', $unknown->last_error_code);
        $this->assertNull($unknown->claim_token);
        $this->assertNull($unknown->next_attempt_at);
        $this->assertFalse($protocol->readyToDispatchQuery(now()->addDay())->whereKey($effect->id)->exists());
    }

    public function test_expired_lease_becomes_ambiguous_delivery(): void
    {
        $effect = TelegramOutboundEffect::factory()->create();
        $protocol = app(TelegramOutboundEffectLedgerProtocol::class);
        $protocol->claim($effect->id);
        $effect->fresh()->forceFill(['lease_until' => now()->subSecond()])->save();

        $this->assertSame(1, $protocol->recoverExpiredLeases(now()));

        $recovered = $effect->fresh();
        $this->assertSame(TelegramOutboundEffectState::Unknown, $recovered->state);
        $this->assertSame('lease_expired', $recovered->last_error_code);
        $this->assertSame('worker_lease_expired', $recovered->outcome_metadata['reason']);
        $this->assertNull($recovered->claim_token);
    }

    public function test_state_vocabulary_and_receipt_key_group_sequence_semantics_are_persisted(): void
    {
        $this->assertSame([
            'pending',
            'sending',
            'sent',
            'failed',
            'unknown',
            'manual_review',
        ], array_map(fn (TelegramOutboundEffectState $state): string => $state->value, TelegramOutboundEffectState::cases()));

        $groupKey = 'order:receipt-protocol:receipt.credentials:v1';
        $first = TelegramOutboundEffect::factory()->create([
            'effect_key' => $groupKey.':unit:1',
            'effect_group_key' => $groupKey,
            'sequence' => 1,
            'effect_type' => TelegramOutboundEffectService::TYPE_RECEIPT_CREDENTIALS,
        ]);
        $second = TelegramOutboundEffect::factory()->create([
            'effect_key' => $groupKey.':unit:2',
            'effect_group_key' => $groupKey,
            'sequence' => 2,
            'effect_type' => TelegramOutboundEffectService::TYPE_RECEIPT_CREDENTIALS,
        ]);
        $protocol = app(TelegramOutboundEffectLedgerProtocol::class);

        $this->assertSame($first->id, $protocol->claim($first->id)?->id);
        $this->assertNull($protocol->claim($second->id));

        $claimedFirst = $first->fresh();
        $protocol->finalizeSent($first->id, (string) $claimedFirst->claim_token, '100001');

        $this->assertSame($second->id, $protocol->claim($second->id)?->id);
    }

    public function test_receipt_successor_seam_preserves_legacy_exception_and_strict_versions(): void
    {
        $protocol = app(TelegramOutboundEffectLedgerProtocol::class);
        $legacyGroup = 'order:receipt-successor-legacy:receipt.credentials:v1';
        $legacyInvoice = TelegramOutboundEffect::factory()->create([
            'effect_key' => $legacyGroup.':unit:1',
            'effect_group_key' => $legacyGroup,
            'sequence' => 1,
            'effect_type' => TelegramOutboundEffectService::TYPE_RECEIPT_CREDENTIALS,
            'telegram_method' => 'sendPhoto',
            'template_version' => TelegramOutboundEffectService::TEMPLATE_RECEIPT_INVOICE,
            'state' => TelegramOutboundEffectState::Failed,
            'last_error_code' => 'telegram_http_500',
            'next_attempt_at' => now()->addMinute(),
        ]);
        $legacyDetail = TelegramOutboundEffect::factory()->create([
            'effect_key' => $legacyGroup.':unit:2',
            'effect_group_key' => $legacyGroup,
            'sequence' => 2,
            'effect_type' => TelegramOutboundEffectService::TYPE_RECEIPT_CREDENTIALS,
        ]);

        $this->assertSame(
            $legacyDetail->id,
            $protocol->nextReceiptEffect($legacyGroup)?->id,
        );
        $this->assertSame(
            $legacyDetail->id,
            $protocol->nextReceiptEffect($legacyGroup, (int) $legacyInvoice->sequence)?->id,
        );

        $strictGroup = 'order:receipt-successor-strict:receipt.credentials:v2';
        $strictPhoto = TelegramOutboundEffect::factory()->create([
            'effect_key' => $strictGroup.':unit:1',
            'effect_group_key' => $strictGroup,
            'sequence' => 1,
            'effect_type' => TelegramOutboundEffectService::TYPE_RECEIPT_CREDENTIALS,
            'telegram_method' => 'sendPhoto',
            'template_version' => TelegramOutboundEffectService::TEMPLATE_RECEIPT_PHOTO_CAPTION,
            'state' => TelegramOutboundEffectState::Unknown,
            'unknown_at' => now()->subMinute(),
            'next_attempt_at' => null,
        ]);
        $strictContinuation = TelegramOutboundEffect::factory()->create([
            'effect_key' => $strictGroup.':unit:2',
            'effect_group_key' => $strictGroup,
            'sequence' => 2,
            'effect_type' => TelegramOutboundEffectService::TYPE_RECEIPT_CREDENTIALS,
        ]);

        $this->assertNull($protocol->nextReceiptEffect($strictGroup));

        $strictPhoto->forceFill([
            'state' => TelegramOutboundEffectState::Sent,
            'telegram_message_id' => '393001',
            'sent_at' => now(),
        ])->save();

        $this->assertSame(
            $strictContinuation->id,
            $protocol->nextReceiptEffect($strictGroup)?->id,
        );
    }

    public function test_receipt_completion_seam_preserves_legacy_exception_and_strict_versions(): void
    {
        $protocol = app(TelegramOutboundEffectLedgerProtocol::class);

        $legacyGroup = 'order:receipt-completion-legacy:receipt.credentials:v1';
        $legacyInvoice = TelegramOutboundEffect::factory()->create([
            'effect_key' => $legacyGroup.':unit:1',
            'effect_group_key' => $legacyGroup,
            'sequence' => 1,
            'effect_type' => TelegramOutboundEffectService::TYPE_RECEIPT_CREDENTIALS,
            'telegram_method' => 'sendPhoto',
            'template_version' => TelegramOutboundEffectService::TEMPLATE_RECEIPT_INVOICE,
            'state' => TelegramOutboundEffectState::Unknown,
            'unknown_at' => now()->subMinute(),
        ]);
        TelegramOutboundEffect::factory()->create([
            'effect_key' => $legacyGroup.':unit:2',
            'effect_group_key' => $legacyGroup,
            'sequence' => 2,
            'effect_type' => TelegramOutboundEffectService::TYPE_RECEIPT_CREDENTIALS,
            'state' => TelegramOutboundEffectState::Sent,
            'telegram_message_id' => '394001',
            'sent_at' => now()->subMinute(),
        ]);

        $this->assertTrue($protocol->isReceiptGroupComplete($legacyGroup));

        $legacyInvoice->forceFill([
            'state' => TelegramOutboundEffectState::ManualReview,
            'unknown_at' => null,
            'manual_review_at' => now(),
        ])->save();
        $this->assertFalse($protocol->isReceiptGroupComplete($legacyGroup));

        $strictGroup = 'order:receipt-completion-strict:receipt.credentials:v2';
        $strictPhoto = TelegramOutboundEffect::factory()->create([
            'effect_key' => $strictGroup.':unit:1',
            'effect_group_key' => $strictGroup,
            'sequence' => 1,
            'effect_type' => TelegramOutboundEffectService::TYPE_RECEIPT_CREDENTIALS,
            'telegram_method' => 'sendPhoto',
            'template_version' => TelegramOutboundEffectService::TEMPLATE_RECEIPT_PHOTO_CAPTION,
            'state' => TelegramOutboundEffectState::Unknown,
            'unknown_at' => now()->subMinute(),
        ]);
        $strictContinuation = TelegramOutboundEffect::factory()->create([
            'effect_key' => $strictGroup.':unit:2',
            'effect_group_key' => $strictGroup,
            'sequence' => 2,
            'effect_type' => TelegramOutboundEffectService::TYPE_RECEIPT_CREDENTIALS,
            'state' => TelegramOutboundEffectState::Sent,
            'telegram_message_id' => '394002',
            'sent_at' => now()->subMinute(),
        ]);

        $this->assertFalse($protocol->isReceiptGroupComplete($strictGroup));

        $strictPhoto->forceFill([
            'state' => TelegramOutboundEffectState::Sent,
            'telegram_message_id' => '394003',
            'sent_at' => now(),
            'unknown_at' => null,
        ])->save();
        $this->assertTrue($protocol->isReceiptGroupComplete($strictGroup));

        $strictContinuation->forceFill([
            'state' => TelegramOutboundEffectState::Pending,
            'telegram_message_id' => null,
            'sent_at' => null,
        ])->save();
        $this->assertFalse($protocol->isReceiptGroupComplete($strictGroup));

        $explicitGroup = 'order:receipt-completion-explicit:receipt.credentials:v2:resend:v2';
        TelegramOutboundEffect::factory()->create([
            'effect_key' => $explicitGroup.':unit:1',
            'effect_group_key' => $explicitGroup,
            'sequence' => 1,
            'effect_type' => TelegramOutboundEffectService::TYPE_RECEIPT_CREDENTIALS,
            'telegram_method' => 'sendPhoto',
            'template_version' => TelegramOutboundEffectService::TEMPLATE_RECEIPT_PHOTO_CAPTION,
            'state' => TelegramOutboundEffectState::Sent,
            'telegram_message_id' => '394004',
            'sent_at' => now()->subMinute(),
        ]);
        TelegramOutboundEffect::factory()->create([
            'effect_key' => $explicitGroup.':unit:2',
            'effect_group_key' => $explicitGroup,
            'sequence' => 2,
            'effect_type' => TelegramOutboundEffectService::TYPE_RECEIPT_CREDENTIALS,
            'state' => TelegramOutboundEffectState::Sent,
            'telegram_message_id' => '394005',
            'sent_at' => now()->subMinute(),
        ]);

        $this->assertTrue($protocol->isReceiptGroupComplete($explicitGroup));
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidMessageIds(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'fractional' => [1.5];
        yield 'empty' => [''];
        yield 'non numeric' => ['not-a-message-id'];
    }
}
