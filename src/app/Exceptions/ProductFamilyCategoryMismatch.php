<?php

namespace App\Exceptions;

use RuntimeException;

final class ProductFamilyCategoryMismatch extends RuntimeException
{
    public function __construct(string $message)
    {
        parent::__construct($message);
    }
}
