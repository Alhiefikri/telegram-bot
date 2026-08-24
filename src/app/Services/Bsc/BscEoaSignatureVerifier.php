<?php

namespace App\Services\Bsc;

use Brick\Math\BigInteger;
use kornrunner\Keccak;
use Throwable;

final class BscEoaSignatureVerifier
{
    private const FIELD_PRIME = 'FFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFEFFFFFC2F';

    private const CURVE_ORDER = 'FFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFEBAAEDCE6AF48A03BBFD25E8CD0364141';

    private const GENERATOR_X = '79BE667EF9DCBBAC55A06295CE870B07029BFCDB2DCE28D959F2815B16F81798';

    private const GENERATOR_Y = '483ADA7726A3C4655DA4FBFC0E1108A8FD17B448A68554199C47D08FFB10D4B8';

    /**
     * Recover the signer address from a strict 65-byte personal-sign value.
     *
     * The application deliberately accepts only canonical v=27/28 (or 0/1)
     * signatures. EIP-155 transaction recovery values are not valid for an
     * EIP-191 ownership message.
     */
    public function recoverAddress(string $message, string $signature): ?string
    {
        try {
            if (preg_match('/^0x[0-9a-fA-F]{130}$/', $signature) !== 1) {
                return null;
            }

            $bytes = hex2bin(substr($signature, 2));
            if ($bytes === false || strlen($bytes) !== 65) {
                return null;
            }

            $recovery = ord($bytes[64]);
            $recovery = match (true) {
                $recovery === 0 || $recovery === 1 => $recovery,
                $recovery === 27 || $recovery === 28 => $recovery - 27,
                default => -1,
            };
            if ($recovery < 0) {
                return null;
            }

            $r = BigInteger::fromBase(bin2hex(substr($bytes, 0, 32)), 16);
            $s = BigInteger::fromBase(bin2hex(substr($bytes, 32, 32)), 16);
            $order = $this->order();
            if ($r->isNegativeOrZero() || $r->compareTo($order) >= 0 || $s->isNegativeOrZero() || $s->compareTo($order) >= 0) {
                return null;
            }
            if ($s->compareTo($order->quotient(2)) > 0) {
                return null;
            }

            $field = $this->field();
            $x = $r;
            if ($x->compareTo($field) >= 0) {
                return null;
            }

            $alpha = $this->mod($x->multipliedBy($x)->multipliedBy($x)->plus(7), $field);
            $beta = $alpha->modPow($field->plus(1)->dividedBy(4), $field);
            if (! $this->mod($beta->multipliedBy($beta)->minus($alpha), $field)->isZero()) {
                return null;
            }

            $y = $beta->mod(2)->isEqualTo($recovery) ? $beta : $this->mod($field->minus($beta), $field);
            $point = ['x' => $x, 'y' => $y];
            if (! $this->isOnCurve($point)) {
                return null;
            }

            $hash = BigInteger::fromBase(bin2hex(BscEip191::messageHash($message)), 16)->mod($order);
            $sR = $this->multiply($point, $s);
            $eG = $this->multiply($this->generator(), $hash);
            $numerator = $this->add($sR, $this->negate($eG));
            if ($numerator === null) {
                return null;
            }

            $publicKey = $this->multiply($numerator, $r->modInverse($order));
            if ($publicKey === null || ! $this->isOnCurve($publicKey)) {
                return null;
            }

            $publicKeyHex = $this->hex($publicKey['x']).$this->hex($publicKey['y']);
            $address = substr(Keccak::hash(hex2bin($publicKeyHex), 256), -40);

            return '0x'.strtolower($address);
        } catch (Throwable) {
            return null;
        }
    }

    public function matchesAddress(string $message, string $signature, string $expectedAddress): bool
    {
        $recovered = $this->recoverAddress($message, $signature);
        if ($recovered === null) {
            return false;
        }

        return hash_equals(
            strtolower(trim($expectedAddress)),
            strtolower($recovered),
        );
    }

    /** @return array{x: BigInteger, y: BigInteger} */
    private function generator(): array
    {
        return [
            'x' => BigInteger::fromBase(self::GENERATOR_X, 16),
            'y' => BigInteger::fromBase(self::GENERATOR_Y, 16),
        ];
    }

    private function field(): BigInteger
    {
        return BigInteger::fromBase(self::FIELD_PRIME, 16);
    }

    private function order(): BigInteger
    {
        return BigInteger::fromBase(self::CURVE_ORDER, 16);
    }

    /** @param array{x: BigInteger, y: BigInteger}|null $left */
    /** @param array{x: BigInteger, y: BigInteger}|null $right */
    /** @return array{x: BigInteger, y: BigInteger}|null */
    private function add(?array $left, ?array $right): ?array
    {
        if ($left === null) {
            return $right;
        }
        if ($right === null) {
            return $left;
        }

        $field = $this->field();
        if ($left['x']->isEqualTo($right['x'])) {
            if ($this->mod($left['y']->plus($right['y']), $field)->isZero()) {
                return null;
            }

            $numerator = $left['x']->multipliedBy($left['x'])->multipliedBy(3);
            $denominator = $left['y']->multipliedBy(2)->modInverse($field);
        } else {
            $numerator = $right['y']->minus($left['y']);
            $denominator = $right['x']->minus($left['x'])->modInverse($field);
        }

        $lambda = $this->mod($numerator->multipliedBy($denominator), $field);
        $x = $this->mod($lambda->multipliedBy($lambda)->minus($left['x'])->minus($right['x']), $field);
        $y = $this->mod($lambda->multipliedBy($left['x']->minus($x))->minus($left['y']), $field);

        return ['x' => $x, 'y' => $y];
    }

    /** @param array{x: BigInteger, y: BigInteger}|null $point */
    /** @return array{x: BigInteger, y: BigInteger}|null */
    private function negate(?array $point): ?array
    {
        if ($point === null) {
            return null;
        }

        return ['x' => $point['x'], 'y' => $this->mod($this->field()->minus($point['y']), $this->field())];
    }

    /** @param array{x: BigInteger, y: BigInteger} $point */
    /** @return array{x: BigInteger, y: BigInteger}|null */
    private function multiply(array $point, BigInteger $scalar): ?array
    {
        $scalar = $scalar->mod($this->order());
        $result = null;
        $addend = $point;

        while (! $scalar->isZero()) {
            if ($scalar->mod(2)->isEqualTo(1)) {
                $result = $this->add($result, $addend);
            }
            $addend = $this->add($addend, $addend);
            $scalar = $scalar->quotient(2);
        }

        return $result;
    }

    /** @param array{x: BigInteger, y: BigInteger} $point */
    private function isOnCurve(array $point): bool
    {
        $field = $this->field();
        $left = $this->mod($point['y']->multipliedBy($point['y']), $field);
        $right = $this->mod($point['x']->multipliedBy($point['x'])->multipliedBy($point['x'])->plus(7), $field);

        return $left->isEqualTo($right);
    }

    private function mod(BigInteger $value, BigInteger $modulus): BigInteger
    {
        $result = $value->mod($modulus);

        return $result->isNegative() ? $result->plus($modulus) : $result;
    }

    /** @param array{x: BigInteger, y: BigInteger} $point */
    private function hex(BigInteger $value): string
    {
        return str_pad(strtolower($value->toBase(16)), 64, '0', STR_PAD_LEFT);
    }
}
