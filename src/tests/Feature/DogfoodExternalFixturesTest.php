<?php

namespace Tests\Feature;

use App\Support\DogfoodExternalFixtures;
use Tests\TestCase;

class DogfoodExternalFixturesTest extends TestCase
{
    public function test_missing_fixture_report_names_required_env_without_raw_values(): void
    {
        config()->set('services.dogfood_fixtures', [
            'admin_email' => '',
            'admin_password' => '',
            'telegram_photo_file_id' => '',
            'store_channel_chat_id' => '',
            'store_channel_username' => '',
            'store_channel_link' => '',
            'store_channel_member_user_id' => '',
            'store_channel_non_member_user_id' => '',
            'telegram_callback_query_id' => '',
        ]);

        $fixtures = DogfoodExternalFixtures::fromConfig();

        $this->assertSame(
            ['DOGFOOD_TELEGRAM_PHOTO_FILE_ID'],
            $fixtures->missingFor(DogfoodExternalFixtures::MANUAL_PAYMENT_PROOF),
        );
        $this->assertSame(
            [
                'DOGFOOD_STORE_CHANNEL_CHAT_ID',
                'DOGFOOD_STORE_CHANNEL_USERNAME',
                'DOGFOOD_STORE_CHANNEL_LINK',
                'DOGFOOD_STORE_CHANNEL_MEMBER_USER_ID',
                'DOGFOOD_STORE_CHANNEL_NON_MEMBER_USER_ID',
            ],
            $fixtures->missingFor(DogfoodExternalFixtures::STORE_CHANNEL_MEMBER_GATE),
        );
        $this->assertSame(
            ['DOGFOOD_ADMIN_EMAIL', 'DOGFOOD_ADMIN_PASSWORD'],
            $fixtures->missingFor(DogfoodExternalFixtures::ADMIN_AUTHENTICATED_SESSION),
        );

        $summary = json_encode($fixtures->summary(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('DOGFOOD_', $summary);
    }

    public function test_complete_fixtures_are_redacted_and_unblock_external_scenarios(): void
    {
        config()->set('services.dogfood_fixtures', [
            'admin_email' => 'owner@example.test',
            'admin_password' => 'secret-password',
            'telegram_photo_file_id' => 'AgAC-real-photo-file-id',
            'store_channel_chat_id' => '-100123456789',
            'store_channel_username' => '@dogfood_channel',
            'store_channel_link' => 'https://t.me/dogfood_channel',
            'store_channel_member_user_id' => '111222333',
            'store_channel_non_member_user_id' => '444555666',
            'telegram_callback_query_id' => '1234567890123456789',
        ]);

        $fixtures = DogfoodExternalFixtures::fromConfig();

        $this->assertSame([], $fixtures->missingFor(DogfoodExternalFixtures::MANUAL_PAYMENT_PROOF));
        $this->assertSame([], $fixtures->missingFor(DogfoodExternalFixtures::STORE_CHANNEL_MEMBER_GATE));
        $this->assertSame([], $fixtures->missingFor(DogfoodExternalFixtures::ADMIN_AUTHENTICATED_SESSION));
        $this->assertSame('owner@example.test', $fixtures->adminEmail());
        $this->assertSame('secret-password', $fixtures->adminPassword());
        $this->assertNull($fixtures->blockedReason(DogfoodExternalFixtures::CALLBACK_ANSWER));

        $summary = json_encode($fixtures->summary(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('owner@example.test', $summary);
        $this->assertStringNotContainsString('secret-password', $summary);
        $this->assertStringNotContainsString('AgAC-real-photo-file-id', $summary);
        $this->assertStringNotContainsString('-100123456789', $summary);
        $this->assertStringNotContainsString('111222333', $summary);
        $this->assertStringContainsString('"configured":true', $summary);
    }
}
