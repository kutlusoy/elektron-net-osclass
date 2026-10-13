<?php

namespace ElektronNet\Escrow;

use GMP;

/**
 * Minimal secp256k1 point arithmetic (affine coordinates), enough for public
 * BIP32 derivation. Not constant time: it only ever handles public data.
 */
final class Secp256k1
{
    private const P = 'fffffffffffffffffffffffffffffffffffffffffffffffffffffffefffffc2f';
    private const N = 'fffffffffffffffffffffffffffffffebaaedce6af48a03bbfd25e8cd0364141';
    private const GX = '79be667ef9dcbbac55a06295ce870b07029bfcdb2dce28d959f2815b16f81798';
    private const GY = '483ada7726a3c4655da4fbfc0e1108a8fd17b448a68554199c47d08ffb10d4b8';

    private function __construct()
    {
    }

    public static function order(): GMP
    {
        return gmp_init(self::N, 16);
    }

    /**
     * @return array{0:GMP,1:GMP}|null point from a compressed public key, null if it is not on the curve
     */
    public static function decompress(string $compressedHex): ?array
    {
        if (!preg_match('/^0[23][0-9a-f]{64}$/', $compressedHex)) {
            return null;
        }
        $p = gmp_init(self::P, 16);
        $x = gmp_init(substr($compressedHex, 2), 16);
        if (gmp_cmp($x, $p) >= 0) {
            return null;
        }
        $rhs = gmp_mod(gmp_add(gmp_powm($x, 3, $p), 7), $p);
        $y = gmp_powm($rhs, gmp_div_q(gmp_add($p, 1), 4), $p);
        if (gmp_cmp(gmp_mod(gmp_mul($y, $y), $p), $rhs) !== 0) {
            return null;
        }
        $wantOdd = $compressedHex[1] === '3';
        if ((gmp_intval(gmp_mod($y, 2)) === 1) !== $wantOdd) {
            $y = gmp_sub($p, $y);
        }

        return [$x, $y];
    }

    /**
     * @param array{0:GMP,1:GMP} $point
     */
    public static function compress(array $point): string
    {
        $prefix = gmp_intval(gmp_mod($point[1], 2)) === 1 ? '03' : '02';

        return $prefix . str_pad(gmp_strval($point[0], 16), 64, '0', STR_PAD_LEFT);
    }

    /**
     * @return array{0:GMP,1:GMP}
     */
    public static function generator(): array
    {
        return [gmp_init(self::GX, 16), gmp_init(self::GY, 16)];
    }

    /**
     * @param array{0:GMP,1:GMP}|null $a
     * @param array{0:GMP,1:GMP}|null $b
     * @return array{0:GMP,1:GMP}|null null is the point at infinity
     */
    public static function add(?array $a, ?array $b): ?array
    {
        if ($a === null) {
            return $b;
        }
        if ($b === null) {
            return $a;
        }
        $p = gmp_init(self::P, 16);
        if (gmp_cmp($a[0], $b[0]) === 0) {
            if (gmp_cmp(gmp_mod(gmp_add($a[1], $b[1]), $p), 0) === 0) {
                return null;
            }
            $slope = gmp_mod(gmp_mul(gmp_mul(3, gmp_mul($a[0], $a[0])), gmp_invert(gmp_mul(2, $a[1]), $p)), $p);
        } else {
            $slope = gmp_mod(gmp_mul(gmp_sub($b[1], $a[1]), gmp_invert(gmp_mod(gmp_sub($b[0], $a[0]), $p), $p)), $p);
        }
        $x = gmp_mod(gmp_sub(gmp_sub(gmp_mul($slope, $slope), $a[0]), $b[0]), $p);
        $y = gmp_mod(gmp_sub(gmp_mul($slope, gmp_sub($a[0], $x)), $a[1]), $p);

        return [$x, $y];
    }

    /**
     * @param array{0:GMP,1:GMP} $point
     * @return array{0:GMP,1:GMP}|null
     */
    public static function multiply(array $point, GMP $scalar): ?array
    {
        $result = null;
        $addend = $point;
        $bits = gmp_strval($scalar, 2);
        for ($i = strlen($bits) - 1; $i >= 0; $i--) {
            if ($bits[$i] === '1') {
                $result = self::add($result, $addend);
            }
            $addend = self::add($addend, $addend);
        }

        return $result;
    }
}
