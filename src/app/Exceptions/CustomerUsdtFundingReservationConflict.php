<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A funding reservation replay or resolution did not match the immutable
 * reservation identity, or tried to resolve a reservation twice with a
 * different economic resolution. No money and no exposure state change.
 */
final class CustomerUsdtFundingReservationConflict extends RuntimeException {}
