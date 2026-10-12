<?php

namespace ElektronNet\Payments\Core\Psbt;

use BitWasp\Bitcoin\Address\AddressCreator;
use BitWasp\Bitcoin\Key\Factory\HierarchicalKeyFactory;
use BitWasp\Bitcoin\Network\Network;
use BitWasp\Bitcoin\Script\ScriptFactory;
use BitWasp\Bitcoin\Script\WitnessScript;
use BitWasp\Bitcoin\Transaction\Factory\TxBuilder;
use BitWasp\Bitcoin\Transaction\TransactionOutput;
use BitWasp\Buffertools\Buffer;
use ElektronNet\Payments\Core\ChainData\FundingOutput;
use ElektronNet\Payments\Core\Escrow\EscrowAddress;
use RuntimeException;

/**
 * Reference (and, for now, only) implementation of
 * ReleasePsbtBuilderInterface, built on bitwasp/bitcoin's low-level
 * primitives plus Bip174Writer for the outer BIP174 byte format -- see that
 * class's docblock for why this had to be hand-rolled instead of using an
 * existing PSBT library.
 *
 * Spends every confirmed, unspent output at the escrow address (usually
 * exactly one, but see ChainDataProviderInterface::getFundingOutputs()'s
 * docblock for why it can legitimately be more -- e.g. a resumed order
 * whose buyer paid twice) into a single output at the seller's payout
 * address, matching PlainMultisigEscrowScriptBuilder's plain
 * `2 <buyerPubKey> <sellerPubKey> 2 OP_CHECKMULTISIG` script. There is no
 * change output because the escrow address's *entire* balance is always
 * swept in one go: nothing else could ever legitimately be sent to a
 * one-time address, so nothing is ever left behind on purpose.
 */
final class Bip174PsbtBuilder implements ReleasePsbtBuilderInterface
{
    /**
     * Estimates the release transaction's virtual size, in vBytes, for
     * $inputCount P2WSH 2-of-2-multisig inputs and the one fixed P2WPKH
     * output, derived from BIP141's weight formula (weight = base_size*3 +
     * total_size, vsize = ceil(weight/4)):
     *
     *   base (non-witness) size: version(4) + incount(1) +
     *     $inputCount * [outpoint(36)+scriptSigLen(1)+sequence(4)](41) +
     *     outcount(1) + [value(8)+P2WPKH scriptPubKey len+script(23)](31) +
     *     locktime(4)
     *   witness size: marker+flag(2) + $inputCount * (itemcount(1) +
     *     dummy-OP_0(1) + sig1 (len-byte + up to 72 DER+sighash bytes)(73) +
     *     sig2(73) + witnessScript (len-byte + this script's fixed 71
     *     bytes)(72)) = $inputCount * 220
     *
     * Only a fee-estimate input, not consensus-critical (a small constant
     * is added for headroom): the actual signatures each wallet produces
     * decide the real final size, and any of this project's supported
     * wallets lets its own user review the fee before signing.
     */
    private static function estimateVsize(int $inputCount): int
    {
        $baseSize = 4 + 1 + ($inputCount * 41) + 1 + 31 + 4;
        $witnessSize = $inputCount * 220;
        $totalSize = $baseSize + 2 + $witnessSize;
        $weight = ($baseSize * 3) + $totalSize;

        return (int) ceil($weight / 4) + 2;
    }

    /**
     * @param FundingOutput[] $fundingOutputs every UTXO to sweep, all at $escrowAddress
     */
    public function buildReleasePsbt(
        EscrowAddress $escrowAddress,
        array $fundingOutputs,
        PsbtKeyOrigin $buyerKey,
        PsbtKeyOrigin $sellerKey,
        string $sellerPayoutAddress,
        PsbtKeyOrigin $sellerPayoutKey,
        int $feeRateLepPerVByte,
        Network $network
    ): string {
        if (count($fundingOutputs) < 1) {
            throw new RuntimeException('Release PSBT: at least one funding output is required.');
        }

        $totalFundingLep = 0;
        foreach ($fundingOutputs as $fundingOutput) {
            $totalFundingLep += $fundingOutput->amountLep();
        }

        $fee = $feeRateLepPerVByte * self::estimateVsize(count($fundingOutputs));
        $outputAmountLep = $totalFundingLep - $fee;
        if ($outputAmountLep <= 0) {
            throw new RuntimeException(
                "Release PSBT: total funding ({$totalFundingLep} lep) does not cover the estimated fee ({$fee} lep)."
            );
        }

        $witnessScript = ScriptFactory::fromHex($escrowAddress->redeemScriptHex());
        $escrowScriptPubKey = (new WitnessScript($witnessScript))->getOutputScript();

        $payoutAddress = (new AddressCreator())->fromString($sellerPayoutAddress, $network);

        $txBuilder = new TxBuilder();
        foreach ($fundingOutputs as $fundingOutput) {
            $txBuilder->input($fundingOutput->txid(), $fundingOutput->vout(), null, 0xffffffff);
        }
        $unsignedTx = $txBuilder->payToAddress($outputAmountLep, $payoutAddress)->get();

        $globalPairs = [
            ['key' => Bip174Writer::key(0x00), 'value' => $unsignedTx->getBinary()],
        ];

        // Each input carries its own witness_utxo, at that specific UTXO's
        // own amount -- not the total -- since the BIP143 sighash a wallet
        // computes for that input depends on the value of the exact output
        // it spends, not the transaction's total input value.
        $inputMaps = [];
        foreach ($fundingOutputs as $fundingOutput) {
            $witnessUtxo = new TransactionOutput($fundingOutput->amountLep(), $escrowScriptPubKey);
            $inputMaps[] = [
                ['key' => Bip174Writer::key(0x01), 'value' => $witnessUtxo->getBinary()],
                ['key' => Bip174Writer::key(0x05), 'value' => $witnessScript->getBinary()],
                $this->bip32DerivationPair($buyerKey, $network),
                $this->bip32DerivationPair($sellerKey, $network),
            ];
        }

        $outputPairs = [
            $this->bip32DerivationPair($sellerPayoutKey, $network, 0x02),
        ];

        $psbtBytes = Bip174Writer::encode($globalPairs, $inputMaps, [$outputPairs]);

        return base64_encode($psbtBytes);
    }

    /**
     * @return array{key:string,value:string}
     */
    private function bip32DerivationPair(PsbtKeyOrigin $key, Network $network, int $keyType = 0x06): array
    {
        $fingerprint = (new HierarchicalKeyFactory())
            ->fromExtended($key->xpub(), $network)
            ->getPublicKey()
            ->getPubKeyHash()
            ->slice(0, 4)
            ->getBinary();

        $value = $fingerprint . Bip174Writer::uint32LE(0) . Bip174Writer::uint32LE($key->index());

        return [
            'key' => Bip174Writer::key($keyType, Buffer::hex($key->pubKeyHex())->getBinary()),
            'value' => $value,
        ];
    }


    /**
     * Builds a buyer-refund PSBT that spends the escrow to the buyer's payout
     * address, locked with nLockTime = buyerRefundLocktime (T1).
     * The seller must sign this before the buyer pays, so the buyer has a
     * unilateral exit after T1.
     *
     * @param FundingOutput[] $fundingOutputs
     */
    public function buildBuyerRefundPsbt(
        EscrowAddress $escrowAddress,
        array $fundingOutputs,
        PsbtKeyOrigin $buyerKey,
        PsbtKeyOrigin $sellerKey,
        string $buyerPayoutAddress,
        PsbtKeyOrigin $buyerPayoutKey,
        int $feeRateLepPerVByte,
        Network $network
    ): string {
        return $this->buildTimeoutPsbt(
            $escrowAddress,
            $fundingOutputs,
            $buyerKey,
            $sellerKey,
            $buyerPayoutAddress,
            $buyerPayoutKey,
            $feeRateLepPerVByte,
            $network,
            $escrowAddress->buyerRefundLocktime()
        );
    }

    /**
     * Builds a seller-claim PSBT that spends the escrow to the seller's payout
     * address, locked with nLockTime = sellerReleaseLocktime (T2).
     * The buyer must sign this before paying, so the seller has a unilateral
     * exit after T2.
     *
     * @param FundingOutput[] $fundingOutputs
     */
    public function buildSellerClaimPsbt(
        EscrowAddress $escrowAddress,
        array $fundingOutputs,
        PsbtKeyOrigin $buyerKey,
        PsbtKeyOrigin $sellerKey,
        string $sellerPayoutAddress,
        PsbtKeyOrigin $sellerPayoutKey,
        int $feeRateLepPerVByte,
        Network $network
    ): string {
        return $this->buildTimeoutPsbt(
            $escrowAddress,
            $fundingOutputs,
            $buyerKey,
            $sellerKey,
            $sellerPayoutAddress,
            $sellerPayoutKey,
            $feeRateLepPerVByte,
            $network,
            $escrowAddress->sellerReleaseLocktime()
        );
    }

    /**
     * Shared builder for time-locked unilateral exit PSBTs.
     *
     * @param FundingOutput[] $fundingOutputs
     */
    private function buildTimeoutPsbt(
        EscrowAddress $escrowAddress,
        array $fundingOutputs,
        PsbtKeyOrigin $buyerKey,
        PsbtKeyOrigin $sellerKey,
        string $payoutAddressStr,
        PsbtKeyOrigin $payoutKey,
        int $feeRateLepPerVByte,
        Network $network,
        int $lockTime
    ): string {
        if (count($fundingOutputs) < 1) {
            throw new RuntimeException('Timeout PSBT: at least one funding output is required.');
        }

        $totalFundingLep = 0;
        foreach ($fundingOutputs as $fundingOutput) {
            $totalFundingLep += $fundingOutput->amountLep();
        }

        $fee = $feeRateLepPerVByte * self::estimateVsize(count($fundingOutputs));
        $outputAmountLep = $totalFundingLep - $fee;
        if ($outputAmountLep <= 0) {
            throw new RuntimeException(
                "Timeout PSBT: total funding ({$totalFundingLep} lep) does not cover the estimated fee ({$fee} lep)."
            );
        }

        $witnessScript = ScriptFactory::fromHex($escrowAddress->redeemScriptHex());
        $escrowScriptPubKey = (new WitnessScript($witnessScript))->getOutputScript();

        $payoutAddress = (new AddressCreator())->fromString($payoutAddressStr, $network);

        $txBuilder = new TxBuilder();
        foreach ($fundingOutputs as $fundingOutput) {
            // nSequence must be < 0xffffffff for nLockTime to be enforced
            $txBuilder->input($fundingOutput->txid(), $fundingOutput->vout(), null, 0xfffffffe);
        }
        $unsignedTx = $txBuilder
            ->payToAddress($outputAmountLep, $payoutAddress)
            ->locktime($lockTime)
            ->get();

        $globalPairs = [
            ['key' => Bip174Writer::key(0x00), 'value' => $unsignedTx->getBinary()],
        ];

        $inputMaps = [];
        foreach ($fundingOutputs as $fundingOutput) {
            $witnessUtxo = new TransactionOutput($fundingOutput->amountLep(), $escrowScriptPubKey);
            $inputMaps[] = [
                ['key' => Bip174Writer::key(0x01), 'value' => $witnessUtxo->getBinary()],
                ['key' => Bip174Writer::key(0x05), 'value' => $witnessScript->getBinary()],
                $this->bip32DerivationPair($buyerKey, $network),
                $this->bip32DerivationPair($sellerKey, $network),
            ];
        }

        $outputPairs = [
            $this->bip32DerivationPair($payoutKey, $network, 0x02),
        ];

        $psbtBytes = Bip174Writer::encode($globalPairs, $inputMaps, [$outputPairs]);

        return base64_encode($psbtBytes);
    }

}
