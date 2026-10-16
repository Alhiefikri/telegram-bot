<?php

namespace App\Services\CustomerApi;

use Illuminate\Support\Facades\DB;
use LogicException;

final readonly class CustomerApiPrincipal
{
    public function __construct(public int $customerId, public int $credentialId) {}

    /** Call inside the financial transaction, after its canonical resource locks and before any write. */
    public function revalidateForMutation(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('API mutation revalidation requires the owning transaction.');
        }
        $active = DB::table('customer_api_credentials')->where('id', $this->credentialId)
            ->where('customer_id', $this->customerId)->whereNull('revoked_at')->sharedLock()->first(['id']);
        if ($active === null) {
            throw new CustomerApiFailure(401, 'unauthenticated', 'A valid API key is required.');
        }
    }
}
