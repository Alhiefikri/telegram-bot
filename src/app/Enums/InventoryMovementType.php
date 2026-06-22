<?php

namespace App\Enums;

enum InventoryMovementType: string
{
    case Adjustment = 'adjustment';
    case Reservation = 'reservation';
    case Release = 'release';
    case Sale = 'sale';
}
