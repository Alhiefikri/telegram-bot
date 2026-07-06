<?php

namespace App\Storefront;

enum StorefrontShopSignedInvoiceState
{
    case Pending;
    case Paid;
    case Terminal;
}
