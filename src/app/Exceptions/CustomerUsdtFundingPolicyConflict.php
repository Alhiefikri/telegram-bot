<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A stale Admin update lost the optimistic-version race. The policy is
 * unchanged; the caller must reload before retrying. Policy changes never
 * mutate money.
 */
final class CustomerUsdtFundingPolicyConflict extends RuntimeException {}
