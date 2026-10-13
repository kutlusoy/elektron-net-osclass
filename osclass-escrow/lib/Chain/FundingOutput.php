<?php

namespace ElektronNet\Escrow\Chain;

/**
 * One confirmed, unspent output at the escrow address.
 */
final class FundingOutput
{
    /** @var string */
    private $txid;

    /** @var int */
    private $vout;

    /** @var int amount paid to the watched address by this specific output, in lep */
    private $amountLep;

    public function __construct(string $txid, int $vout, int $amountLep)
    {
        $this->txid = $txid;
        $this->vout = $vout;
        $this->amountLep = $amountLep;
    }

    public function txid(): string
    {
        return $this->txid;
    }

    public function vout(): int
    {
        return $this->vout;
    }

    public function amountLep(): int
    {
        return $this->amountLep;
    }
}
