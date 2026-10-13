<?php

spl_autoload_register(function (string $class): void {
    $prefix = 'ElektronNet\\Escrow\\';
    if (strncmp($class, $prefix, strlen($prefix)) === 0) {
        $file = __DIR__ . '/../lib/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

$failures = 0;

function check(string $name, bool $ok): void
{
    global $failures;
    echo ($ok ? 'ok   ' : 'FAIL ') . $name . "\n";
    if (!$ok) {
        $failures++;
    }
}

function throws(callable $fn, string $class = Throwable::class): bool
{
    try {
        $fn();
    } catch (Throwable $e) {
        return $e instanceof $class;
    }

    return false;
}

function finish(): void
{
    global $failures;
    echo $failures === 0 ? "\nall passed\n" : "\n{$failures} failed\n";
    exit($failures === 0 ? 0 : 1);
}

/**
 * An unsigned release PSBT as a wallet would produce it for the escrow
 * (witness UTXO and witness script filled in).
 *
 * @param array<int,array{txid:string,vout:int,amountLep:int}> $outpoints
 */
function makePsbt(string $witnessScriptHex, array $outpoints, string $payoutScriptPubKeyHex, int $outputLep, int $locktime = 0): ElektronNet\Escrow\Psbt
{
    $spk = hex2bin(ElektronNet\Escrow\EscrowScript::scriptPubKeyHex($witnessScriptHex));
    $inputs = [];
    $maps = [];
    foreach ($outpoints as $o) {
        $inputs[] = ['txid' => $o['txid'], 'vout' => $o['vout'], 'scriptSig' => '', 'sequence' => 0xfffffffd];
        $maps[] = [["\x01", pack('P', $o['amountLep']) . chr(strlen($spk)) . $spk], ["\x05", hex2bin($witnessScriptHex)]];
    }
    $tx = new ElektronNet\Escrow\RawTransaction(2, $inputs, [['amountLep' => $outputLep, 'scriptPubKey' => hex2bin($payoutScriptPubKeyHex)]], $locktime);

    return new ElektronNet\Escrow\Psbt([["\x00", $tx->serializeWithoutWitness()]], $maps, [[]], $tx);
}

function withSignature(ElektronNet\Escrow\Psbt $psbt, string $pubKeyHex, string $sig = "\x30\x06\x02\x01\x01\x02\x01\x01\x01"): ElektronNet\Escrow\Psbt
{
    $maps = $psbt->inputMaps();
    foreach (array_keys($maps) as $i) {
        $maps[$i][] = ["\x02" . hex2bin($pubKeyHex), $sig];
    }

    return new ElektronNet\Escrow\Psbt([["\x00", $psbt->tx()->serializeWithoutWitness()]], $maps, $psbt->outputMaps(), $psbt->tx());
}
