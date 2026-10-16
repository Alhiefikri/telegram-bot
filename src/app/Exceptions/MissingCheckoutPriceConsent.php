<?php

namespace App\Exceptions;

/**
 * The caller authorized candidate currencies but omitted the expected total
 * for the resolved primary currency, which has an eligible quote. Distinguished
 * from other invalid checkout states so the API can answer 422 without
 * charging; it is still an {@see InvalidCheckout}.
 */
final class MissingCheckoutPriceConsent extends InvalidCheckout {}
