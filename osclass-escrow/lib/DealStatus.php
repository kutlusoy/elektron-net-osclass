<?php

namespace ElektronNet\Escrow;

/**
 * Lifecycle of one escrow deal. Every non-terminal status has exactly one
 * party who may act next (see actor()), so neither side can run ahead of the other.
 */
final class DealStatus
{
    public const AWAITING_PAYMENT = 'awaiting_payment';
    public const CONFIRMING = 'confirming';
    public const FUNDED = 'funded';
    public const SHIPPED = 'shipped';
    public const AWAITING_SELLER_RELEASE = 'awaiting_seller_release';
    public const AWAITING_BUYER_RELEASE = 'awaiting_buyer_release';
    public const RELEASED = 'released';
    public const REFUNDED = 'refunded';
    public const CLAIMED = 'claimed';
    public const CANCELLED = 'cancelled';

    public const TERMINAL = [self::RELEASED, self::REFUNDED, self::CLAIMED, self::CANCELLED];

    /** Statuses in which the payment may already be on chain, so a timeout path can apply. */
    public const FUNDS_AT_RISK = [
        self::CONFIRMING,
        self::FUNDED,
        self::SHIPPED,
        self::AWAITING_SELLER_RELEASE,
        self::AWAITING_BUYER_RELEASE,
    ];

    /** Statuses in which no payment can exist yet and the deal can be cancelled. */
    public const CANCELLABLE = [self::AWAITING_PAYMENT];

    private const ACTORS = [
        self::AWAITING_PAYMENT => 'buyer',
        self::FUNDED => 'seller',
        self::SHIPPED => 'buyer',
        self::AWAITING_SELLER_RELEASE => 'seller',
        self::AWAITING_BUYER_RELEASE => 'buyer',
    ];

    private function __construct()
    {
    }

    /**
     * Whether $role has something to do in this deal right now.
     */
    public static function mayAct(array $deal, string $role): bool
    {
        return self::actor($deal['status']) === $role;
    }

    /**
     * @return string|null 'buyer' or 'seller' for a status with one fixed actor, null while the chain is being waited on or once terminal
     */
    public static function actor(string $status): ?string
    {
        return self::ACTORS[$status] ?? null;
    }

    public static function isTerminal(string $status): bool
    {
        return in_array($status, self::TERMINAL, true);
    }
}
