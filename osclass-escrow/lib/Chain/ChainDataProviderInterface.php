<?php

namespace ElektronNet\Escrow\Chain;

interface ChainDataProviderInterface
{
    /**
     * Transactions touching $address with the value each paid to it.
     *
     * @return AddressTransaction[]
     */
    public function getAddressTransactions(string $address): array;

    /**
     * Confirmed, unspent outputs at $address.
     *
     * @return FundingOutput[]
     */
    public function getFundingOutputs(string $address): array;

    /**
     * Confirmations of a transaction, 0 if unconfirmed.
     */
    public function getConfirmations(string $txid): int;
}
