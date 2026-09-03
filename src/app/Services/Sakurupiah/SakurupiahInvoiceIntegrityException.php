<?php

namespace App\Services\Sakurupiah;

use RuntimeException;
use Throwable;

final class SakurupiahInvoiceIntegrityException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message = 'Sakurupiah invoice data failed the local integrity checks.',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
