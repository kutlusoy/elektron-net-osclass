<?php

namespace ElektronNet\Escrow;

use InvalidArgumentException;

/**
 * Public (non-hardened) BIP32 child derivation from an extended public key.
 */
final class Bip32
{
    private const VERSION_XPUB = '0488b21e';
    private const VERSION_TPUB = '043587cf';
    private const HARDENED = 0x80000000;

    private function __construct()
    {
    }

    /**
     * @return array{depth:int,chainCode:string,pubKey:string} binary chain code, hex compressed public key
     * @throws InvalidArgumentException if this is not an extended public key of the network
     */
    public static function parseXpub(string $xpub, NetworkParams $network): array
    {
        $raw = Base58::decodeCheck(trim($xpub));
        if ($raw === null || strlen($raw) !== 78) {
            throw new InvalidArgumentException('This is not an extended public key.');
        }
        $version = bin2hex(substr($raw, 0, 4));
        $expected = $network->hrp() === 'be' ? self::VERSION_XPUB : self::VERSION_TPUB;
        if ($version !== $expected) {
            throw new InvalidArgumentException('This extended public key is not for this network.');
        }
        $pubKey = bin2hex(substr($raw, 45, 33));
        if (Secp256k1::decompress($pubKey) === null) {
            throw new InvalidArgumentException('The extended public key is invalid.');
        }

        return ['depth' => ord($raw[4]), 'chainCode' => substr($raw, 13, 32), 'pubKey' => $pubKey];
    }

    /**
     * First four bytes of the key's hash160, as hex: the wallet id shown in descriptors.
     *
     * @param array{depth:int,chainCode:string,pubKey:string} $node
     */
    public static function fingerprint(array $node): string
    {
        return substr(hash('ripemd160', hash('sha256', hex2bin($node['pubKey']), true)), 0, 8);
    }

    /**
     * @param array{depth:int,chainCode:string,pubKey:string} $node
     * @return array{depth:int,chainCode:string,pubKey:string}
     */
    public static function deriveChild(array $node, int $index): array
    {
        if ($index < 0 || $index >= self::HARDENED) {
            throw new InvalidArgumentException('Only non-hardened indexes can be derived from a public key.');
        }
        $digest = hash_hmac('sha512', hex2bin($node['pubKey']) . pack('N', $index), $node['chainCode'], true);
        $tweak = gmp_init(bin2hex(substr($digest, 0, 32)), 16);
        if (gmp_cmp($tweak, Secp256k1::order()) >= 0) {
            throw new InvalidArgumentException('Invalid derivation, use another index.');
        }
        $parent = Secp256k1::decompress($node['pubKey']);
        $child = Secp256k1::add(Secp256k1::multiply(Secp256k1::generator(), $tweak), $parent);
        if ($child === null) {
            throw new InvalidArgumentException('Invalid derivation, use another index.');
        }

        return ['depth' => $node['depth'] + 1, 'chainCode' => substr($digest, 32), 'pubKey' => Secp256k1::compress($child)];
    }

    /**
     * @param int[] $indexes
     * @return string hex compressed public key at the path below the extended public key
     */
    public static function derivePubKey(string $xpub, array $indexes, NetworkParams $network): string
    {
        $node = self::parseXpub($xpub, $network);
        foreach ($indexes as $index) {
            $node = self::deriveChild($node, $index);
        }

        return $node['pubKey'];
    }
}
