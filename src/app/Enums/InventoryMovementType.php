<?php

namespace App\Enums;

enum InventoryMovementType: string
{
    case Adjustment = 'adjustment';
    case Availability = 'availability';
    case Reservation = 'reservation';
    case Release = 'release';
    case Sale = 'sale';
}
