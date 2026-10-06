<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * No approved custody provisioning adapter can supply an exclusive public
 * receiver, or its address capacity is exhausted. The rail fails closed: it
 * never falls back to a shared address with a unique amount workaround.
 */
class UsdtReceiverCapacityUnavailable extends RuntimeException {}
