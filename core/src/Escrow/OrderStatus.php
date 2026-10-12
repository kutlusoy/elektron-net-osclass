<?php

namespace ElektronNet\Payments\Core\Escrow;

/**
 * The platform-independent lifecycle of one escrow order. A platform
 * adapter stores this value alongside its own order/listing record (for
 * Osclass: a column on the plugin's own DB table; the native Osclass item
 * table is never modified).
 */
final class OrderStatus
{
    /** Escrow address generated, waiting for the buyer's on-chain payment. */
    public const AWAITING_PAYMENT = 'awaiting_payment';

    /** Payment seen on chain, waiting for the required confirmations. */
    public const CONFIRMING = 'confirming';

    /** Payment confirmed. Buyer-refund countdown (T1) is now running. */
    public const FUNDED = 'funded';

    /**
     * Seller has confirmed that the goods have been shipped.
     * Buyer can now confirm receipt.
     */
    public const SHIPPED = 'shipped';

    /** Buyer confirmed receipt; release PSBT is out for the seller's signature. */
    public const RELEASE_PENDING_SELLER_SIGNATURE = 'release_pending_seller_signature';

    /** Both signatures present and the wallet broadcast the release transaction. */
    public const RELEASED = 'released';

    /** T1 has passed and the buyer chose to reclaim the funds unilaterally. */
    public const REFUNDED = 'refunded';

    /** T2 has passed and the seller chose to claim the funds unilaterally. */
    public const CLAIMED_BY_SELLER = 'claimed_by_seller';

    /** Terminal states; a state machine transition out of these is never valid. */
    public const TERMINAL = [
        self::RELEASED,
        self::REFUNDED,
        self::CLAIMED_BY_SELLER,
    ];

    private function __construct()
    {
        // Constants-only holder, never instantiated.
    }
}
