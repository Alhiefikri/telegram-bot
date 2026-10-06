<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A Customer USDT deposit receiver identity, its permanent Customer binding,
 * or a permanent receiver address claim was mutated after it was established.
 * Retire the receiver and provision a new address instead.
 */
class UsdtReceiverBindingImmutable extends RuntimeException {}
