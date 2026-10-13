<?php

namespace ElektronNet\Escrow;

final class PubKey
{
    private const FIELD_PRIME_HEX = 'fffffffffffffffffffffffffffffffffffffffffffffffffffffffefffffc2f';

    private function __construct()
    {
    }

    /**
     * True for a 33-byte compressed secp256k1 public key that is a real curve point.
     */
    public static function isValidCompressed(string $hex): bool
    {
        if (!preg_match('/^0[23][0-9a-f]{64}$/', $hex)) {
            return false;
        }
        $p = gmp_init(self::FIELD_PRIME_HEX, 16);
        $x = gmp_init(substr($hex, 2), 16);
        if (gmp_cmp($x, $p) >= 0) {
            return false;
        }
        $rhs = gmp_mod(gmp_add(gmp_powm($x, 3, $p), 7), $p);
        $euler = gmp_powm($rhs, gmp_div_q(gmp_sub($p, 1), 2), $p);

        return gmp_cmp($euler, 1) === 0;
    }
}
