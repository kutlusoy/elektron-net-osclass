<?php

namespace ElektronNet\Escrow;

final class Address
{
    private function __construct()
    {
    }

    public static function toScriptPubKeyHex(string $address, NetworkParams $network): ?string
    {
        $segwit = Bech32::decodeSegwit($network->hrp(), $address);
        if ($segwit !== null) {
            [$version, $program] = $segwit;
            if ($version === 0 && strlen($program) === 20) {
                return '0014' . bin2hex($program);
            }
            if ($version === 0 && strlen($program) === 32) {
                return '0020' . bin2hex($program);
            }
            if ($version === 1 && strlen($program) === 32) {
                return '5120' . bin2hex($program);
            }

            return null;
        }

        $payload = Base58::decodeCheck($address);
        if ($payload === null || strlen($payload) !== 21) {
            return null;
        }
        $version = ord($payload[0]);
        $hash = bin2hex(substr($payload, 1));
        if ($version === $network->p2pkhVersion()) {
            return '76a914' . $hash . '88ac';
        }
        if ($version === $network->p2shVersion()) {
            return 'a914' . $hash . '87';
        }

        return null;
    }

    public static function fromScriptPubKeyHex(string $scriptPubKeyHex, NetworkParams $network): ?string
    {
        $script = strtolower($scriptPubKeyHex);
        if (preg_match('/^0014([0-9a-f]{40})$/', $script, $m)) {
            return Bech32::encodeSegwit($network->hrp(), 0, hex2bin($m[1]));
        }
        if (preg_match('/^0020([0-9a-f]{64})$/', $script, $m)) {
            return Bech32::encodeSegwit($network->hrp(), 0, hex2bin($m[1]));
        }
        if (preg_match('/^5120([0-9a-f]{64})$/', $script, $m)) {
            return Bech32::encodeSegwit($network->hrp(), 1, hex2bin($m[1]));
        }
        if (preg_match('/^76a914([0-9a-f]{40})88ac$/', $script, $m)) {
            return Base58::encodeCheck(chr($network->p2pkhVersion()) . hex2bin($m[1]));
        }
        if (preg_match('/^a914([0-9a-f]{40})87$/', $script, $m)) {
            return Base58::encodeCheck(chr($network->p2shVersion()) . hex2bin($m[1]));
        }

        return null;
    }
}
