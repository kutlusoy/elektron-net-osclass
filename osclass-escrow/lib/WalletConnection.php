<?php

namespace ElektronNet\Escrow;

use InvalidArgumentException;

/**
 * Reads the public wallet data a user pastes (the output of the node
 * console command "gethdkeys") and returns the wallet's master extended
 * public key with its fingerprint.
 *
 * Anything that contains a private key is refused so that it never gets
 * stored or processed.
 */
final class WalletConnection
{
    private const PRIVATE_KEY = '/[xyztuv]prv[1-9A-HJ-NP-Za-km-z]{50,}/';
    private const EXTENDED_PUBLIC_KEY = '/[xt]pub[1-9A-HJ-NP-Za-km-z]{50,}/';

    private function __construct()
    {
    }

    public static function containsPrivateKey(string $text): bool
    {
        return preg_match(self::PRIVATE_KEY, $text) === 1;
    }

    /**
     * @return array{fingerprint:string,xpub:string}
     * @throws InvalidArgumentException with a message safe to show to the user
     */
    public static function parse(string $text, NetworkParams $network): array
    {
        if (self::containsPrivateKey($text)) {
            throw new InvalidArgumentException('This text contains a private key. Do not paste it anywhere. Run the command without "private" and paste that output instead.');
        }
        preg_match_all(self::EXTENDED_PUBLIC_KEY, $text, $matches);
        $masters = [];
        foreach (array_unique($matches[0]) as $candidate) {
            try {
                $node = Bip32::parseXpub($candidate, $network);
            } catch (InvalidArgumentException $e) {
                continue;
            }
            if ($node['depth'] === 0) {
                $masters[$candidate] = $node;
            }
        }
        if ($masters === []) {
            throw new InvalidArgumentException('No wallet key was found. Paste the whole result of the command gethdkeys.');
        }
        if (count($masters) > 1) {
            throw new InvalidArgumentException('The text contains several wallet keys. Run gethdkeys {"active_only":true} and paste that result.');
        }
        $xpub = (string) array_key_first($masters);

        return ['fingerprint' => Bip32::fingerprint($masters[$xpub]), 'xpub' => $xpub];
    }
}
