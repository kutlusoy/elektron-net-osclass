<?php if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');

use ElektronNet\Payments\Core\ChainData\FeeRateResolver;
use ElektronNet\Payments\Core\Escrow\EscrowAddress;
use ElektronNet\Payments\Core\Escrow\XpubChildKeyDeriver;
use ElektronNet\Payments\Core\Psbt\Bip174PsbtBuilder;
use ElektronNet\Payments\Core\Psbt\PsbtKeyOrigin;
use ElektronNet\Payments\Core\Psbt\PsbtRelay;

/**
 * Builds the release PSBT for an order that just entered
 * RELEASE_PENDING_SELLER_SIGNATURE, from data already on the order row plus
 * a live chain-data lookup for the exact funding outpoint (never stored on
 * the order row itself -- see FundingOutput's docblock for why the plain
 * "funded" check does not already have it).
 *
 * @param array $order EscrowOrderDAO row
 * @return PsbtRelay the unsigned PSBT, wrapped exactly as the shared core
 *     expects it to travel between platform storage and the two parties
 *     (see the core README, "Wiring a new platform" and PsbtRelay's own
 *     docblock) -- store $relay->base64() and $relay->signatureCount() (0
 *     at this point) on the order row
 * @throws RuntimeException if either party's xpub is gone (should not
 *     happen: EscrowOrderDAO::hasActiveOrderForUser() blocks changing a
 *     connected xpub while an order still needs it), or if the escrow
 *     address has no confirmed, unspent funding at all (should not happen
 *     for an order already past `funded`). Every confirmed UTXO at the
 *     address is swept together, regardless of how many there are or how
 *     their total compares to `amount_lep` -- see
 *     Bip174PsbtBuilder's docblock for why (nothing else could ever
 *     legitimately be sent to a one-time address, so all of it belongs to
 *     this order, including e.g. a second payment from a buyer who resumed
 *     an old, never-completed order at a since-changed price).
 */
function elektron_escrow_build_release_psbt(array $order): PsbtRelay
{
    $network = elektron_escrow_network();
    $config = elektron_escrow_config();

    $buyerXpub = elektron_escrow_get_user_xpub((int) $order['fk_i_buyer_id']);
    $sellerXpub = elektron_escrow_get_user_xpub((int) $order['fk_i_seller_id']);
    if ($buyerXpub === null || $sellerXpub === null) {
        throw new RuntimeException("elektron_escrow_build_release_psbt(): order {$order['pk_i_id']} is missing a connected xpub for one of its parties.");
    }

    $chainData = elektron_escrow_chain_data();
    $fundingOutputs = $chainData->getFundingOutputs($order['s_address']);
    if (count($fundingOutputs) < 1) {
        throw new RuntimeException(
            "elektron_escrow_build_release_psbt(): order {$order['pk_i_id']} has no confirmed, unspent funding at its escrow address; cannot build the release PSBT."
        );
    }

    $feeRateLepPerVByte = FeeRateResolver::resolve($chainData, $config->feeRateLepPerVByte());

    $escrowAddress = new EscrowAddress(
        $order['s_address'],
        $order['redeem_script_hex'],
        (int) $order['buyer_refund_locktime'],
        (int) $order['seller_release_locktime']
    );

    $sellerPayoutPubKeyHex = (new XpubChildKeyDeriver())->deriveChildPubKeyHex(
        $sellerXpub,
        (int) $order['seller_payout_index'],
        $network
    );

    $psbtBase64 = (new Bip174PsbtBuilder())->buildReleasePsbt(
        $escrowAddress,
        $fundingOutputs,
        new PsbtKeyOrigin($order['buyer_pubkey'], $buyerXpub, (int) $order['buyer_pubkey_index']),
        new PsbtKeyOrigin($order['seller_pubkey'], $sellerXpub, (int) $order['seller_pubkey_index']),
        $order['seller_payout_address'],
        new PsbtKeyOrigin($sellerPayoutPubKeyHex, $sellerXpub, (int) $order['seller_payout_index']),
        $feeRateLepPerVByte,
        $network
    );

    return new PsbtRelay($psbtBase64, 0);
}


/**
 * Builds both unilateral timeout PSBTs (buyer refund @ T1, seller claim @ T2)
 * once funding UTXOs are known. Stores the unsigned versions on the order row
 * so the parties can exchange signatures via the order-status page.
 *
 * Call this after the order reaches "funded" and funding outputs are confirmed.
 */
function elektron_escrow_prepare_timeout_psbts(array $order): array
{
    $network = elektron_escrow_network();
    $config = elektron_escrow_config();

    $buyerXpub = elektron_escrow_get_user_xpub((int) $order['fk_i_buyer_id']);
    $sellerXpub = elektron_escrow_get_user_xpub((int) $order['fk_i_seller_id']);
    if ($buyerXpub === null || $sellerXpub === null) {
        throw new RuntimeException("Timeout PSBTs: missing xpub for order {$order['pk_i_id']}.");
    }

    $chainData = elektron_escrow_chain_data();
    $fundingOutputs = $chainData->getFundingOutputs($order['s_address']);
    if (count($fundingOutputs) < 1) {
        throw new RuntimeException("Timeout PSBTs: no funding outputs for order {$order['pk_i_id']}.");
    }

    $feeRate = FeeRateResolver::resolve($chainData, $config->feeRateLepPerVByte());

    $escrowAddress = new EscrowAddress(
        $order['s_address'],
        $order['redeem_script_hex'],
        (int) $order['buyer_refund_locktime'],
        (int) $order['seller_release_locktime']
    );

    $builder = new Bip174PsbtBuilder();

    // Buyer refund: pays to a fresh child address derived from the buyer's xpub
    // (same pattern as the seller payout). Both parties must have connected a wallet.
    $deriver = new XpubChildKeyDeriver();
    $buyerPayoutIndex = (int) $order['buyer_pubkey_index'] + 1;
    $buyerPayoutPub = $deriver->deriveChildPubKeyHex($buyerXpub, $buyerPayoutIndex, $network);
    $buyerPayoutAddr = $deriver->deriveChildAddress($buyerXpub, $buyerPayoutIndex, $network);

    $refundPsbt = $builder->buildBuyerRefundPsbt(
        $escrowAddress,
        $fundingOutputs,
        new PsbtKeyOrigin($order['buyer_pubkey'], $buyerXpub, (int) $order['buyer_pubkey_index']),
        new PsbtKeyOrigin($order['seller_pubkey'], $sellerXpub, (int) $order['seller_pubkey_index']),
        $buyerPayoutAddr,
        new PsbtKeyOrigin($buyerPayoutPub, $buyerXpub, $buyerPayoutIndex),
        $feeRate,
        $network
    );

    $claimPsbt = $builder->buildSellerClaimPsbt(
        $escrowAddress,
        $fundingOutputs,
        new PsbtKeyOrigin($order['buyer_pubkey'], $buyerXpub, (int) $order['buyer_pubkey_index']),
        new PsbtKeyOrigin($order['seller_pubkey'], $sellerXpub, (int) $order['seller_pubkey_index']),
        $order['seller_payout_address'],
        new PsbtKeyOrigin(
            (new XpubChildKeyDeriver())->deriveChildPubKeyHex($sellerXpub, (int) $order['seller_payout_index'], $network),
            $sellerXpub,
            (int) $order['seller_payout_index']
        ),
        $feeRate,
        $network
    );

    return [
        'buyer_refund_psbt_base64' => $refundPsbt,
        'seller_claim_psbt_base64' => $claimPsbt,
    ];
}
