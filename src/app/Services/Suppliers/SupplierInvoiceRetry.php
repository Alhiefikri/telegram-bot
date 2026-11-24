<?php

namespace App\Services\Suppliers;

use App\Models\User;

final class SupplierInvoiceRetry
{
    public function __construct(private readonly SupplierFulfillmentService $fulfillment) {}

    public function attempt(int $fulfillmentId, bool $forceSync = false, ?int $approvedCostIdr = null, ?int $expectedPurchaseId = null, ?int $reviewId = null, ?User $owner = null): array
    {
        // A caller-supplied number is never authority to exceed the order ceiling.
        return $this->fulfillment->retryInvoice($fulfillmentId, $forceSync, $expectedPurchaseId, $reviewId, $owner);
    }

    public function releaseProvenRejected(int $fulfillmentId): array
    {
        return $this->fulfillment->releaseProvenRejectedHold($fulfillmentId);
    }

    public function preview(int $fulfillmentId, User $owner, ?int $expectedPurchaseId = null, ?int $reviewId = null): array
    {
        return $this->fulfillment->previewInvoiceRetry($fulfillmentId, $owner, $expectedPurchaseId, $reviewId);
    }

    /** Caller must also enforce a fresh owner password confirmation. */
    public function confirm(int $fulfillmentId, User $owner, string $token, ?int $reviewId = null): array
    {
        return $this->fulfillment->confirmInvoiceRetry($fulfillmentId, $owner, $token, $reviewId);
    }
}
