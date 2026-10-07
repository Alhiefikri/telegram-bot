<?php

namespace App\Services\Suppliers;

use Carbon\CarbonImmutable;

/** @phpstan-type SupplierHistoryOrder array{order_id:string,product_id:int,quantity:int,total_micros:int,status:string,created_at:CarbonImmutable} */
final readonly class SupplierOrderHistorySnapshot
{
    /** @param list<array{order_id:string,product_id:int,quantity:int,total_micros:int,status:string,created_at:CarbonImmutable}> $orders */
    public function __construct(
        public int $limit,
        public int $reportedCount,
        public array $orders,
        public string $timezone,
        public string $responseSha256,
    ) {}
}
