<?php

// Checks of the dependency-free contract layer. Run: php osclass-escrow/tests/ContractTest.php

require __DIR__ . '/bootstrap.php';

use ElektronNet\Escrow\Address;
use ElektronNet\Escrow\Bech32;
use ElektronNet\Escrow\Descriptor;
use ElektronNet\Escrow\EscrowKeys;
use ElektronNet\Escrow\EscrowScript;
use ElektronNet\Escrow\NetworkParams;
use ElektronNet\Escrow\Psbt;
use ElektronNet\Escrow\PsbtBuilder;
use ElektronNet\Escrow\PsbtVerifier;
use ElektronNet\Escrow\PubKey;
use ElektronNet\Escrow\RawTransaction;
use ElektronNet\Escrow\WalletConnection;

$G = '0279be667ef9dcbbac55a06295ce870b07029bfcdb2dce28d959f2815b16f81798';
$G2 = '02c6047f9441ed7d6d3045406e95c07cd85c778e4b8cef3ca7abac09b95c709ee5';
$G3 = '02f9308a019258c31049344f85f89d5229b531c845836f99b08601f113bce036f9';
$G4 = '02e493dbf1c10d80f3581e4904930b1404cc6c13900ee0758474fa94abe8c4cd13';
$mainnet = NetworkParams::forNetwork('mainnet');
$testnet = NetworkParams::forNetwork('testnet');

// BIP173 vectors
$decoded = Bech32::decodeSegwit('bc', 'BC1QW508D6QEJXTDG4Y5R3ZARVARY0C5XW7KV8F3T4');
check('bech32 decode', $decoded !== null && $decoded[0] === 0 && bin2hex($decoded[1]) === '751e76e8199196d454941c45d1b3a323f1433bd6');
check('bech32 encode', Bech32::encodeSegwit('bc', 0, hex2bin('751e76e8199196d454941c45d1b3a323f1433bd6')) === 'bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t4');
check('bech32 rejects bad checksum', Bech32::decodeSegwit('bc', 'bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t5') === null);
check('bech32 rejects wrong hrp', Bech32::decodeSegwit('be', 'bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t4') === null);
$p2wsh = 'tb1qrp33g0q5c5txsp9arysrx4k6zdkfs4nce4xj0gdcccefvpysxf3q0sl5k7';
check('p2wsh address to script', Address::toScriptPubKeyHex($p2wsh, $testnet) === '00201863143c14c5166804bd19203356da136c985678cd4d27a1b8c6329604903262');
check('script to p2wsh address', Address::fromScriptPubKeyHex('00201863143c14c5166804bd19203356da136c985678cd4d27a1b8c6329604903262', $testnet) === $p2wsh);
check('p2pkh to script', Address::toScriptPubKeyHex('1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa', $mainnet) === '76a91462e907b15cbf27d5425399ebf6f0fb50ebb88f1888ac');
check('script to p2pkh', Address::fromScriptPubKeyHex('76a91462e907b15cbf27d5425399ebf6f0fb50ebb88f1888ac', $mainnet) === '1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa');
check('base58 rejects bad checksum', Address::toScriptPubKeyHex('1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNb', $mainnet) === null);

check('generator point is valid', PubKey::isValidCompressed($G));
check('off-curve x is invalid', !PubKey::isValidCompressed('02' . str_repeat('00', 31) . '05'));
check('uncompressed is invalid', !PubKey::isValidCompressed('04' . str_repeat('11', 64)));

// Escrow script with seller main/exit key G/2G and buyer main/exit key 3G/4G. Vector compiled
// independently with embit from
// wsh(or_d(multi(2,G,3G),or_i(and_v(v:pk(4G),after(1794431976)),and_v(v:pk(2G),after(1799615976)))))
$T1 = 1794431976;
$T2 = 1799615976;
$script = EscrowScript::witnessScriptHex($G, $G2, $G3, $G4, $T1, $T2);
check('script matches miniscript compiler', $script === '52210279be667ef9dcbbac55a06295ce870b07029bfcdb2dce28d959f2815b16f817982102f9308a019258c31049344f85f89d5229b531c845836f99b08601f113bce036f952ae7364632102e493dbf1c10d80f3581e4904930b1404cc6c13900ee0758474fa94abe8c4cd13ad04e8dbf46ab1672102c6047f9441ed7d6d3045406e95c07cd85c778e4b8cef3ca7abac09b95c709ee5ad04e8f5436bb16868');
check('script pubkey matches', EscrowScript::scriptPubKeyHex($script) === '00202bf9dfdd4c6ed19c70d3fb942f4a1fda52f42cfe2da34c37c707ffff91bdc453');
check('multi keys are sorted', EscrowScript::sortedKeys($G3, $G) === [$G, $G3]);
check('swapping roles changes the script', EscrowScript::witnessScriptHex($G3, $G4, $G, $G2, $T1, $T2) !== $script);
check('rejects a repeated key', throws(function () use ($G, $G2, $G3, $T1, $T2) { EscrowScript::witnessScriptHex($G, $G2, $G3, $G, $T1, $T2); }));
check('rejects the same key as main and exit', throws(function () use ($G, $G3, $G4, $T1, $T2) { EscrowScript::witnessScriptHex($G, $G, $G3, $G4, $T1, $T2); }));
check('rejects invalid key', throws(function () use ($G, $G2, $G3, $T1, $T2) { EscrowScript::witnessScriptHex($G, $G2, $G3, '02' . str_repeat('00', 31) . '05', $T1, $T2); }));
check('rejects refund after claim', throws(function () use ($G, $G2, $G3, $G4, $T1, $T2) { EscrowScript::witnessScriptHex($G, $G2, $G3, $G4, $T2, $T1); }));
check('rejects block-height locktime', throws(function () use ($G, $G2, $G3, $G4, $T2) { EscrowScript::witnessScriptHex($G, $G2, $G3, $G4, 800000, $T2); }));
$escrowAddress = EscrowScript::address($script, $testnet);
check('address matches script pubkey', Address::toScriptPubKeyHex($escrowAddress, $testnet) === EscrowScript::scriptPubKeyHex($script));

// Descriptor
check('descriptor checksum vector', Descriptor::checksum("pkh([d34db33f/44'/0'/0']xpub6ERApfZwUNrhLCkDtcHTcxd75RbzS1ed54G1LkBUHQVHQKqhMkhgbmJbZRkrgZw4koxb5JaHWkY4ALHY2grBGRjaDMzQLcgJvLJuZZvRcEL/1/*)") === 'ml40v0wf');
check('escrow descriptor body', Descriptor::escrowBody($G, $G2, $G3, $G4, $T1, $T2) === "wsh(or_d(multi(2,{$G},{$G3}),or_i(and_v(v:pk({$G4}),after({$T1})),and_v(v:pk({$G2}),after({$T2})))))");
check('descriptor has checksum', preg_match('/#[a-z0-9]{8}$/', Descriptor::escrow($G, $G2, $G3, $G4, $T1, $T2)) === 1);
$body = Descriptor::escrowBody($G, $G2, $G3, $G4, $T1, $T2);
preg_match_all('/0[23][0-9a-f]{64}/', $body, $found);
check('no public key appears twice', count($found[0]) === 4 && count(array_unique($found[0])) === 4);
$template = Descriptor::escrowBody($G, $G2, $G3, $G4, $T1, $T2, [$G => '<KEY_1>', $G2 => '<KEY_2>']);
check('own keys replaced', substr_count($template, '<KEY_1>') === 1 && substr_count($template, '<KEY_2>') === 1 && strpos($template, $G) === false && strpos($template, $G3) !== false);

// Release PSBT checks
$payout = Address::toScriptPubKeyHex('tb1qw508d6qejxtdg4y5r3zarvary0c5xw7kxpjzsx', $testnet);
$funding = [['txid' => str_repeat('ab', 32), 'vout' => 1, 'amountLep' => 1000000]];
$expected = ['witnessScriptHex' => $script, 'fundingOutputs' => $funding, 'payoutScriptPubKeyHex' => $payout, 'allowedPubKeys' => [$G, $G3], 'signerPubKey' => $G];
$psbt = makePsbt($script, $funding, $payout, 998600);
check('psbt round trip', Psbt::parse($psbt->toBase64())->toBase64() === $psbt->toBase64());
check('psbt hex accepted', Psbt::parse(bin2hex($psbt->toBinary()))->toBase64() === $psbt->toBase64());
check('parse rejects garbage', throws(function () { Psbt::parse('not a psbt'); }));
check('parse rejects truncated', throws(function () use ($psbt) { Psbt::parse(substr($psbt->toBase64(), 0, 40)); }));
$report = PsbtVerifier::inspect($psbt, $testnet);
check('inspect fee and output', $report['feeLep'] === 1400 && $report['outputs'][0]['address'] === 'tb1qw508d6qejxtdg4y5r3zarvary0c5xw7kxpjzsx');

$good = withSignature($psbt, $G);
check('valid release accepted', PsbtVerifier::assertReleasePsbt($good, $expected) === ['feeLep' => 1400, 'outputAmountLep' => 998600]);
check('unsigned rejected', throws(function () use ($psbt, $expected) { PsbtVerifier::assertReleasePsbt($psbt, $expected); }, RuntimeException::class));
check('other party signature rejected', throws(function () use ($psbt, $expected, $G3) { PsbtVerifier::assertReleasePsbt(withSignature($psbt, $G3), $expected); }, RuntimeException::class));
check('both signatures rejected for the seller upload', throws(function () use ($good, $expected, $G3) { PsbtVerifier::assertReleasePsbt(withSignature($good, $G3), $expected); }, RuntimeException::class));
check('exit key signature rejected', throws(function () use ($psbt, $expected, $G2) { PsbtVerifier::assertReleasePsbt(withSignature($psbt, $G2), $expected); }, RuntimeException::class));
check('bad sighash rejected', throws(function () use ($psbt, $expected, $G) { PsbtVerifier::assertReleasePsbt(withSignature($psbt, $G, "\x30\x06\x02\x01\x01\x02\x01\x01\x02"), $expected); }, RuntimeException::class));
check('wrong payout rejected', throws(function () use ($script, $funding, $expected, $G, $testnet) {
    $other = Address::toScriptPubKeyHex('tb1qrp33g0q5c5txsp9arysrx4k6zdkfs4nce4xj0gdcccefvpysxf3q0sl5k7', $testnet);
    PsbtVerifier::assertReleasePsbt(withSignature(makePsbt($script, $funding, $other, 998600), $G), $expected);
}, RuntimeException::class));
check('extra input rejected', throws(function () use ($script, $expected, $G, $payout) {
    $two = [['txid' => str_repeat('ab', 32), 'vout' => 1, 'amountLep' => 1000000], ['txid' => str_repeat('cd', 32), 'vout' => 0, 'amountLep' => 500000]];
    PsbtVerifier::assertReleasePsbt(withSignature(makePsbt($script, $two, $payout, 1400000), $G), $expected);
}, RuntimeException::class));
check('missing input rejected', throws(function () use ($script, $expected, $G, $payout) {
    $other = [['txid' => str_repeat('cd', 32), 'vout' => 0, 'amountLep' => 1000000]];
    PsbtVerifier::assertReleasePsbt(withSignature(makePsbt($script, $other, $payout, 998600), $G), $expected);
}, RuntimeException::class));
check('excessive fee rejected', throws(function () use ($script, $funding, $expected, $G, $payout) { PsbtVerifier::assertReleasePsbt(withSignature(makePsbt($script, $funding, $payout, 100000), $G), $expected); }, RuntimeException::class));
check('timestamp locktime rejected', throws(function () use ($script, $funding, $expected, $G, $payout, $T1) { PsbtVerifier::assertReleasePsbt(withSignature(makePsbt($script, $funding, $payout, 998600, $T1), $G), $expected); }, RuntimeException::class));
check('wrong witness script rejected', throws(function () use ($script, $funding, $expected, $G, $G2, $G3, $G4, $payout, $T1, $T2) {
    PsbtVerifier::assertReleasePsbt(withSignature(makePsbt(EscrowScript::witnessScriptHex($G, $G2, $G4, $G3, $T1, $T2), $funding, $payout, 998600), $G), $expected);
}, RuntimeException::class));

// Built PSBT
$builtB64 = PsbtBuilder::buildSpend($script, $funding, 'tb1qw508d6qejxtdg4y5r3zarvary0c5xw7kxpjzsx', 10, 0, $testnet);
$built = Psbt::parse($builtB64);
$builtReport = PsbtVerifier::inspect($built, $testnet);
check('built psbt pays the address minus the fee', $builtReport['outputs'][0]['address'] === 'tb1qw508d6qejxtdg4y5r3zarvary0c5xw7kxpjzsx' && $builtReport['feeLep'] > 0 && $builtReport['feeLep'] < 5000);
check('built psbt passes the release check once signed', PsbtVerifier::assertReleasePsbt(withSignature($built, $G), $expected)['feeLep'] === $builtReport['feeLep']);
$exit = Psbt::parse(PsbtBuilder::buildSpend($script, $funding, 'tb1qw508d6qejxtdg4y5r3zarvary0c5xw7kxpjzsx', 10, $T1, $testnet));
check('exit psbt carries the lock time and a non-final sequence', $exit->tx()->locktime() === $T1 && $exit->tx()->inputs()[0]['sequence'] === 0xfffffffe);
check('built psbt rejects a bad address', throws(function () use ($script, $funding, $testnet) { PsbtBuilder::buildSpend($script, $funding, 'nope', 10, 0, $testnet); }, InvalidArgumentException::class));
check('built psbt rejects a missing fee rate', throws(function () use ($script, $funding, $testnet) { PsbtBuilder::buildSpend($script, $funding, 'tb1qw508d6qejxtdg4y5r3zarvary0c5xw7kxpjzsx', 0, 0, $testnet); }, InvalidArgumentException::class));
check('built psbt rejects a fee that eats the amount', throws(function () use ($script, $testnet) {
    PsbtBuilder::buildSpend($script, [['txid' => str_repeat('ab', 32), 'vout' => 0, 'amountLep' => 5000]], 'tb1qw508d6qejxtdg4y5r3zarvary0c5xw7kxpjzsx', 100, 0, $testnet);
}, InvalidArgumentException::class));
check('built psbt needs outputs', throws(function () use ($script, $testnet) { PsbtBuilder::buildSpend($script, [], 'tb1qw508d6qejxtdg4y5r3zarvary0c5xw7kxpjzsx', 10, 0, $testnet); }, InvalidArgumentException::class));

// Raw transaction parsing
$spk = hex2bin(EscrowScript::scriptPubKeyHex($script));
$unsigned = new RawTransaction(2, [['txid' => str_repeat('cd', 32), 'vout' => 0, 'scriptSig' => '', 'sequence' => 0xfffffffd]], [['amountLep' => 1000000, 'scriptPubKey' => $spk]], 0, true);
$raw = $unsigned->serializeWithoutWitness();
$withWitness = substr($raw, 0, 4) . "\x00\x01" . substr($raw, 4, -4) . "\x02\x01\xaa\x01\xbb" . substr($raw, -4);
check('txid ignores witness', RawTransaction::fromHex(bin2hex($withWitness))->txid() === $unsigned->txid());

// Wallet connection
$masterXpub = 'xpub661MyMwAqRbcFiyme9xRZe855HWfYxvTcYoWpX1E8ZW8DGu35DbthdTxz222XRihFsxrdH4BCEe32DBRyKEerW8CUMAB8FDziiNyDG4ecgT';
$accountXpub = 'xpub6CUKWm4nWQEwWg7mBiA7PbAyvmzLb1DU898yUE9Zt78iPnFFc2YyodWtwqysPJbkEni56H2xFcZumxKHcmr8qVfAw5bwxq5YGRT97yeLxvC';
$hdKeys = json_encode([[
    'xpub' => $masterXpub,
    'has_private' => true,
    'descriptors' => [['desc' => 'wpkh([5a3469b6/84h/1370h/0h]' . $accountXpub . '/0/*)#00000000', 'active' => true]],
]], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
$connection = WalletConnection::parse($hdKeys, $mainnet);
check('hdkeys output yields the master key and its fingerprint', $connection['xpub'] === $masterXpub && $connection['fingerprint'] === '5a3469b6');
check('bare master key is accepted', WalletConnection::parse($masterXpub, $mainnet)['xpub'] === $masterXpub);
check('private key rejected', throws(function () use ($mainnet) {
    WalletConnection::parse('[{"xpub":"x","xprv":"xprv9s21ZrQH143K3QTDL4LXw2F7HEK3wJUD2nW2nRk4stbPy6cq3jPPqjiChkVvvNKmPGJxWUtg6LnF5kejMRNNU3TGtRBeJgk33yuGBxrMPHi"}]', $mainnet);
}, InvalidArgumentException::class));
check('text without a key rejected', throws(function () use ($mainnet) { WalletConnection::parse('hello', $mainnet); }, InvalidArgumentException::class));
check('account key alone rejected', throws(function () use ($accountXpub, $mainnet) { WalletConnection::parse($accountXpub, $mainnet); }, InvalidArgumentException::class));
check('wrong network rejected', throws(function () use ($masterXpub, $testnet) { WalletConnection::parse($masterXpub, $testnet); }, InvalidArgumentException::class));
check('several master keys rejected', throws(function () use ($masterXpub, $mainnet) {
    WalletConnection::parse($masterXpub . ' xpub661MyMwAqRbcFtXgS5sYJABqqG9YLmC4Q1Rdap9gSE8NqtwybGhePY2gZ29ESFjqJoCu1Rupje8YtGqsefD265TMg7usUDFdp6W1EGMcet8', $mainnet);
}, InvalidArgumentException::class));

$dealKeys = EscrowKeys::forDeal($masterXpub, 18, $mainnet);
check('escrow keys sit at m/1370/(2n) and m/1370/(2n+1)', $dealKeys['mainIndex'] === 36 && $dealKeys['exitIndex'] === 37);
check('escrow keys match the reference derivation', $dealKeys['main'] === '035041e89c3b3fb6f89de215dc9f145acead0f97c1124c63c02477fcb914762ff5' && $dealKeys['exit'] === '02a9ae660777ad176343bdc14341850a6f680fca2320f5b6fbccbbf4c7f9efffa7');
check('escrow keys reject deal number zero', throws(function () use ($masterXpub, $mainnet) { EscrowKeys::forDeal($masterXpub, 0, $mainnet); }, InvalidArgumentException::class));
check('escrow keys reject a non master key', throws(function () use ($accountXpub, $mainnet) { EscrowKeys::forDeal($accountXpub, 1, $mainnet); }, InvalidArgumentException::class));

finish();
