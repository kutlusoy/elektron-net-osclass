<?php

namespace ElektronNet\Escrow;

use InvalidArgumentException;

/**
 * The escrow timeout policy, an admin setting for the whole marketplace and
 * never a per-deal choice. Both dates are counted from the moment the escrow
 * address is fixed (both keys known) and are written into its script:
 *
 * - T1 (buyerRefundDays): afterwards the buyer alone can reclaim the funds.
 * - T2 (sellerReleaseDays): afterwards the seller alone can claim the funds.
 */
final class TimeoutPolicy
{
    public const DEFAULT_BUYER_REFUND_DAYS = 30;
    public const DEFAULT_SELLER_RELEASE_DAYS = 60;

    /** T2 must be at least this many times T1. */
    public const MIN_RATIO = 1.5;

    public const MIN_BUYER_REFUND_DAYS = 3;
    public const MAX_SELLER_RELEASE_DAYS = 183; // roughly half a year

    /**
     * "Confirm receipt" has no on-chain effect, and the buyer's refund path
     * opens at T1 regardless. A release started closer to T1 than this could
     * be overtaken by the refund, so it is refused.
     */
    public const MIN_CONFIRM_RECEIPT_SAFETY_MARGIN_DAYS = 1;

    /** @var int */
    private $buyerRefundDays;

    /** @var int */
    private $sellerReleaseDays;

    private function __construct(int $buyerRefundDays, int $sellerReleaseDays)
    {
        $this->buyerRefundDays = $buyerRefundDays;
        $this->sellerReleaseDays = $sellerReleaseDays;
    }

    public static function defaults(): self
    {
        return new self(self::DEFAULT_BUYER_REFUND_DAYS, self::DEFAULT_SELLER_RELEASE_DAYS);
    }

    /**
     * @throws InvalidArgumentException if the pair violates a safety guardrail.
     */
    public static function fromDays(int $buyerRefundDays, int $sellerReleaseDays): self
    {
        if ($buyerRefundDays < self::MIN_BUYER_REFUND_DAYS) {
            throw new InvalidArgumentException(sprintf(
                'Buyer refund threshold must be at least %d days.',
                self::MIN_BUYER_REFUND_DAYS
            ));
        }
        if ($sellerReleaseDays > self::MAX_SELLER_RELEASE_DAYS) {
            throw new InvalidArgumentException(sprintf(
                'Seller release threshold must not exceed %d days.',
                self::MAX_SELLER_RELEASE_DAYS
            ));
        }
        if ($sellerReleaseDays < $buyerRefundDays * self::MIN_RATIO) {
            throw new InvalidArgumentException(sprintf(
                'Seller release threshold must be at least %.1fx the buyer refund threshold (got %d and %d days).',
                self::MIN_RATIO,
                $buyerRefundDays,
                $sellerReleaseDays
            ));
        }

        return new self($buyerRefundDays, $sellerReleaseDays);
    }

    public function buyerRefundDays(): int
    {
        return $this->buyerRefundDays;
    }

    public function sellerReleaseDays(): int
    {
        return $this->sellerReleaseDays;
    }

    /**
     * Absolute refund time (unix timestamp) counted from $fundedAtUnixTime,
     * here the moment the escrow address is fixed.
     */
    public function buyerRefundLocktime(int $fundedAtUnixTime): int
    {
        return $fundedAtUnixTime + $this->buyerRefundDays * 86400;
    }

    public function sellerReleaseLocktime(int $fundedAtUnixTime): int
    {
        return $fundedAtUnixTime + $this->sellerReleaseDays * 86400;
    }

    /**
     * False once too little time remains before the deal's own, already
     * fixed refund time to complete a cooperative release. Static on purpose:
     * the deal's frozen value matters, not the current admin setting.
     */
    public static function canSafelyStartRelease(int $buyerRefundLocktime, int $nowUnixTime): bool
    {
        return $nowUnixTime < $buyerRefundLocktime - self::MIN_CONFIRM_RECEIPT_SAFETY_MARGIN_DAYS * 86400;
    }
}
