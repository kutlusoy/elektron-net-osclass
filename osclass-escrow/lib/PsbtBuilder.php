<?php

namespace ElektronNet\Escrow;

use InvalidArgumentException;

/**
 * Builds an unsigned PSBT that spends the escrow outputs into one address,
 * as a convenience for a party who prefers loading a PSBT into their wallet
 * over typing commands. The wallet shows what it signs, and the party
 * checks it there. Nothing built here is stored or trusted.
 */
final class PsbtBuilder
{
    private const MIN_OUTPUT_LEP = 1000;
    private const MAX_FEE_RATE = 5000;

    private function __construct()
    {
    }

    /**
     * @param array<int,array{txid:string,vout:int,amountLep:int}> $prevouts
     * @param int $locktime 0 for the cooperative path, T1 or T2 for an exit
     * @return string base64 PSBT
     * @throws InvalidArgumentException with a message safe to show to the user
     */
    public static function buildSpend(string $witnessScriptHex, array $prevouts, string $payoutAddress, int $feeRateLepPerVByte, int $locktime, NetworkParams $network): string
    {
        if (count($prevouts) < 1) {
            throw new InvalidArgumentException('The payment outputs are not known yet.');
        }
        if ($feeRateLepPerVByte < 1 || $feeRateLepPerVByte > self::MAX_FEE_RATE) {
            throw new InvalidArgumentException('The fee rate must be a whole number between 1 and ' . self::MAX_FEE_RATE . ' lep per byte.');
        }
        $payoutScript = Address::toScriptPubKeyHex(trim($payoutAddress), $network);
        if ($payoutScript === null) {
            throw new InvalidArgumentException('This is not a valid address for this network.');
        }

        $total = 0;
        foreach ($prevouts as $prevout) {
            $total += $prevout['amountLep'];
        }
        $fee = $feeRateLepPerVByte * self::estimateVsize(count($prevouts), strlen($witnessScriptHex) / 2, strlen($payoutScript) / 2);
        if ($total - $fee < self::MIN_OUTPUT_LEP) {
            throw new InvalidArgumentException('The amount does not cover the fee at this fee rate.');
        }

        $escrowScriptPubKey = hex2bin(EscrowScript::scriptPubKeyHex($witnessScriptHex));
        $sequence = $locktime > 0 ? 0xfffffffe : 0xfffffffd;
        $inputs = [];
        $inputMaps = [];
        foreach ($prevouts as $prevout) {
            $inputs[] = ['txid' => $prevout['txid'], 'vout' => $prevout['vout'], 'scriptSig' => '', 'sequence' => $sequence];
            $inputMaps[] = [
                ["\x01", pack('P', $prevout['amountLep']) . ByteReader::encodeVarInt(strlen($escrowScriptPubKey)) . $escrowScriptPubKey],
                ["\x05", hex2bin($witnessScriptHex)],
            ];
        }
        $tx = new RawTransaction(2, $inputs, [['amountLep' => $total - $fee, 'scriptPubKey' => hex2bin($payoutScript)]], $locktime);

        return (new Psbt([["\x00", $tx->serializeWithoutWitness()]], $inputMaps, [[]], $tx))->toBase64();
    }

    /**
     * Upper bound of the virtual size for any spending path of the escrow.
     */
    public static function estimateVsize(int $inputCount, int $witnessScriptLength, int $outputScriptLength): int
    {
        $base = 4 + 1 + $inputCount * 41 + 1 + (9 + $outputScriptLength) + 4;
        $witness = 2 + $inputCount * (1 + 1 + 2 * 74 + ByteReader::varIntLength($witnessScriptLength) + $witnessScriptLength);
        $weight = $base * 3 + ($base + $witness);

        return (int) ceil($weight / 4) + 2;
    }
}
