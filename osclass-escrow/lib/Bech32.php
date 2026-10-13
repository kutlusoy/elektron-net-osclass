<?php

namespace ElektronNet\Escrow;

final class Bech32
{
    private const CHARSET = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';
    private const CONST_BECH32 = 1;
    private const CONST_BECH32M = 0x2bc830a3;

    private function __construct()
    {
    }

    public static function encodeSegwit(string $hrp, int $witnessVersion, string $program): string
    {
        $data = array_merge([$witnessVersion], self::convertBits(array_values(unpack('C*', $program)), 8, 5, true));
        $constant = $witnessVersion === 0 ? self::CONST_BECH32 : self::CONST_BECH32M;
        $checksum = self::createChecksum($hrp, $data, $constant);
        $out = $hrp . '1';
        foreach (array_merge($data, $checksum) as $value) {
            $out .= self::CHARSET[$value];
        }

        return $out;
    }

    /**
     * @return array{0:int,1:string}|null witness version and raw program, or null if invalid
     */
    public static function decodeSegwit(string $hrp, string $address): ?array
    {
        if (strlen($address) < 8 || strlen($address) > 90) {
            return null;
        }
        if ($address !== strtolower($address) && $address !== strtoupper($address)) {
            return null;
        }
        $address = strtolower($address);
        $separator = strrpos($address, '1');
        if ($separator === false || $separator < 1 || $separator + 7 > strlen($address)) {
            return null;
        }
        if (substr($address, 0, $separator) !== $hrp) {
            return null;
        }
        $data = [];
        for ($i = $separator + 1, $n = strlen($address); $i < $n; $i++) {
            $position = strpos(self::CHARSET, $address[$i]);
            if ($position === false) {
                return null;
            }
            $data[] = $position;
        }
        $polymod = self::polymod(array_merge(self::hrpExpand($hrp), $data));
        $witnessVersion = $data[0];
        $expected = $witnessVersion === 0 ? self::CONST_BECH32 : self::CONST_BECH32M;
        if ($polymod !== $expected || $witnessVersion > 16) {
            return null;
        }
        $program = self::convertBits(array_slice($data, 1, -6), 5, 8, false);
        if ($program === null || count($program) < 2 || count($program) > 40) {
            return null;
        }
        if ($witnessVersion === 0 && count($program) !== 20 && count($program) !== 32) {
            return null;
        }

        return [$witnessVersion, pack('C*', ...$program)];
    }

    /**
     * @param int[] $data
     * @return int[]|null
     */
    private static function convertBits(array $data, int $fromBits, int $toBits, bool $pad): ?array
    {
        $acc = 0;
        $bits = 0;
        $out = [];
        $maxValue = (1 << $toBits) - 1;
        foreach ($data as $value) {
            if ($value < 0 || ($value >> $fromBits) !== 0) {
                return null;
            }
            $acc = (($acc << $fromBits) | $value) & 0xfffffff;
            $bits += $fromBits;
            while ($bits >= $toBits) {
                $bits -= $toBits;
                $out[] = ($acc >> $bits) & $maxValue;
            }
        }
        if ($pad) {
            if ($bits > 0) {
                $out[] = ($acc << ($toBits - $bits)) & $maxValue;
            }
        } elseif ($bits >= $fromBits || (($acc << ($toBits - $bits)) & $maxValue) !== 0) {
            return null;
        }

        return $out;
    }

    /**
     * @param int[] $values
     */
    private static function polymod(array $values): int
    {
        $generator = [0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3];
        $chk = 1;
        foreach ($values as $value) {
            $top = $chk >> 25;
            $chk = (($chk & 0x1ffffff) << 5) ^ $value;
            for ($i = 0; $i < 5; $i++) {
                if ((($top >> $i) & 1) === 1) {
                    $chk ^= $generator[$i];
                }
            }
        }

        return $chk;
    }

    /**
     * @return int[]
     */
    private static function hrpExpand(string $hrp): array
    {
        $out = [];
        $length = strlen($hrp);
        for ($i = 0; $i < $length; $i++) {
            $out[] = ord($hrp[$i]) >> 5;
        }
        $out[] = 0;
        for ($i = 0; $i < $length; $i++) {
            $out[] = ord($hrp[$i]) & 31;
        }

        return $out;
    }

    /**
     * @param int[] $data
     * @return int[]
     */
    private static function createChecksum(string $hrp, array $data, int $constant): array
    {
        $polymod = self::polymod(array_merge(self::hrpExpand($hrp), $data, [0, 0, 0, 0, 0, 0])) ^ $constant;
        $out = [];
        for ($i = 0; $i < 6; $i++) {
            $out[] = ($polymod >> (5 * (5 - $i))) & 31;
        }

        return $out;
    }
}
