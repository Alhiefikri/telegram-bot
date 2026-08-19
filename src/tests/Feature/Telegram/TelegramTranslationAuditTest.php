<?php

namespace Tests\Feature\Telegram;

use App\Services\Telegram\TelegramTranslationCatalog;
use Illuminate\Contracts\Translation\Translator;
use Mockery;
use Tests\TestCase;

class TelegramTranslationAuditTest extends TestCase
{
    public function test_customer_translation_catalog_is_complete_for_all_supported_locales(): void
    {
        $this->assertSame([], app(TelegramTranslationCatalog::class)->audit());
    }

    public function test_customer_translation_audit_is_available_as_a_release_gate_command(): void
    {
        $this->artisan('telegram:translation-audit')
            ->expectsOutput('Telegram translation audit passed for id, en, and ms.')
            ->assertSuccessful();
    }

    public function test_customer_translation_audit_rejects_missing_referenced_keys_and_locale_values(): void
    {
        $catalog = app(TelegramTranslationCatalog::class);

        $errors = $catalog->auditCatalogs([
            'en' => ['required.key' => 'Required :value'],
            'id' => ['required.key' => 'Wajib :value'],
            'ms' => [],
        ], ['required.key', 'missing.key']);

        $this->assertContains('Referenced translation key missing.key is missing from the English catalog.', $errors);
        $this->assertContains('Missing ms translation for key required.key.', $errors);
    }

    public function test_customer_translation_audit_ignores_eloquent_relation_literals(): void
    {
        $errors = app(TelegramTranslationCatalog::class)->audit();

        $this->assertNotContains('Referenced translation key order.customer is missing from the English catalog.', $errors);
        $this->assertNotContains('Referenced translation key order.orderItems is missing from the English catalog.', $errors);
        $this->assertNotContains('Key order.orderItems is not defined in the English catalog.', $errors);
    }

    public function test_customer_translation_audit_rejects_placeholder_mismatches(): void
    {
        $errors = app(TelegramTranslationCatalog::class)->auditCatalogs([
            'en' => ['checkout.example' => 'Pay :amount for :product'],
            'id' => ['checkout.example' => 'Bayar :amount untuk :item'],
            'ms' => ['checkout.example' => 'Bayar :amount untuk :product'],
        ]);

        $this->assertContains('Placeholder mismatch for key checkout.example in id.', $errors);
    }

    public function test_catalog_uses_english_when_a_requested_locale_key_is_missing(): void
    {
        $translator = Mockery::mock(Translator::class);
        $translator->shouldReceive('get')->with('catalog.key', [], 'ms', false)->once()->andReturn('catalog.key');
        $translator->shouldReceive('get')->with('catalog.key', [], 'en', false)->once()->andReturn('English fallback');

        $this->assertSame('English fallback', (new TelegramTranslationCatalog($translator))->translate('catalog.key', 'ms'));
    }

    public function test_catalog_english_fallback_ignores_application_fallback_locale(): void
    {
        config()->set('app.fallback_locale', 'id');
        $translator = app('translator');
        $translator->setFallback('id');
        $translator->addLines(['catalog.fallback_probe' => 'Nilai Bahasa Indonesia'], 'id');
        $translator->addLines(['catalog.fallback_probe' => 'English fallback'], 'en');

        $this->assertSame(
            'English fallback',
            (new TelegramTranslationCatalog($translator))->translate('catalog.fallback_probe', 'ms'),
        );
    }

    public function test_store_channel_audit_rejects_known_indonesian_system_copy(): void
    {
        $errors = app(TelegramTranslationCatalog::class)->auditCatalogs([
            'en' => ['notification.store_channel.restock.title' => 'Stok masuk'],
            'id' => ['notification.store_channel.restock.title' => 'Stok masuk'],
            'ms' => ['notification.store_channel.restock.title' => 'Stok masuk'],
        ]);

        $this->assertContains('Indonesian Store Channel phrase Stok masuk found in en key notification.store_channel.restock.title.', $errors);
        $this->assertContains('Indonesian Store Channel phrase Stok masuk found in ms key notification.store_channel.restock.title.', $errors);
    }
}
