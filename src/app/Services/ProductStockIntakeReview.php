<?php

namespace App\Services;

/**
 * Credential-free projection returned by the Account Input review endpoint.
 *
 * The review is an advisory read. The final ProductStockIntake submission
 * still repeats validation and identity claims inside its transaction.
 */
final readonly class ProductStockIntakeReview
{
    /**
     * @param  list<array{
     *     index:int,
     *     account_number:int,
     *     email_masked:string,
     *     access_method:string,
     *     otp_domain:?string,
     *     duplicate_status:string,
     *     duplicate_fields:list<string>,
     *     duplicate_sources:list<string>
     * }>  $accounts
     * @param  array<string, mixed>  $receiptPreview
     */
    public function __construct(
        public string $operationKey,
        public string $status,
        public int $accountCount,
        public int $duplicateCount,
        public array $accounts,
        public array $receiptPreview,
    ) {}

    /**
     * @return array{
     *     operation_key:string,
     *     status:string,
     *     account_count:int,
     *     duplicate_count:int,
     *     accounts:list<array<string,mixed>>,
     *     receipt_preview:array<string,mixed>
     * }
     */
    public function toArray(): array
    {
        return [
            'operation_key' => $this->operationKey,
            'status' => $this->status,
            'account_count' => $this->accountCount,
            'duplicate_count' => $this->duplicateCount,
            'accounts' => $this->accounts,
            'receipt_preview' => $this->receiptPreview,
        ];
    }
}
