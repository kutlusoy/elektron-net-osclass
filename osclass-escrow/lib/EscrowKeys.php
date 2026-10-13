<?php

namespace ElektronNet\Escrow;

use InvalidArgumentException;

/**
 * The two public keys a party contributes to one deal, derived from the
 * party's connected master public key at m/1370/(2n) and m/1370/(2n+1) for
 * deal number n. The path has no hardened step, so the node can verify
 * every key of the script, and it depends only on the deal number, so the
 * owner can always re-derive the matching private keys from their seed.
 */
final class EscrowKeys
{
    public const BRANCH = 1370;
    private const MAX_DEAL_ID = 1000000000;

    private function __construct()
    {
    }

    /**
     * @return array{main:string,exit:string,mainIndex:int,exitIndex:int}
     */
    public static function forDeal(string $masterXpub, int $dealId, NetworkParams $network): array
    {
        if ($dealId < 1 || $dealId > self::MAX_DEAL_ID) {
            throw new InvalidArgumentException('Deal number out of range.');
        }
        $master = Bip32::parseXpub($masterXpub, $network);
        if ($master['depth'] !== 0) {
            throw new InvalidArgumentException('The connected key is not a wallet master key.');
        }
        $mainIndex = 2 * $dealId;
        $branch = Bip32::deriveChild($master, self::BRANCH);

        return [
            'main' => Bip32::deriveChild($branch, $mainIndex)['pubKey'],
            'exit' => Bip32::deriveChild($branch, $mainIndex + 1)['pubKey'],
            'mainIndex' => $mainIndex,
            'exitIndex' => $mainIndex + 1,
        ];
    }
}
