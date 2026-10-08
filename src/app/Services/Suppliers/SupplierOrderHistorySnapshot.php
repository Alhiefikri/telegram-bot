<?php

namespace App\Services\Suppliers;

use Carbon\CarbonImmutable;

/** Private allowlisted history facts; an unqualified timestamp is never an instant. */
final readonly class SupplierOrderHistorySnapshot
{
    /** @param list<array{order_id:string,product_id:int|string,quantity:int,total_micros:int,status:string,created_at:?CarbonImmutable,created_at_raw?:string,currency?:string,timezone_qualification?:string}> $orders */
    public function __construct(
        public int $limit,
        public int $reportedCount,
        public array $orders,
        public string $timezone,
        public string $responseSha256,
        public string $coverageKind = 'qualified_window',
    ) {}
}
