<?php

namespace ElektronNet\Escrow;

final class Base58
{
    private const ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

    private function __construct()
    {
    }

    /**
     * @return string|null raw payload including the version byte, null if the checksum or alphabet is wrong
     */
    public static function decodeCheck(string $encoded): ?string
    {
        if ($encoded === '') {
            return null;
        }
        $number = gmp_init(0);
        $length = strlen($encoded);
        for ($i = 0; $i < $length; $i++) {
            $position = strpos(self::ALPHABET, $encoded[$i]);
            if ($position === false) {
                return null;
            }
            $number = gmp_add(gmp_mul($number, 58), $position);
        }
        $hex = gmp_strval($number, 16);
        if (strlen($hex) % 2 === 1) {
            $hex = '0' . $hex;
        }
        $bytes = $number == 0 ? '' : hex2bin($hex);
        $leadingZeros = strlen($encoded) - strlen(ltrim($encoded, '1'));
        $raw = str_repeat("\x00", $leadingZeros) . $bytes;
        if (strlen($raw) < 5) {
            return null;
        }
        $payload = substr($raw, 0, -4);
        if (substr(hash('sha256', hash('sha256', $payload, true), true), 0, 4) !== substr($raw, -4)) {
            return null;
        }

        return $payload;
    }

    public static function encodeCheck(string $payload): string
    {
        $raw = $payload . substr(hash('sha256', hash('sha256', $payload, true), true), 0, 4);
        $number = gmp_init(bin2hex($raw), 16);
        $out = '';
        while ($number > 0) {
            [$number, $remainder] = gmp_div_qr($number, 58);
            $out = self::ALPHABET[gmp_intval($remainder)] . $out;
        }
        $leadingZeros = strlen($raw) - strlen(ltrim($raw, "\x00"));

        return str_repeat('1', $leadingZeros) . $out;
    }
}
