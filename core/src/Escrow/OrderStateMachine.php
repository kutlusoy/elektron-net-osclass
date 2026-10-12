<?php

namespace ElektronNet\Payments\Core\Escrow;

use InvalidArgumentException;

/**
 * Enforces which OrderStatus transitions are legal. A platform adapter calls
 * this before writing a new status into its own storage, instead of
 * re-implementing (and potentially getting wrong) the same rules per
 * platform.
 */
final class OrderStateMachine
{
    /** @var array<string, string[]> */
    private const ALLOWED_TRANSITIONS = [
        OrderStatus::AWAITING_PAYMENT => [OrderStatus::CONFIRMING],
        OrderStatus::CONFIRMING => [OrderStatus::FUNDED, OrderStatus::AWAITING_PAYMENT],
        OrderStatus::FUNDED => [
            OrderStatus::SHIPPED,
            OrderStatus::REFUNDED,
            OrderStatus::CLAIMED_BY_SELLER,
        ],
        OrderStatus::SHIPPED => [
            OrderStatus::RELEASE_PENDING_SELLER_SIGNATURE,
            OrderStatus::REFUNDED,
            OrderStatus::CLAIMED_BY_SELLER,
        ],
        OrderStatus::RELEASE_PENDING_SELLER_SIGNATURE => [
            OrderStatus::RELEASED,
            OrderStatus::REFUNDED,
            OrderStatus::CLAIMED_BY_SELLER,
        ],
    ];

    public static function canTransition(string $from, string $to): bool
    {
        if (in_array($from, OrderStatus::TERMINAL, true)) {
            return false;
        }

        return in_array($to, self::ALLOWED_TRANSITIONS[$from] ?? [], true);
    }

    /**
     * @throws InvalidArgumentException if the transition is not legal.
     */
    public static function assertTransition(string $from, string $to): void
    {
        if (!self::canTransition($from, $to)) {
            throw new InvalidArgumentException("Illegal order status transition: {$from} -> {$to}.");
        }
    }
}
