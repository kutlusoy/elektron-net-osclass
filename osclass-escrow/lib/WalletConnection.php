<?php

namespace ElektronNet\Escrow;

use InvalidArgumentException;

/**
 * Reads the public wallet data a user pastes (the output of the node
 * console command "listdescriptors" without "true", or just its wpkh line)
 * and returns the account extended public key with its origin.
 *
 * Anything that contains a private key is refused so that it never gets
 * stored or processed.
 */
final class WalletConnection
{
    private const PRIVATE_KEY = '/[xyztuv]prv[1-9A-HJ-NP-Za-km-z]{50,}/';
    private const DESCRIPTOR = '/(?:wpkh\(\s*)?\[([0-9a-f]{8})((?:\/\d+[h\']?)+)\]([xt]pub[1-9A-HJ-NP-Za-km-z]{50,})\/0\/\*\)?(?:#([a-z0-9]{8}))?/';

    private function __construct()
    {
    }

    public static function containsPrivateKey(string $text): bool
    {
        return preg_match(self::PRIVATE_KEY, $text) === 1;
    }

    /**
     * @return array{fingerprint:string,originPath:string,xpub:string}
     * @throws InvalidArgumentException with a message safe to show to the user
     */
    public static function parse(string $text, NetworkParams $network): array
    {
        if (self::containsPrivateKey($text)) {
            throw new InvalidArgumentException('This text contains a private key. Do not paste it anywhere. Run the command without "true" and paste that output instead.');
        }
        $text = str_replace('\\/', '/', $text);
        if (!preg_match(self::DESCRIPTOR, $text, $match)) {
            throw new InvalidArgumentException('No receiving descriptor of the form wpkh([fingerprint/84h/...]xpub.../0/*) was found.');
        }
        [$whole, $fingerprint, $origin, $xpub] = $match;
        $checksum = $match[4] ?? '';

        if ($checksum !== '' && strpos($whole, 'wpkh(') === 0) {
            $body = substr($whole, 0, strpos($whole, '#'));
            if (Descriptor::checksum($body) !== $checksum) {
                throw new InvalidArgumentException('The descriptor checksum does not match. The text is incomplete or was changed.');
            }
        }

        $segments = array_values(array_filter(explode('/', str_replace("'", 'h', $origin)), 'strlen'));
        $node = Bip32::parseXpubOrFail($xpub, $network);
        if ($node['depth'] !== count($segments)) {
            throw new InvalidArgumentException('The key does not match its path.');
        }

        return ['fingerprint' => $fingerprint, 'originPath' => implode('/', $segments), 'xpub' => $xpub];
    }
}
