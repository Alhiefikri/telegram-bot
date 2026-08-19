<?php

namespace Tests\Feature\Telegram;

use Tests\TestCase;

/**
 * Deletion test for issue #298: removing the old facade surface must not make
 * callers recreate a second claim/finalization or projection switchboard.
 */
final class TelegramOutboundEffectProtocolBoundaryTest extends TestCase
{
    public function test_removed_delivery_state_surface_does_not_reappear_in_callers(): void
    {
        $protocol = file_get_contents(base_path('app/Services/Telegram/TelegramOutboundEffectLedgerProtocol.php'));
        $service = file_get_contents(base_path('app/Services/Telegram/TelegramOutboundEffectService.php'));

        $this->assertIsString($protocol);
        $this->assertIsString($service);
        $this->assertStringNotContainsString('TelegramOutboundEffectService', $protocol);

        foreach ([
            'public function claim(',
            'public function finalizeSent(',
            'public function finalizeFailed(',
            'public function finalizeUnknown(',
            'public function finalizeNotDeliverable(',
            'public function processAdminOrderNotification(',
            'public function processManualPaymentProof(',
            'public function processSalesProofPost(',
            'public function processReceipt(',
            'private function synchronizeDeliveryProjection(',
            'public function syncAdminBroadcastProjection(',
            'public function syncStockNotificationProjection(',
        ] as $removedSurface) {
            $this->assertStringNotContainsString($removedSurface, $service, $removedSurface.' was reintroduced.');
        }

        foreach ([
            'CheckoutInstructionDeliveryPipeline.php',
            'ReceiptDeliveryPipeline.php',
            'AdminBroadcastDeliveryPipeline.php',
            'StockNotificationDeliveryPipeline.php',
            'ProductPriceChangeNotificationService.php',
            'AdminOrderNotificationDeliveryPipeline.php',
            'ManualPaymentProofDeliveryPipeline.php',
            'SalesProofDeliveryPipeline.php',
        ] as $adapter) {
            $source = file_get_contents(base_path('app/Services/Telegram/'.$adapter));
            $this->assertIsString($source);
            $this->assertStringNotContainsString('outboundEffects->claim(', $source, $adapter.' bypasses the protocol through the old service.');
            $this->assertStringNotContainsString('outboundEffects->finalize', $source, $adapter.' bypasses the protocol through the old service.');
            $this->assertStringNotContainsString('outboundEffects()->claim(', $source, $adapter.' bypasses the protocol through the old service.');
            $this->assertStringNotContainsString('outboundEffects()->finalize', $source, $adapter.' bypasses the protocol through the old service.');
        }
    }
}
