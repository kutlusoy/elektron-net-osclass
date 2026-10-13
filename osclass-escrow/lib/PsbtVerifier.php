<?php

namespace ElektronNet\Escrow;

use InvalidArgumentException;
use RuntimeException;

final class PsbtVerifier
{
    private const TYPE_NON_WITNESS_UTXO = 0x00;
    private const TYPE_WITNESS_UTXO = 0x01;
    private const TYPE_PARTIAL_SIG = 0x02;
    private const TYPE_SIGHASH = 0x03;
    private const TYPE_WITNESS_SCRIPT = 0x05;
    private const TYPE_BIP32 = 0x06;
    private const MAX_FEE_RATIO = 0.05;

    private function __construct()
    {
    }

    /**
     * Human-readable summary of a PSBT, for display only.
     *
     * @return array{
     *   txid:string,
     *   locktime:int,
     *   feeLep:?int,
     *   inputs:array<int,array{txid:string,vout:int,amountLep:?int,signedBy:string[]}>,
     *   outputs:array<int,array{address:?string,amountLep:int}>
     * }
     */
    public static function inspect(Psbt $psbt, NetworkParams $network): array
    {
        $tx = $psbt->tx();
        $inputs = [];
        $totalIn = 0;
        $complete = true;
        foreach ($tx->inputs() as $index => $input) {
            $map = $psbt->inputMaps()[$index];
            $amount = self::witnessUtxoAmount($map);
            if ($amount === null) {
                $complete = false;
            } else {
                $totalIn += $amount;
            }
            $inputs[] = [
                'txid' => $input['txid'],
                'vout' => $input['vout'],
                'amountLep' => $amount,
                'signedBy' => array_keys(self::partialSignatures($map)),
            ];
        }
        $outputs = [];
        $totalOut = 0;
        foreach ($tx->outputs() as $output) {
            $totalOut += $output['amountLep'];
            $outputs[] = [
                'address' => Address::fromScriptPubKeyHex(bin2hex($output['scriptPubKey']), $network),
                'amountLep' => $output['amountLep'],
            ];
        }

        return [
            'txid' => $tx->txid(),
            'locktime' => $tx->locktime(),
            'feeLep' => $complete ? $totalIn - $totalOut : null,
            'inputs' => $inputs,
            'outputs' => $outputs,
        ];
    }

    /**
     * Checks a release PSBT that a party built in their own wallet against
     * what this deal allows: it must spend exactly the escrow's funding
     * outputs, pay one output to the stated payout address, carry a
     * plausible fee, and hold nothing but signatures of the expected party.
     *
     * @param array{
     *   witnessScriptHex:string,
     *   fundingOutputs:array<int,array{txid:string,vout:int,amountLep:int}>,
     *   payoutScriptPubKeyHex:string,
     *   allowedPubKeys:string[],
     *   signerPubKey:string,
     *   signerExitPubKey?:string
     * } $expected
     * @return array{feeLep:int,outputAmountLep:int}
     * @throws RuntimeException describing the first deviation found
     */
    public static function assertReleasePsbt(Psbt $psbt, array $expected): array
    {
        $tx = $psbt->tx();
        if ($tx->locktime() >= 500000000) {
            throw new RuntimeException('The transaction has a time lock. A release must not be time locked.');
        }

        $escrowScriptPubKey = EscrowScript::scriptPubKeyHex($expected['witnessScriptHex']);
        $expectedOutpoints = [];
        foreach ($expected['fundingOutputs'] as $output) {
            $expectedOutpoints[$output['txid'] . ':' . $output['vout']] = $output['amountLep'];
        }
        $actualOutpoints = [];
        foreach ($tx->inputs() as $input) {
            $actualOutpoints[] = $input['txid'] . ':' . $input['vout'];
        }
        $expectedKeys = array_keys($expectedOutpoints);
        sort($expectedKeys);
        $sortedActual = $actualOutpoints;
        sort($sortedActual);
        if ($expectedKeys === [] || $sortedActual !== $expectedKeys) {
            throw new RuntimeException('The transaction must spend exactly the escrow payment output(s), nothing else.');
        }

        $totalIn = 0;
        $signers = null;
        $allowed = array_map('strtolower', array_merge($expected['allowedPubKeys'], [$expected['signerExitPubKey'] ?? '']));
        foreach ($tx->inputs() as $index => $input) {
            $map = $psbt->inputMaps()[$index];
            $amount = $expectedOutpoints[$input['txid'] . ':' . $input['vout']];
            self::assertInputData($map, $input, $index, $escrowScriptPubKey, $amount, $expected['witnessScriptHex']);
            $totalIn += $amount;

            $inputSigners = [];
            foreach (self::partialSignatures($map) as $pubKey => $signature) {
                if (!in_array($pubKey, $allowed, true)) {
                    throw new RuntimeException("Input {$index}: signature under an unexpected public key.");
                }
                self::assertSignatureShape($signature, $index);
                $inputSigners[] = $pubKey;
            }
            sort($inputSigners);
            if ($signers === null) {
                $signers = $inputSigners;
            } elseif ($signers !== $inputSigners) {
                throw new RuntimeException('Not every input was signed by the same keys.');
            }
        }
        // A wallet that holds both of the signer's keys signs with both; the exit key signature is harmless.
        $own = array_filter([strtolower($expected['signerPubKey']), strtolower($expected['signerExitPubKey'] ?? '')]);
        if (!in_array(strtolower($expected['signerPubKey']), $signers, true) || array_diff($signers, $own) !== []) {
            throw new RuntimeException('The PSBT must carry your signature and no signature of the other party.');
        }

        $outputs = $tx->outputs();
        if (count($outputs) !== 1) {
            throw new RuntimeException('A release must have exactly one output.');
        }
        if (bin2hex($outputs[0]['scriptPubKey']) !== strtolower($expected['payoutScriptPubKeyHex'])) {
            throw new RuntimeException('The output does not pay the stated payout address.');
        }
        $fee = $totalIn - $outputs[0]['amountLep'];
        if ($fee < 0 || $fee > $totalIn * self::MAX_FEE_RATIO) {
            throw new RuntimeException('The fee is negative or unreasonably high.');
        }

        return ['feeLep' => $fee, 'outputAmountLep' => $outputs[0]['amountLep']];
    }

    /**
     * @param array<int,array{0:string,1:string}> $map
     * @param array{txid:string,vout:int,scriptSig:string,sequence:int} $input
     */
    private static function assertInputData(array $map, array $input, int $index, string $escrowScriptPubKey, int $amount, string $witnessScriptHex): void
    {
        $hasUtxo = false;
        foreach ($map as [$key, $value]) {
            $type = ord($key[0]);
            if ($type === self::TYPE_WITNESS_UTXO) {
                $hasUtxo = true;
                if (strlen($value) < 9 || unpack('P', substr($value, 0, 8))[1] !== $amount || bin2hex(substr($value, 9)) !== $escrowScriptPubKey) {
                    throw new RuntimeException("Input {$index}: the witness UTXO does not match the escrow payment.");
                }
            } elseif ($type === self::TYPE_NON_WITNESS_UTXO) {
                $hasUtxo = true;
                self::assertPreviousTransaction($value, $input, $amount, $escrowScriptPubKey);
            } elseif ($type === self::TYPE_WITNESS_SCRIPT) {
                if (bin2hex($value) !== strtolower($witnessScriptHex)) {
                    throw new RuntimeException("Input {$index}: the witness script is not this deal's escrow script.");
                }
            } elseif ($type === self::TYPE_SIGHASH) {
                if ($value !== "\x01\x00\x00\x00") {
                    throw new RuntimeException("Input {$index}: only SIGHASH_ALL is accepted.");
                }
            } elseif (!in_array($type, [self::TYPE_PARTIAL_SIG, self::TYPE_BIP32], true)) {
                throw new RuntimeException("Input {$index}: unexpected field type {$type}.");
            }
        }
        if (!$hasUtxo) {
            throw new RuntimeException("Input {$index}: the PSBT carries no UTXO data.");
        }
    }

    /**
     * @param array<int,array{0:string,1:string}> $inputMap
     * @return array<string,string> hex public key => signature bytes
     */
    public static function partialSignatures(array $inputMap): array
    {
        $out = [];
        foreach (Psbt::pairsOfType($inputMap, self::TYPE_PARTIAL_SIG) as [$key, $value]) {
            if (strlen($key) === 34) {
                $out[bin2hex(substr($key, 1))] = $value;
            }
        }

        return $out;
    }

    /**
     * @param array<int,array{0:string,1:string}> $inputMap
     */
    private static function witnessUtxoAmount(array $inputMap): ?int
    {
        $pairs = Psbt::pairsOfType($inputMap, self::TYPE_WITNESS_UTXO);
        if (count($pairs) !== 1 || strlen($pairs[0][1]) < 9) {
            return null;
        }

        return unpack('P', substr($pairs[0][1], 0, 8))[1];
    }

    private static function assertSignatureShape(string $signature, int $inputIndex): void
    {
        $length = strlen($signature);
        if ($length < 9 || $length > 73 || $signature[0] !== "\x30" || ord($signature[1]) !== $length - 3) {
            throw new RuntimeException("Input {$inputIndex}: malformed signature.");
        }
        if ($signature[$length - 1] !== "\x01") {
            throw new RuntimeException("Input {$inputIndex}: only SIGHASH_ALL is accepted.");
        }
    }

    /**
     * @param array{txid:string,vout:int,scriptSig:string,sequence:int} $input
     */
    private static function assertPreviousTransaction(string $rawTx, array $input, int $amount, string $escrowScriptPubKey): void
    {
        try {
            $previous = RawTransaction::fromBinary($rawTx);
        } catch (InvalidArgumentException $e) {
            throw new RuntimeException('Embedded previous transaction is malformed.');
        }
        $output = $previous->outputs()[$input['vout']] ?? null;
        if ($previous->txid() !== $input['txid'] || $output === null
            || $output['amountLep'] !== $amount || bin2hex($output['scriptPubKey']) !== $escrowScriptPubKey) {
            throw new RuntimeException('Embedded previous transaction does not match the escrow payment.');
        }
    }
}
