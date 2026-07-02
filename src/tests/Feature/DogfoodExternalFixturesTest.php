<?php

namespace Tests\Feature;

use App\Support\DogfoodExternalFixtures;
use Tests\TestCase;

class DogfoodExternalFixturesTest extends TestCase
{
    public function test_missing_fixture_report_names_required_env_without_raw_values(): void
    {
        config()->set('services.dogfood_fixtures', [
            'telegram_photo_file_id' => '',
            'store_channel_chat_id' => '',
            'store_channel_username' => '',
            'store_channel_link' => '',
            'store_channel_member_user_id' => '',
            'store_channel_non_member_user_id' => '',
            'telegram_callback_query_id' => '',
            'pakasir_pending_order_id' => '',
            'pakasir_pending_amount' => '',
        ]);
        config()->set('services.pakasir.slug', '');
        config()->set('services.pakasir.api_key', '');

        $fixtures = DogfoodExternalFixtures::fromConfig();

        $this->assertSame(
            ['DOGFOOD_TELEGRAM_PHOTO_FILE_ID'],
            $fixtures->missingFor(DogfoodExternalFixtures::MANUAL_PAYMENT_PROOF),
        );
        $this->assertSame(
            ['DOGFOOD_PAKASIR_PENDING_ORDER_ID', 'DOGFOOD_PAKASIR_PENDING_AMOUNT', 'PAKASIR_SLUG', 'PAKASIR_API_KEY'],
            $fixtures->missingFor(DogfoodExternalFixtures::PAKASIR_RECONCILIATION),
        );

        $summary = json_encode($fixtures->summary(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('DOGFOOD_', $summary);
    }

    public function test_complete_fixtures_are_redacted_and_unblock_external_scenarios(): void
    {
        config()->set('services.dogfood_fixtures', [
            'telegram_photo_file_id' => 'AgAC-real-photo-file-id',
            'store_channel_chat_id' => '-100123456789',
            'store_channel_username' => '@dogfood_channel',
            'store_channel_link' => 'https://t.me/dogfood_channel',
            'store_channel_member_user_id' => '111222333',
            'store_channel_non_member_user_id' => '444555666',
            'telegram_callback_query_id' => '1234567890123456789',
            'pakasir_pending_order_id' => 'INV-DOGFOOD-1',
            'pakasir_pending_amount' => '35000',
        ]);
        config()->set('services.pakasir.slug', 'dogfood-store');
        config()->set('services.pakasir.api_key', 'pakasir-secret');

        $fixtures = DogfoodExternalFixtures::fromConfig();

        $this->assertSame([], $fixtures->missingFor(DogfoodExternalFixtures::MANUAL_PAYMENT_PROOF));
        $this->assertSame([], $fixtures->missingFor(DogfoodExternalFixtures::STORE_CHANNEL_MEMBER_GATE));
        $this->assertSame([], $fixtures->missingFor(DogfoodExternalFixtures::PAKASIR_RECONCILIATION));
        $this->assertNull($fixtures->blockedReason(DogfoodExternalFixtures::CALLBACK_ANSWER));

        $summary = json_encode($fixtures->summary(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('AgAC-real-photo-file-id', $summary);
        $this->assertStringNotContainsString('-100123456789', $summary);
        $this->assertStringNotContainsString('111222333', $summary);
        $this->assertStringNotContainsString('pakasir-secret', $summary);
        $this->assertStringContainsString('"configured":true', $summary);
    }
}
