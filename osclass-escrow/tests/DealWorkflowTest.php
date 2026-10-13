<?php

// Walks one deal through the whole workflow in memory. Run: php osclass-escrow/tests/DealWorkflowTest.php

require __DIR__ . '/bootstrap.php';

use ElektronNet\Escrow\Address;
use ElektronNet\Escrow\DealException;
use ElektronNet\Escrow\DealStatus;
use ElektronNet\Escrow\DealWorkflow;
use ElektronNet\Escrow\NetworkParams;
use ElektronNet\Escrow\TimeoutPolicy;

$sellerKey = '0279be667ef9dcbbac55a06295ce870b07029bfcdb2dce28d959f2815b16f81798';
$sellerExit = '02c6047f9441ed7d6d3045406e95c07cd85c778e4b8cef3ca7abac09b95c709ee5';
$buyerKey = '02f9308a019258c31049344f85f89d5229b531c845836f99b08601f113bce036f9';
$buyerExit = '02e493dbf1c10d80f3581e4904930b1404cc6c13900ee0758474fa94abe8c4cd13';
$net = NetworkParams::forNetwork('testnet');
$payoutAddress = 'tb1qw508d6qejxtdg4y5r3zarvary0c5xw7kxpjzsx';
$now = 1700000000;
$flow = new DealWorkflow($net, TimeoutPolicy::defaults());

function rejected(callable $fn): bool
{
    return throws($fn, DealException::class);
}
function step(DealWorkflow $flow, array &$deal, string $action, string $role, array $input, int $now): void
{
    $deal = array_merge($deal, $flow->perform($action, $deal, $role, $input, $now));
}

// Both wallets are connected, so the escrow is fixed at creation
$sellerPair = ['main' => $sellerKey, 'exit' => $sellerExit];
$buyerPair = ['main' => $buyerKey, 'exit' => $buyerExit];
$bad = '02' . str_repeat('00', 31) . '05';
$deal = ['amount_lep' => 1000000] + $flow->create($sellerPair, $buyerPair, $now);
check('escrow fixed with both dates', $deal['status'] === DealStatus::AWAITING_PAYMENT && strpos($deal['escrow_address'], 'tb1q') === 0
    && $deal['buyer_refund_locktime'] === $now + 30 * 86400 && $deal['seller_release_locktime'] === $now + 60 * 86400);
check('create rejects an invalid key', rejected(function () use ($flow, $bad, $sellerExit, $buyerPair, $now) { $flow->create(['main' => $bad, 'exit' => $sellerExit], $buyerPair, $now); }));
check('create rejects equal main and exit key', rejected(function () use ($flow, $sellerKey, $buyerPair, $now) { $flow->create(['main' => $sellerKey, 'exit' => $sellerKey], $buyerPair, $now); }));
check('create rejects a shared key', rejected(function () use ($flow, $sellerPair, $sellerKey, $buyerExit, $now) { $flow->create($sellerPair, ['main' => $sellerKey, 'exit' => $buyerExit], $now); }));
check('only the buyer acts while payment is awaited', DealStatus::mayAct($deal, 'buyer') && !DealStatus::mayAct($deal, 'seller'));
check('cancel allowed before payment', $flow->perform('cancel', $deal, 'seller', [], $now)['status'] === DealStatus::CANCELLED);

check('chain: short payment ignored', $flow->advanceFromChain($deal, 500000, 5, 3) === []);
check('chain: seen', $flow->advanceFromChain($deal, 1000000, 0, 3) === ['status' => DealStatus::CONFIRMING]);
$confirming = array_merge($deal, ['status' => DealStatus::CONFIRMING]);
check('chain: still confirming', $flow->advanceFromChain($confirming, 1000000, 2, 3) === []);
check('chain: funded from awaiting payment', $flow->advanceFromChain($deal, 1000000, 3, 3) === ['status' => DealStatus::FUNDED]);
$deal = array_merge($deal, $flow->advanceFromChain($confirming, 1000000, 3, 3));
check('funded', $deal['status'] === DealStatus::FUNDED);
check('cancel refused after payment', rejected(function () use ($flow, $deal, $now) { $flow->perform('cancel', $deal, 'buyer', [], $now); }));

check('buyer cannot mark shipped', rejected(function () use ($flow, $deal, $now) { $flow->perform('seller_mark_shipped', $deal, 'buyer', [], $now); }));
step($flow, $deal, 'seller_mark_shipped', 'seller', [], $now);
check('refund not yet allowed', rejected(function () use ($flow, $deal, $now) { $flow->perform('buyer_mark_refunded', $deal, 'buyer', ['txid' => str_repeat('a', 64)], $now + 86400); }));
check('receipt refused close to refund time', rejected(function () use ($flow, $deal) { $flow->perform('buyer_mark_received', $deal, 'buyer', [], $deal['buyer_refund_locktime'] - 3600); }));
step($flow, $deal, 'buyer_mark_received', 'buyer', [], $now + 86400);
check('seller releases next', $deal['status'] === DealStatus::AWAITING_SELLER_RELEASE);

$funding = [['txid' => str_repeat('ab', 32), 'vout' => 0, 'amountLep' => 1000000]];
$payout = Address::toScriptPubKeyHex($payoutAddress, $net);
$unsigned = makePsbt($deal['witness_script_hex'], $funding, $payout, 998600);
check('release without known funding rejected', rejected(function () use ($flow, $deal, $unsigned, $sellerKey, $payoutAddress, $now) {
    $flow->perform('seller_submit_release', $deal, 'seller', ['psbt' => withSignature($unsigned, $sellerKey)->toBase64(), 'address' => $payoutAddress], $now);
}));
$deal['funding_outputs'] = json_encode($funding);
check('buyer cannot upload the release', rejected(function () use ($flow, $deal, $unsigned, $sellerKey, $payoutAddress, $now) {
    $flow->perform('seller_submit_release', $deal, 'buyer', ['psbt' => withSignature($unsigned, $sellerKey)->toBase64(), 'address' => $payoutAddress], $now);
}));
check('unsigned release rejected', rejected(function () use ($flow, $deal, $unsigned, $payoutAddress, $now) {
    $flow->perform('seller_submit_release', $deal, 'seller', ['psbt' => $unsigned->toBase64(), 'address' => $payoutAddress], $now);
}));
check('buyer signature on seller upload rejected', rejected(function () use ($flow, $deal, $unsigned, $buyerKey, $payoutAddress, $now) {
    $flow->perform('seller_submit_release', $deal, 'seller', ['psbt' => withSignature($unsigned, $buyerKey)->toBase64(), 'address' => $payoutAddress], $now);
}));
check('wrong payout address rejected', rejected(function () use ($flow, $deal, $unsigned, $sellerKey, $now) {
    $flow->perform('seller_submit_release', $deal, 'seller', ['psbt' => withSignature($unsigned, $sellerKey)->toBase64(), 'address' => 'tb1qrp33g0q5c5txsp9arysrx4k6zdkfs4nce4xj0gdcccefvpysxf3q0sl5k7'], $now);
}));
check('garbage rejected', rejected(function () use ($flow, $deal, $payoutAddress, $now) { $flow->perform('seller_submit_release', $deal, 'seller', ['psbt' => 'nonsense', 'address' => $payoutAddress], $now); }));
step($flow, $deal, 'seller_submit_release', 'seller', ['psbt' => withSignature($unsigned, $sellerKey)->toBase64(), 'address' => $payoutAddress], $now + 86400);
check('release stored, buyer next', $deal['status'] === DealStatus::AWAITING_BUYER_RELEASE && $deal['seller_payout_address'] === $payoutAddress && $deal['release_psbt_signed'] !== '');
check('bad txid rejected', rejected(function () use ($flow, $deal, $now) { $flow->perform('buyer_mark_released', $deal, 'buyer', ['txid' => 'xyz'], $now); }));
check('seller cannot close the release', rejected(function () use ($flow, $deal, $now) { $flow->perform('buyer_mark_released', $deal, 'seller', ['txid' => str_repeat('b', 64)], $now); }));
step($flow, $deal, 'buyer_mark_released', 'buyer', ['txid' => str_repeat('b', 64)], $now + 2 * 86400);
check('released', $deal['status'] === DealStatus::RELEASED && $deal['closing_txid'] === str_repeat('b', 64));
check('terminal is final', rejected(function () use ($flow, $deal, $now) { $flow->perform('cancel', $deal, 'buyer', [], $now); }));

// Timeout paths
$stuck = array_merge($deal, ['status' => DealStatus::FUNDED, 'closing_txid' => null]);
check('refund after T1', $flow->perform('buyer_mark_refunded', $stuck, 'buyer', ['txid' => str_repeat('c', 64)], $stuck['buyer_refund_locktime'])['status'] === DealStatus::REFUNDED);
check('seller cannot refund', rejected(function () use ($flow, $stuck) { $flow->perform('buyer_mark_refunded', $stuck, 'seller', ['txid' => str_repeat('c', 64)], $stuck['buyer_refund_locktime']); }));
check('claim before T2 rejected', rejected(function () use ($flow, $stuck) { $flow->perform('seller_mark_claimed', $stuck, 'seller', ['txid' => str_repeat('c', 64)], $stuck['buyer_refund_locktime']); }));
check('claim after T2', $flow->perform('seller_mark_claimed', $stuck, 'seller', ['txid' => str_repeat('c', 64)], $stuck['seller_release_locktime'])['status'] === DealStatus::CLAIMED);
check('refund from release stage after T1', $flow->perform('buyer_mark_refunded', array_merge($stuck, ['status' => DealStatus::AWAITING_BUYER_RELEASE]), 'buyer', ['txid' => str_repeat('c', 64)], $stuck['buyer_refund_locktime'])['status'] === DealStatus::REFUNDED);

finish();
