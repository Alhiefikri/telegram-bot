<?php

namespace App\Observers;

use App\Models\Product;
use App\Services\Audit\AuditContext;
use App\Services\AuditLogger;

class ProductAuditObserver
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly AuditContext $auditContext,
    ) {}

    public function deleted(Product $product): void
    {
        $this->auditLogger->logWithResolvedContext('product.deleted', $product, $product->getOriginal(), null, $this->auditContext->user(), $this->auditContext->ip());
    }

    public function restored(Product $product): void
    {
        $this->auditLogger->logWithResolvedContext('product.restored', $product, null, $product->only(['id', 'name']), $this->auditContext->user(), $this->auditContext->ip());
    }
}
