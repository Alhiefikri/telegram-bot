<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The normalized (chain, address) pair is already owned by another receiver
 * channel or another Customer binding. Addresses are never shared, reassigned
 * or recycled, including addresses whose receiver row was later deleted.
 */
class UsdtReceiverAddressConflict extends RuntimeException {}
