<?php

namespace ElektronNet\Escrow;

use InvalidArgumentException;

/**
 * The two public keys a party contributes to one deal, derived from the
 * party's connected account key. They sit in a reserved index range far
 * above anything a wallet hands out as an address, so they never clash with
 * normal addresses, and they depend only on the deal number, so the owner
 * can always re-derive the matching private keys from their seed.
 */
final class EscrowKeys
{
    public const INDEX_BASE = 2000000000;
    private const MAX_DEAL_ID = 73741823;

    private function __construct()
    {
    }

    /**
     * @return array{main:string,exit:string,mainIndex:int,exitIndex:int}
     */
    public static function forDeal(string $xpub, int $dealId, NetworkParams $network): array
    {
        if ($dealId < 1 || $dealId > self::MAX_DEAL_ID) {
            throw new InvalidArgumentException('Deal number out of range.');
        }
        $mainIndex = self::INDEX_BASE + 2 * $dealId;
        $exitIndex = $mainIndex + 1;
        $chain = Bip32::deriveChild(Bip32::parseXpub($xpub, $network), 0);

        return [
            'main' => Bip32::deriveChild($chain, $mainIndex)['pubKey'],
            'exit' => Bip32::deriveChild($chain, $exitIndex)['pubKey'],
            'mainIndex' => $mainIndex,
            'exitIndex' => $exitIndex,
        ];
    }
}
