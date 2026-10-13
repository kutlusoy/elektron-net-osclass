<?php if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');

use ElektronNet\Escrow\Descriptor;
use ElektronNet\Escrow\EscrowKeys;

/**
 * The two public keys a user contributes to a deal, derived from the
 * connected wallet. Null if the user has not connected a wallet.
 *
 * @return array{main:string,exit:string,mainIndex:int,exitIndex:int}|null
 */
function elektron_escrow_keys_for(int $userId, int $dealId): ?array
{
    $wallet = EscrowWalletDAO::newInstance()->forUser($userId);

    return $wallet === null ? null : EscrowKeys::forDeal($wallet['s_xpub'], $dealId, elektron_escrow_network_params());
}

/**
 * The escrow descriptor with the viewer's own two keys written as private
 * key expressions of their wallet, still containing the <PASTE_XPRV> placeholder.
 * The user replaces it in their own console; it never reaches this site.
 */
function elektron_escrow_private_descriptor(array $deal, string $role): ?string
{
    if (empty($deal['witness_script_hex'])) {
        return null;
    }
    if ($role === 'arbiter') {
        if (empty($deal['arbiter_pubkey']) || elektron_escrow_arbiter_xpub() === '') {
            return null;
        }
        $keys = elektron_escrow_arbiter_keys((int) $deal['pk_i_id']);
        $replace = [$keys['main'] => '<PASTE_XPRV>/' . EscrowKeys::BRANCH . '/' . $keys['mainIndex']];
    } else {
        $wallet = EscrowWalletDAO::newInstance()->forUser((int) $deal['fk_i_' . $role . '_id']);
        if ($wallet === null) {
            return null;
        }
        $keys = EscrowKeys::forDeal($wallet['s_xpub'], (int) $deal['pk_i_id'], elektron_escrow_network_params());
        $prefix = '<PASTE_XPRV>/' . EscrowKeys::BRANCH . '/';
        $replace = [$keys['main'] => $prefix . $keys['mainIndex'], $keys['exit'] => $prefix . $keys['exitIndex']];
    }

    return Descriptor::escrowBody(
        $deal['seller_pubkey'],
        $deal['seller_exit_pubkey'],
        $deal['buyer_pubkey'],
        $deal['buyer_exit_pubkey'],
        (int) $deal['buyer_refund_locktime'],
        (int) $deal['seller_release_locktime'],
        $replace,
        $deal['arbiter_pubkey'] ?: null
    );
}
