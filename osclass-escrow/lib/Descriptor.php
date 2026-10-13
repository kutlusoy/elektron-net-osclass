<?php

namespace ElektronNet\Escrow;

/**
 * Output descriptor strings with checksum, for importing the escrow into a node wallet.
 */
final class Descriptor
{
    private const INPUT_CHARSET = '0123456789()[],\'/*abcdefgh@:$%{}IJKLMNOPQRSTUVWXYZ&+-.;<=>?!^_|~ijklmnopqrstuvwxyzABCDEFGH`#"\\ ';
    private const CHECKSUM_CHARSET = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';

    private function __construct()
    {
    }

    /**
     * The escrow descriptor body (without checksum). $replace maps a public
     * key to the text that replaces it, which is the form a party completes
     * with their private keys before importing it.
     *
     * @param array<string,string> $replace
     */
    public static function escrowBody(string $sellerKey, string $sellerExitKey, string $buyerKey, string $buyerExitKey, int $refundLocktime, int $claimLocktime, array $replace = [], ?string $arbiterKey = null): string
    {
        $multi = implode(',', EscrowScript::sortedKeys($sellerKey, $buyerKey, $arbiterKey));
        $body = 'wsh(or_d(multi(2,' . $multi . '),or_i(and_v(v:pk(' . strtolower($buyerExitKey) . '),after(' . $refundLocktime . ')),and_v(v:pk(' . strtolower($sellerExitKey) . '),after(' . $claimLocktime . ')))))';

        foreach ($replace as $key => $text) {
            $body = str_replace(strtolower($key), $text, $body);
        }

        return $body;
    }

    public static function escrow(string $sellerKey, string $sellerExitKey, string $buyerKey, string $buyerExitKey, int $refundLocktime, int $claimLocktime, ?string $arbiterKey = null): string
    {
        return self::withChecksum(self::escrowBody($sellerKey, $sellerExitKey, $buyerKey, $buyerExitKey, $refundLocktime, $claimLocktime, [], $arbiterKey));
    }

    public static function withChecksum(string $descriptor): string
    {
        return $descriptor . '#' . self::checksum($descriptor);
    }

    public static function checksum(string $descriptor): string
    {
        $c = 1;
        $class = 0;
        $classCount = 0;
        $length = strlen($descriptor);
        for ($i = 0; $i < $length; $i++) {
            $position = strpos(self::INPUT_CHARSET, $descriptor[$i]);
            if ($position === false) {
                return '';
            }
            $c = self::polymod($c, $position & 31);
            $class = $class * 3 + ($position >> 5);
            if (++$classCount === 3) {
                $c = self::polymod($c, $class);
                $class = 0;
                $classCount = 0;
            }
        }
        if ($classCount > 0) {
            $c = self::polymod($c, $class);
        }
        for ($j = 0; $j < 8; $j++) {
            $c = self::polymod($c, 0);
        }
        $c ^= 1;
        $out = '';
        for ($j = 0; $j < 8; $j++) {
            $out .= self::CHECKSUM_CHARSET[($c >> (5 * (7 - $j))) & 31];
        }

        return $out;
    }

    private static function polymod(int $c, int $value): int
    {
        $top = $c >> 35;
        $c = (($c & 0x7ffffffff) << 5) ^ $value;
        if ($top & 1) {
            $c ^= 0xf5dee51989;
        }
        if ($top & 2) {
            $c ^= 0xa9fdca3312;
        }
        if ($top & 4) {
            $c ^= 0x1bab10e32d;
        }
        if ($top & 8) {
            $c ^= 0x3706b1677a;
        }
        if ($top & 16) {
            $c ^= 0x644d626ffd;
        }

        return $c;
    }
}
