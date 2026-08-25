<?php

namespace App\Services\Checkout;

use App\Exceptions\InvalidCheckout;
use InvalidArgumentException;

/**
 * The result of preparation preflight is intentionally opaque to checkout.
 * Only the adapter that produced it may retrieve its typed state.
 */
final readonly class CheckoutPaymentPreflight
{
    public function __construct(
        public string $provider,
        public string $method,
        public string $fingerprint,
        private mixed $state = null,
    ) {
        if (trim($provider) === '' || trim($method) === '') {
            throw new InvalidArgumentException('A checkout payment preflight requires a provider and method.');
        }

        if (trim($fingerprint) === '') {
            throw new InvalidArgumentException('A checkout payment preflight requires an identity fingerprint.');
        }

        if ($state !== null && ! is_object($state)) {
            throw new InvalidArgumentException('Checkout payment preflight state must be an object.');
        }
    }

    public static function none(CheckoutPaymentPreflightContext $context): self
    {
        return new self(
            provider: $context->requestedProvider,
            method: $context->requestedMethod,
            fingerprint: $context->fingerprint(),
        );
    }

    public static function withState(
        CheckoutPaymentPreflightContext $context,
        object $state,
    ): self {
        return new self(
            provider: $context->requestedProvider,
            method: $context->requestedMethod,
            fingerprint: $context->fingerprint(),
            state: $state,
        );
    }

    public function identity(): string
    {
        return CheckoutPaymentPreparationIdentity::key($this->provider, $this->method);
    }

    public function matches(CheckoutPaymentPreflightContext $context): bool
    {
        return $this->identity() === $context->identity()
            && hash_equals($this->fingerprint, $context->fingerprint());
    }

    public function assertMatches(CheckoutPaymentPreflightContext $context): void
    {
        if (! $this->matches($context)) {
            throw new InvalidCheckout('Checkout payment preflight is stale.');
        }
    }

    public function hasState(): bool
    {
        return $this->state !== null;
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $type
     * @return T
     */
    public function stateAs(string $type): object
    {
        if (! $this->state instanceof $type) {
            throw new InvalidCheckout('Checkout payment preflight does not contain the expected adapter state.');
        }

        return $this->state;
    }
}
