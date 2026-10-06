<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The Customer already holds one non-retired USDT deposit receiver. Rotation
 * retires the current receiver before a new exclusive address is provisioned.
 */
class UsdtDepositReceiverAlreadyActive extends RuntimeException {}
