<?php

namespace ElektronNet\Escrow\Chain;

/**
 * One transaction touching a watched escrow address.
 */
final class AddressTransaction
{
    /** @var string */
    private $txid;

    /** @var int amount received at the watched address, in lep (0 if this tx only spends from it) */
    private $receivedLep;

    /** @var int confirmations at the time this was fetched */
    private $confirmations;

    public function __construct(string $txid, int $receivedLep, int $confirmations)
    {
        $this->txid = $txid;
        $this->receivedLep = $receivedLep;
        $this->confirmations = $confirmations;
    }

    public function txid(): string
    {
        return $this->txid;
    }

    public function receivedLep(): int
    {
        return $this->receivedLep;
    }

    public function confirmations(): int
    {
        return $this->confirmations;
    }
}
