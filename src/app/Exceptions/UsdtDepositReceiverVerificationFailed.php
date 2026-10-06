<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * An ownership-proof challenge for a pending Customer USDT deposit receiver
 * was missing, stale, used, issued to another actor, or not satisfied by the
 * supplied signature. The receiver stays pending; the address claim stays
 * permanent.
 */
class UsdtDepositReceiverVerificationFailed extends RuntimeException {}
