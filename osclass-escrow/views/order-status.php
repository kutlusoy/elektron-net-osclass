<?php if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');
/** @var array $deal */
/** @var string $role 'buyer' or 'seller' */
/** @var array|false $item */
/** @var float $amountElek */
/** @var int $requiredConfirmations */
/** @var array[] $events */
/** @var string|null $statusMessage */
/** @var string|null $errorMessage */
/** @var array{kind:string,psbt:string}|null $built */

use ElektronNet\Escrow\DealStatus;
use ElektronNet\Escrow\Descriptor;
use ElektronNet\Escrow\Psbt;
use ElektronNet\Escrow\PsbtVerifier;

$status = $deal['status'];
$isBuyer = $role === 'buyer';
$myTurn = DealStatus::mayAct($deal, $role);
$now = time();
$dealId = (int) $deal['pk_i_id'];
$t1 = (int) $deal['buyer_refund_locktime'];
$t2 = (int) $deal['seller_release_locktime'];
$amountText = elektron_escrow_format_amount($amountElek) . ' ELEK';
$fundsAtRisk = in_array($status, DealStatus::FUNDS_AT_RISK, true);
$funding = elektron_escrow_deal_funding_list($deal);
$fundingTotalLep = array_sum(array_column($funding, 'amountLep'));
$fundingTotalElek = elektron_escrow_plain_amount($fundingTotalLep / 100000000);

$path = [
    DealStatus::AWAITING_PAYMENT, DealStatus::CONFIRMING, DealStatus::FUNDED, DealStatus::SHIPPED,
    DealStatus::AWAITING_SELLER_RELEASE, DealStatus::AWAITING_BUYER_RELEASE, DealStatus::RELEASED,
];
$position = array_search($status, $path, true);

$inDispute = in_array($status, [DealStatus::DISPUTED, DealStatus::RULED, DealStatus::AWAITING_ARBITER_SIGNATURE], true);
$statusOrder = [
    DealStatus::AWAITING_PAYMENT => 0, DealStatus::CONFIRMING => 1, DealStatus::FUNDED => 2, DealStatus::SHIPPED => 3,
    DealStatus::AWAITING_SELLER_RELEASE => 4, DealStatus::AWAITING_BUYER_RELEASE => 5,
];
$stage = $statusOrder[$status] ?? 6;
$prepared = !empty($deal[$role . '_prepared_at']);
$prepareLabel = __('Prepare your wallet for this deal (once, before you sign)', ELEKTRON_ESCROW_DOMAIN);
$checklist = $isBuyer ? [
    ['prepare', $prepareLabel, $prepared],
    ['pay', __('Pay the escrow address with the QR code', ELEKTRON_ESCROW_DOMAIN), $stage >= 1],
    ['receive', __('Confirm receipt of the goods', ELEKTRON_ESCROW_DOMAIN), $stage >= 4],
    ['release', __('Check the seller\'s release, sign it and send it', ELEKTRON_ESCROW_DOMAIN), $stage >= 6],
] : [
    ['prepare', $prepareLabel, $prepared],
    ['ship', __('Ship the goods once the payment is confirmed, then confirm shipping', ELEKTRON_ESCROW_DOMAIN), $stage >= 3],
    ['release', __('Create and sign the release transaction', ELEKTRON_ESCROW_DOMAIN), $stage >= 5],
];
$currentKey = !$prepared ? 'prepare' : [
    DealStatus::AWAITING_PAYMENT => 'pay',
    DealStatus::FUNDED => 'ship',
    DealStatus::SHIPPED => 'receive',
    DealStatus::AWAITING_SELLER_RELEASE => 'release',
    DealStatus::AWAITING_BUYER_RELEASE => 'release',
][$status] ?? null;
$stepNumber = function (string $key) use ($checklist): int {
    foreach ($checklist as $index => $step) {
        if ($step[0] === $key) {
            return $index + 1;
        }
    }

    return 0;
};

$publicDescriptor = null;
$descriptorTemplate = null;
if ($deal['witness_script_hex'] !== null) {
    $publicDescriptor = Descriptor::escrow($deal['seller_pubkey'], $deal['seller_exit_pubkey'], $deal['buyer_pubkey'], $deal['buyer_exit_pubkey'], $t1, $t2, $deal['arbiter_pubkey'] ?: null);
    $descriptorTemplate = elektron_escrow_private_descriptor($deal, $role);
}
$ownWallet = EscrowWalletDAO::newInstance()->forUser((int) $deal['fk_i_' . $role . '_id']);

$showShipping = !$isBuyer && in_array($status, [
    DealStatus::FUNDED, DealStatus::SHIPPED, DealStatus::AWAITING_SELLER_RELEASE,
    DealStatus::AWAITING_BUYER_RELEASE, DealStatus::RELEASED, DealStatus::CLAIMED,
], true);

$inputsJson = function (?int $sequence) use ($funding): string {
    $inputs = [];
    foreach ($funding as $output) {
        $input = ['txid' => $output['txid'], 'vout' => $output['vout']];
        if ($sequence !== null) {
            $input['sequence'] = $sequence;
        }
        $inputs[] = $input;
    }

    return json_encode($inputs, JSON_UNESCAPED_SLASHES);
};
$createCommand = function (string $address, int $locktime, ?int $sequence) use ($inputsJson, $fundingTotalElek): string {
    return 'walletcreatefundedpsbt \'' . $inputsJson($sequence) . '\' \'[{"' . $address . '":' . $fundingTotalElek . '}]\' ' . $locktime
        . ' \'{"add_inputs":false,"subtractFeeFromOutputs":[0],"fee_rate":10}\'';
};

$help = 'elektron_escrow_help';
$consoleHelp = function (string $title, array $lines) use ($help): void {
    echo '<details><summary>' . osc_esc_html($title) . $help('console') . '</summary>';
    echo elektron_escrow_where_console();
    foreach ($lines as $line) {
        echo '<p>' . $line . '</p>';
    }
    echo '</details>';
};
$code = function (string $text): string {
    return '<code class="elektron-escrow-code">' . osc_esc_html($text) . '</code>';
};
$why = function (string $text): string {
    return '<p class="elektron-escrow-why">' . osc_esc_html($text) . '</p>';
};

$result = 'elektron_escrow_result_block';
$cmdStep = function (string $title, string $text, string $command, ?string $resultText = null, ?string $helpKey = null) use ($code, $result, $help): string {
    return '<strong>' . osc_esc_html($title) . '</strong>' . ($helpKey !== null ? $help($helpKey) : '') . '<br />' . osc_esc_html($text) . $code($command) . ($resultText !== null ? $result($resultText) : '');
};

$psbtHelper = function (string $kind, string $defaultAddress) use ($deal, $dealId, $built, $why, $help): void {
    $titles = [
        'release' => __('Create the release PSBT for me', ELEKTRON_ESCROW_DOMAIN),
        'ruled' => __('Create the PSBT for me', ELEKTRON_ESCROW_DOMAIN),
        'refund' => __('Create the refund PSBT for me', ELEKTRON_ESCROW_DOMAIN),
        'claim' => __('Create the claim PSBT for me', ELEKTRON_ESCROW_DOMAIN),
    ];
    echo '<h4>' . osc_esc_html(__('Option 1: let this page create the PSBT', ELEKTRON_ESCROW_DOMAIN)) . $help('psbt') . '</h4>';
    echo $why(__('You get an unsigned transaction that you load into your wallet. Your wallet shows what it signs. Check the address and the amount there before you sign.', ELEKTRON_ESCROW_DOMAIN));
    elektron_escrow_action_form($deal, 'build_' . $kind . '_psbt', $titles[$kind], [
        'address' => ['label' => __('Address of your own wallet that receives the money', ELEKTRON_ESCROW_DOMAIN), 'help' => 'payout', 'value' => $defaultAddress],
        'fee_rate' => ['label' => __('Fee rate (lep per byte)', ELEKTRON_ESCROW_DOMAIN), 'help' => 'fee_rate', 'value' => '10'],
    ]);
    if ($built !== null && $built['kind'] === $kind) {
        elektron_escrow_copy_block(__('Unsigned PSBT', ELEKTRON_ESCROW_DOMAIN), $built['psbt'], 'elektron-escrow-built-' . $dealId, $kind . '-' . $dealId . '.psbt', 'psbt');
        echo '<details><summary>' . osc_esc_html(__('How do I sign it in the node wallet window?', ELEKTRON_ESCROW_DOMAIN)) . '</summary>'
            . '<p>' . osc_esc_html(__('Copy the PSBT, then in the node wallet use the menu File, "Load PSBT from clipboard" (or "from file" with the downloaded file). A PSBT window opens. Check the output and the amount, then click "Sign Tx". Afterwards "Copy to clipboard" gives you the signed PSBT. Menu names can differ slightly between versions.', ELEKTRON_ESCROW_DOMAIN)) . '</p>'
            . '</details>';
    }
    echo '<h4>' . osc_esc_html(__('Option 2: commands in the console', ELEKTRON_ESCROW_DOMAIN)) . '</h4>';
};

elektron_escrow_table_style();
?>
<style>
    .elektron-escrow-steps { list-style: none; padding: 0; margin: 1em 0; display: flex; flex-wrap: wrap; gap: 6px; }
    .elektron-escrow-steps li { padding: 4px 10px; border: 1px solid #ccc; border-radius: 12px; font-size: 0.85em; color: #777; }
    .elektron-escrow-steps li.done { color: #2a7a2a; border-color: #2a7a2a; }
    .elektron-escrow-steps li.current { background: #fff3cd; border-color: #d4a017; color: #000; font-weight: bold; }
    .elektron-escrow-next { background: #eef6ff; border: 1px solid #9cc4f0; border-radius: 4px; padding: 10px 14px; margin: 1em 0; }
    .elektron-escrow-order textarea { width: 100%; box-sizing: border-box; }
    .elektron-escrow-order details { margin: 0.5em 0; }
    .elektron-escrow-todo { margin: 1em 0; padding-left: 1.6em; }
    .elektron-escrow-todo li { padding: 3px 0; }
    .elektron-escrow-todo li.done { color: #2a7a2a; text-decoration: line-through; }
    .elektron-escrow-todo li.current { font-weight: bold; }
    .elektron-escrow-required { background: #b00020; color: #fff; border-radius: 3px; padding: 0 6px; font-size: 0.8em; font-weight: normal; text-decoration: none; display: inline-block; }
    .elektron-escrow-events { list-style: none; padding: 0; }
    .elektron-escrow-events li { padding: 4px 0; border-bottom: 1px solid #eee; }
</style>

<div class="elektron-escrow-order">
    <h1><?php _e('Elektron order', ELEKTRON_ESCROW_DOMAIN); ?> #<?php echo $dealId; ?></h1>

    <details>
        <summary><?php _e('How does this work? (short explanation)', ELEKTRON_ESCROW_DOMAIN); ?></summary>
        <p><?php _e('The buyer\'s payment goes into an escrow address that only the two of you can open together. Every transaction is created and signed in your own wallet. This website only passes information between you and shows you where the deal stands. It never holds a key and cannot move any money.', ELEKTRON_ESCROW_DOMAIN); ?></p>
        <p><?php _e('You always do one step at a time. When it is the other person\'s turn, you wait. If something goes wrong, the escrow address has built-in exits: after the refund date the buyer can take the money back alone, after the claim date the seller can take it alone.', ELEKTRON_ESCROW_DOMAIN); ?></p>
        <p><?php _e('Where a step needs your wallet, a "?" explains the term and a box shows the exact commands for the Elektron Net node wallet.', ELEKTRON_ESCROW_DOMAIN); ?></p>
    </details>

    <table class="elektron-escrow-table">
        <tr>
            <td><?php _e('Item', ELEKTRON_ESCROW_DOMAIN); ?></td>
            <td>
                <?php if ($item !== false) { ?>
                    <a href="<?php echo osc_esc_html(osc_item_url_from_item($item)); ?>"><?php echo osc_esc_html($item['s_title']); ?></a>
                <?php } else { ?>
                    <?php _e('(listing removed)', ELEKTRON_ESCROW_DOMAIN); ?>
                <?php } ?>
            </td>
        </tr>
        <tr><td><?php _e('Amount', ELEKTRON_ESCROW_DOMAIN); ?></td><td><?php echo osc_esc_html($amountText); ?></td></tr>
        <tr><td><?php _e('Your role', ELEKTRON_ESCROW_DOMAIN); ?></td><td><?php echo $isBuyer ? osc_esc_html(__('Buyer', ELEKTRON_ESCROW_DOMAIN)) : osc_esc_html(__('Seller', ELEKTRON_ESCROW_DOMAIN)); ?></td></tr>
        <tr><td><?php _e('Status', ELEKTRON_ESCROW_DOMAIN); ?><?php echo $help('status'); ?></td><td class="elektron-escrow-status" data-status="<?php echo osc_esc_html($status); ?>"><?php echo osc_esc_html(elektron_escrow_status_label($status)); ?></td></tr>
        <?php if ($t1 > 0) { ?>
            <tr><td><?php _e('Buyer can reclaim after', ELEKTRON_ESCROW_DOMAIN); ?><?php echo $help('locktimes'); ?></td><td><?php echo osc_esc_html(date('Y-m-d H:i T', $t1)); ?></td></tr>
            <tr><td><?php _e('Seller can claim after', ELEKTRON_ESCROW_DOMAIN); ?><?php echo $help('locktimes'); ?></td><td><?php echo osc_esc_html(date('Y-m-d H:i T', $t2)); ?></td></tr>
        <?php } ?>
        <?php if ($deal['closing_txid'] !== null) { ?>
            <?php $closingLink = elektron_escrow_explorer_url('tx', $deal['closing_txid']); ?>
            <tr><td><?php _e('Closing transaction (as reported)', ELEKTRON_ESCROW_DOMAIN); ?><?php echo $help('txid'); ?></td><td><?php echo $closingLink !== null ? '<a href="' . osc_esc_html($closingLink) . '" target="_blank" rel="noopener noreferrer">' . osc_esc_html($deal['closing_txid']) . '</a>' : osc_esc_html($deal['closing_txid']); ?></td></tr>
        <?php } ?>
    </table>

    <?php if ($position !== false) { ?>
        <ol class="elektron-escrow-steps">
            <?php foreach ($path as $index => $step) { ?>
                <li class="<?php echo $index < $position ? 'done' : ($index === $position ? 'current' : ''); ?>"><?php echo osc_esc_html(elektron_escrow_status_label($step)); ?></li>
            <?php } ?>
        </ol>
    <?php } ?>

    <?php if ($statusMessage !== null) { ?>
        <p class="elektron-escrow-success"><?php echo osc_esc_html($statusMessage); ?></p>
    <?php } ?>
    <?php if ($errorMessage !== null) { ?>
        <p class="elektron-escrow-error"><?php echo osc_esc_html($errorMessage); ?></p>
    <?php } ?>

    <div class="elektron-escrow-next"><strong><?php _e('Next step', ELEKTRON_ESCROW_DOMAIN); ?>:</strong> <?php echo osc_esc_html(elektron_escrow_next_step($deal, $role)); ?></div>

    <?php if ($inDispute || $deal['dispute_opened_at'] !== null) { ?>
        <div class="elektron-escrow-panel elektron-escrow-dispute">
            <h3><?php _e('Dispute', ELEKTRON_ESCROW_DOMAIN); ?></h3>
            <p><?php printf(osc_esc_html(__('Opened by the %1$s on %2$s.', ELEKTRON_ESCROW_DOMAIN)), osc_esc_html($deal['dispute_opened_by'] === 'buyer' ? __('buyer', ELEKTRON_ESCROW_DOMAIN) : __('seller', ELEKTRON_ESCROW_DOMAIN)), osc_esc_html((string) $deal['dispute_opened_at'])); ?></p>
            <p><?php echo nl2br(osc_esc_html((string) $deal['dispute_reason'])); ?></p>
            <?php if (!empty($deal['ruling'])) { ?>
                <p><strong><?php printf(osc_esc_html(__('Decision of the arbiter: the money goes to the %s.', ELEKTRON_ESCROW_DOMAIN)), osc_esc_html($deal['ruling'] === 'buyer' ? __('buyer', ELEKTRON_ESCROW_DOMAIN) : __('seller', ELEKTRON_ESCROW_DOMAIN))); ?></strong></p>
                <p><?php echo nl2br(osc_esc_html((string) $deal['ruling_note'])); ?></p>
            <?php } ?>
        </div>
    <?php } ?>

    <?php if (!DealStatus::isTerminal($status) && !$inDispute) { ?>
        <p><strong><?php _e('Your steps. Every step is required, in this order:', ELEKTRON_ESCROW_DOMAIN); ?></strong></p>
        <ol class="elektron-escrow-todo">
            <?php foreach ($checklist as $step) { ?>
                <li class="<?php echo $step[2] ? 'done' : ($step[0] === $currentKey ? 'current' : ''); ?>"><?php echo osc_esc_html($step[1]); ?> <span class="elektron-escrow-required"><?php _e('required', ELEKTRON_ESCROW_DOMAIN); ?></span><?php if ($step[2]) { ?> <span class="elektron-escrow-done"><?php _e('Done', ELEKTRON_ESCROW_DOMAIN); ?></span><?php } ?></li>
            <?php } ?>
        </ol>
    <?php } ?>

    <?php if ($deal['escrow_address'] !== null) { ?>
        <div class="elektron-escrow-panel">
            <p><strong><?php _e('Escrow address', ELEKTRON_ESCROW_DOMAIN); ?></strong><?php echo $help('escrow'); ?></p>
            <?php echo $code($deal['escrow_address']); ?>
            <?php $addressLink = elektron_escrow_explorer_url('address', $deal['escrow_address']); ?>
            <?php if ($addressLink !== null) { ?>
                <p><a href="<?php echo osc_esc_html($addressLink); ?>" target="_blank" rel="noopener noreferrer"><?php _e('Open in block explorer', ELEKTRON_ESCROW_DOMAIN); ?></a></p>
            <?php } ?>
            <details>
                <summary><?php _e('Escrow descriptor (public)', ELEKTRON_ESCROW_DOMAIN); ?><?php echo $help('descriptor'); ?></summary>
                <?php echo $code($publicDescriptor); ?>
            </details>
            <p class="text-muted"><?php _e('This page only shows what the two parties submitted. Always check addresses, amounts and transactions in your own wallet.', ELEKTRON_ESCROW_DOMAIN); ?><?php echo $help('verify'); ?></p>
        </div>
    <?php } ?>

    <?php if ($deal['escrow_address'] !== null && !DealStatus::isTerminal($status) && $descriptorTemplate !== null) { ?>
        <details class="elektron-escrow-panel"<?php echo $prepared ? '' : ' open'; ?>>
            <summary><strong><?php echo $stepNumber('prepare'); ?>. <?php echo osc_esc_html($prepareLabel); ?></strong> <span class="elektron-escrow-required"><?php _e('required', ELEKTRON_ESCROW_DOMAIN); ?></span><?php if ($prepared) { ?> <span class="elektron-escrow-done"><?php printf(osc_esc_html(__('Done before, %s', ELEKTRON_ESCROW_DOMAIN)), osc_esc_html(substr((string) $deal[$role . '_prepared_at'], 0, 10))); ?></span><?php } ?><?php echo $help('check_address'); ?></summary>
            <?php if (!empty($ownWallet['s_wallet_name'])) { echo $why(sprintf(__('Choose the wallet "%s" in the dropdown at the top of the console.', ELEKTRON_ESCROW_DOMAIN), $ownWallet['s_wallet_name'])); } ?>
            <?php $consoleHelp(__('Commands for the node wallet console', ELEKTRON_ESCROW_DOMAIN), [
                $cmdStep(__('1. Get your wallet key', ELEKTRON_ESCROW_DOMAIN), __('Run this command. Copy the key from the result. Keep it for steps 2 and 3 and never show it to anyone.', ELEKTRON_ESCROW_DOMAIN), "gethdkeys '{\"active_only\":true,\"private\":true}'", '[{"xpub": "xpub661My...", "has_private": true, "xprv": "<COPY_XPRV>", ...}]', 'private_key'),
                $cmdStep(__('2. Get the checksum', ELEKTRON_ESCROW_DOMAIN), __('In the command below replace <PASTE_XPRV> with <COPY_XPRV> from step 1 (it appears twice) and run it. Do not change anything else: the numbers after <PASTE_XPRV> are fixed by this page and have nothing to do with the coin type of your wallet.', ELEKTRON_ESCROW_DOMAIN), 'getdescriptorinfo "' . $descriptorTemplate . '"', '{"descriptor": "(ignore this line)", "checksum": "<COPY_CHECKSUM>", ...}'),
                $cmdStep(__('3. Import', ELEKTRON_ESCROW_DOMAIN), __('In the command below replace <PASTE_XPRV> with <COPY_XPRV> (twice) and <PASTE_CHECKSUM> with <COPY_CHECKSUM> from step 2. If the payment is already confirmed, write 0 instead of now.', ELEKTRON_ESCROW_DOMAIN), 'importdescriptors \'[{"desc":"' . $descriptorTemplate . '#<PASTE_CHECKSUM>","timestamp":"now"}]\'', '[{"success": true, "warnings": ["Not all private keys provided. ..."]}]'),
                $cmdStep(__('4. Check', ELEKTRON_ESCROW_DOMAIN), __('Run this command. The result must show "ismine": true and "solvable": true. The warning "Not all private keys provided" in step 3 is normal: the other party\'s keys are not yours.', ELEKTRON_ESCROW_DOMAIN), 'getaddressinfo "' . $deal['escrow_address'] . '"'),
            ]); ?>
            <?php if ($prepared) { ?>
                <p class="elektron-escrow-success"><?php printf(osc_esc_html(__('Confirmed on %s. You can come back to these commands at any time.', ELEKTRON_ESCROW_DOMAIN)), osc_esc_html((string) $deal[$role . '_prepared_at'])); ?></p>
            <?php } else { ?>
                <form method="post" action="<?php echo osc_esc_html(elektron_escrow_deal_url($deal)); ?>">
                    <?php echo osc_csrf_token_form(); ?>
                    <input type="hidden" name="deal_action" value="confirm_prepared" />
                    <p><label><input type="checkbox" name="confirmed" value="1" required="required" />
                        <?php _e('Yes, I have done all four steps above and my wallet shows "ismine": true and "solvable": true for the escrow address. I understand that otherwise I cannot sign for this escrow and my money can get stuck.', ELEKTRON_ESCROW_DOMAIN); ?></label></p>
                    <input type="submit" class="btn btn-primary" value="<?php echo osc_esc_html(__('Confirm and unlock the next steps', ELEKTRON_ESCROW_DOMAIN)); ?>" />
                </form>
            <?php } ?>
        </details>
    <?php } ?>

    <?php if ($myTurn && !$prepared) { ?>
        <p class="elektron-escrow-error"><?php _e('The next steps are locked until you confirm step 1 above.', ELEKTRON_ESCROW_DOMAIN); ?></p>
    <?php } ?>

    <?php if ($myTurn && $prepared) { ?>
        <div class="elektron-escrow-panel">
        <?php if ($currentKey !== null && $stepNumber($currentKey) > 0) { ?>
            <h3><?php printf(osc_esc_html(__('Step %d', ELEKTRON_ESCROW_DOMAIN)), $stepNumber($currentKey)); ?>: <?php echo osc_esc_html($checklist[$stepNumber($currentKey) - 1][1]); ?> <span class="elektron-escrow-required"><?php _e('required', ELEKTRON_ESCROW_DOMAIN); ?></span></h3>
        <?php } ?>
        <?php switch ($status) {
            case DealStatus::AWAITING_PAYMENT:
                if (empty($deal['escrow_address'])) {
                    echo '<p class="elektron-escrow-error">' . osc_esc_html(__('The escrow address of this order was never created. Cancel it and start again from the listing.', ELEKTRON_ESCROW_DOMAIN)) . '</p>';
                    break;
                }
                $paymentUri = elektron_escrow_payment_uri($deal['escrow_address'], $amountElek, $dealId);
                echo $why(__('Why: the payment is a normal transaction from your wallet to the escrow address. From there it can only move with both signatures, or through the exits after the refund or claim date.', ELEKTRON_ESCROW_DOMAIN));
                echo '<p><strong>' . osc_esc_html(sprintf(__('Send %s to the escrow address', ELEKTRON_ESCROW_DOMAIN), $amountText)) . '</strong></p>';
                echo elektron_escrow_qr_image($paymentUri, $paymentUri);
                echo '<p><a class="btn btn-primary" href="' . osc_esc_html($paymentUri) . '">' . osc_esc_html(__('Open in my wallet', ELEKTRON_ESCROW_DOMAIN)) . '</a></p>';
                echo $code($deal['escrow_address']);
                echo '<p>' . osc_esc_html(__('Scan the QR code with your wallet and pay the full amount in one transaction. This page checks the block explorer whenever you open or reload it, and the status moves on by itself. You can also press "Check the chain now".', ELEKTRON_ESCROW_DOMAIN)) . '</p>';
                break;

            case DealStatus::FUNDED:
                if ($t1 - $now < 14 * 86400) {
                    echo '<p class="elektron-escrow-error">' . osc_esc_html(__('Warning: the buyer can take the money back from the refund date shown above, which is close. Think twice before you ship.', ELEKTRON_ESCROW_DOMAIN)) . '</p>';
                }
                echo $why(__('Why: ship only when your own wallet shows the confirmed payment at the escrow address. The money is then locked for you.', ELEKTRON_ESCROW_DOMAIN));
                elektron_escrow_action_form($deal, 'seller_mark_shipped', __('I have shipped the goods', ELEKTRON_ESCROW_DOMAIN), [], __('Only confirm once the goods are shipped and your own wallet shows the confirmed payment.', ELEKTRON_ESCROW_DOMAIN));
                break;

            case DealStatus::SHIPPED:
                echo $why(__('Why: confirming receipt lets the seller prepare the release. Only the buyer\'s own signature, at the very end, actually moves the money.', ELEKTRON_ESCROW_DOMAIN));
                elektron_escrow_action_form($deal, 'buyer_mark_received', __('I have received the goods', ELEKTRON_ESCROW_DOMAIN), [], __('Only confirm if you received the goods as agreed.', ELEKTRON_ESCROW_DOMAIN));
                break;

            case DealStatus::AWAITING_SELLER_RELEASE:
                echo $why(__('Why: you create the release transaction in your own wallet, paying yourself, and sign it first. The buyer then checks it and has the final say with the second signature.', ELEKTRON_ESCROW_DOMAIN));
                if ($funding === []) {
                    echo '<p>' . osc_esc_html(__('The payment outputs are not known yet. Use "Check the chain now" below.', ELEKTRON_ESCROW_DOMAIN)) . '</p>';
                    break;
                }
                $psbtHelper('release', (string) $deal['seller_payout_address']);
                $consoleHelp(__('How do I create and sign the release? (node wallet console)', ELEKTRON_ESCROW_DOMAIN), [
                    $cmdStep(__('1. Create the transaction', ELEKTRON_ESCROW_DOMAIN), __('In the command below replace <YOUR_ADDRESS> with an address of your own wallet and run it. The fee is taken from the amount, 10 is the fee in lep per byte.', ELEKTRON_ESCROW_DOMAIN), $createCommand('<YOUR_ADDRESS>', 0, null), '{"psbt": "<COPY_PSBT_1>", "fee": 0.000016, "changepos": -1}', 'psbt'),
                    $cmdStep(__('2. Sign it', ELEKTRON_ESCROW_DOMAIN), __('In the command below replace <PASTE_PSBT_1> with <COPY_PSBT_1> and run it. "complete": false is correct, the buyer signs last.', ELEKTRON_ESCROW_DOMAIN), 'walletprocesspsbt "<PASTE_PSBT_1>"', '{"psbt": "<COPY_PSBT_2>", "complete": false}', 'sign'),
                    osc_esc_html(__('3. Paste <COPY_PSBT_2> into the field "Signed release transaction (PSBT)" below and your address into the field above it.', ELEKTRON_ESCROW_DOMAIN)),
                ]);
                elektron_escrow_action_form($deal, 'seller_submit_release', __('Submit my signed release', ELEKTRON_ESCROW_DOMAIN), [
                    'address' => ['label' => __('Paste here: the address you used in step 1', ELEKTRON_ESCROW_DOMAIN), 'help' => 'payout', 'value' => (string) $deal['seller_payout_address']],
                    'psbt' => ['label' => __('Paste here: <COPY_PSBT_2> from step 2 (signed release transaction)', ELEKTRON_ESCROW_DOMAIN), 'type' => 'textarea', 'help' => 'psbt'],
                ]);
                echo elektron_escrow_howto('address');
                break;

            case DealStatus::AWAITING_BUYER_RELEASE:
                echo $why(__('Why: you decide. Check in your wallet where the money goes. Only if everything is right, add your signature and broadcast.', ELEKTRON_ESCROW_DOMAIN));
                $summary = PsbtVerifier::inspect(Psbt::parse($deal['release_psbt_signed']), elektron_escrow_network_params());
                echo '<p><strong>' . osc_esc_html(__('The seller\'s release pays', ELEKTRON_ESCROW_DOMAIN)) . ':</strong> '
                    . osc_esc_html(elektron_escrow_format_amount($summary['outputs'][0]['amountLep'] / 100000000)) . ' ELEK '
                    . osc_esc_html(__('to', ELEKTRON_ESCROW_DOMAIN)) . ' ' . osc_esc_html((string) $summary['outputs'][0]['address'])
                    . ' (' . osc_esc_html(__('fee', ELEKTRON_ESCROW_DOMAIN)) . ': ' . osc_esc_html((string) $summary['feeLep']) . ' lep)' . $help('verify') . '</p>';
                elektron_escrow_copy_block(__('Release transaction signed by the seller (PSBT)', ELEKTRON_ESCROW_DOMAIN), $deal['release_psbt_signed'], 'elektron-escrow-psbt-' . $dealId, 'release-' . $dealId . '.psbt', 'psbt');
                $consoleHelp(__('How do I check, sign and broadcast it? (node wallet console)', ELEKTRON_ESCROW_DOMAIN), [
                    $cmdStep(__('1. Look at it in your wallet', ELEKTRON_ESCROW_DOMAIN), __('In the command below replace <PASTE_SELLER_PSBT> with the text of the box above (button Copy) and run it. Check that the output goes to the address and the amount shown above.', ELEKTRON_ESCROW_DOMAIN), 'decodepsbt "<PASTE_SELLER_PSBT>"', null, 'verify'),
                    $cmdStep(__('2. Sign it', ELEKTRON_ESCROW_DOMAIN), __('In the command below replace <PASTE_SELLER_PSBT> with the same text and run it. Now "complete": true is expected.', ELEKTRON_ESCROW_DOMAIN), 'walletprocesspsbt "<PASTE_SELLER_PSBT>"', '{"psbt": "<COPY_PSBT_2>", "complete": true}', 'sign'),
                    $cmdStep(__('3. Finish it', ELEKTRON_ESCROW_DOMAIN), __('In the command below replace <PASTE_PSBT_2> with <COPY_PSBT_2> and run it.', ELEKTRON_ESCROW_DOMAIN), 'finalizepsbt "<PASTE_PSBT_2>"', '{"hex": "<COPY_HEX>", "complete": true}', 'finalize'),
                    $cmdStep(__('4. Send it', ELEKTRON_ESCROW_DOMAIN), __('In the command below replace <PASTE_HEX> with <COPY_HEX> and run it. Only now money moves.', ELEKTRON_ESCROW_DOMAIN), 'sendrawtransaction "<PASTE_HEX>"', '<COPY_TXID>', 'broadcast'),
                    osc_esc_html(__('5. Paste <COPY_TXID> (64 characters) into the field "Transaction id of the release" below.', ELEKTRON_ESCROW_DOMAIN)),
                ]);
                elektron_escrow_action_form($deal, 'buyer_mark_released', __('I have broadcast the release', ELEKTRON_ESCROW_DOMAIN), [
                    'txid' => ['label' => __('Paste here: <COPY_TXID> from step 4', ELEKTRON_ESCROW_DOMAIN), 'help' => 'txid'],
                ]);
                break;

            case DealStatus::RULED:
                echo $why(__('Why: the arbiter decided in your favour. You prepare the transaction that pays you and sign it. The arbiter then adds the second signature and sends it.', ELEKTRON_ESCROW_DOMAIN));
                if ($funding === []) {
                    echo '<p>' . osc_esc_html(__('The payment outputs are not known yet. Use "Check the chain now" below.', ELEKTRON_ESCROW_DOMAIN)) . '</p>';
                    break;
                }
                $psbtHelper('ruled', (string) ($isBuyer ? '' : $deal['seller_payout_address']));
                $consoleHelp(__('How do I create and sign it? (node wallet console)', ELEKTRON_ESCROW_DOMAIN), [
                    $cmdStep(__('1. Create the transaction', ELEKTRON_ESCROW_DOMAIN), __('In the command below replace <YOUR_ADDRESS> with an address of your own wallet and run it. The fee is taken from the amount, 10 is the fee in lep per byte.', ELEKTRON_ESCROW_DOMAIN), $createCommand('<YOUR_ADDRESS>', 0, null), '{"psbt": "<COPY_PSBT_1>", "fee": 0.000016, "changepos": -1}', 'psbt'),
                    $cmdStep(__('2. Sign it', ELEKTRON_ESCROW_DOMAIN), __('In the command below replace <PASTE_PSBT_1> with <COPY_PSBT_1> and run it. "complete": false is correct, the arbiter signs next.', ELEKTRON_ESCROW_DOMAIN), 'walletprocesspsbt "<PASTE_PSBT_1>"', '{"psbt": "<COPY_PSBT_2>", "complete": false}', 'sign'),
                    osc_esc_html(__('3. Paste <COPY_PSBT_2> into the field "Paste here" below and your address into the field above it.', ELEKTRON_ESCROW_DOMAIN)),
                ]);
                elektron_escrow_action_form($deal, 'winner_submit_psbt', __('Submit my signed transaction', ELEKTRON_ESCROW_DOMAIN), [
                    'address' => ['label' => __('Paste here: the address you used in step 1', ELEKTRON_ESCROW_DOMAIN), 'help' => 'payout', 'value' => (string) ($isBuyer ? '' : $deal['seller_payout_address'])],
                    'psbt' => ['label' => __('Paste here: <COPY_PSBT_2> from step 2 (signed transaction)', ELEKTRON_ESCROW_DOMAIN), 'type' => 'textarea', 'help' => 'psbt'],
                ]);
                break;
        } ?>
        </div>
    <?php } ?>

    <?php if (in_array($status, [DealStatus::AWAITING_PAYMENT, DealStatus::CONFIRMING, DealStatus::AWAITING_SELLER_RELEASE], true)) { ?>
        <?php elektron_escrow_action_form($deal, 'check_chain', __('Check the chain now', ELEKTRON_ESCROW_DOMAIN)); ?>
        <p class="text-muted"><?php printf(osc_esc_html(__('The payment needs %d confirmations.', ELEKTRON_ESCROW_DOMAIN)), $requiredConfirmations); ?><?php echo $help('confirmations'); ?></p>
    <?php } ?>

    <?php if ($showShipping) { ?>
        <div class="elektron-escrow-panel">
            <p><strong><?php _e('Ship to', ELEKTRON_ESCROW_DOMAIN); ?></strong></p>
            <p><?php echo osc_esc_html($deal['buyer_shipping_name']); ?><br /><?php echo nl2br(osc_esc_html($deal['buyer_shipping_address'])); ?></p>
            <p><?php _e('Phone number', ELEKTRON_ESCROW_DOMAIN); ?>: <?php echo osc_esc_html($deal['buyer_shipping_phone']); ?><br />
               <?php _e('Contact email for this order', ELEKTRON_ESCROW_DOMAIN); ?>: <?php echo osc_esc_html($deal['buyer_contact_email']); ?></p>
        </div>
    <?php } ?>

    <?php if ($fundsAtRisk && $funding !== []) {
        $exitLocktime = $isBuyer ? $t1 : $t2;
        $exitReached = $now >= $exitLocktime;
        ?>
        <div class="elektron-escrow-panel">
            <h3><?php echo $isBuyer ? osc_esc_html(__('Take your money back after the refund date', ELEKTRON_ESCROW_DOMAIN)) : osc_esc_html(__('Claim your money after the claim date', ELEKTRON_ESCROW_DOMAIN)); ?><?php echo $help($isBuyer ? 'refund' : 'claim'); ?></h3>
            <?php if (!$exitReached) { ?>
                <p><?php printf(osc_esc_html($isBuyer
                    ? __('If the seller does not complete the deal, you can reclaim the funds yourself from %s, with a transaction from your own wallet. Nothing needs to be prepared now.', ELEKTRON_ESCROW_DOMAIN)
                    : __('If the buyer neither confirms nor refunds, you can claim the funds yourself from %s, with a transaction from your own wallet. Nothing needs to be prepared now.', ELEKTRON_ESCROW_DOMAIN)), osc_esc_html(date('Y-m-d H:i T', $exitLocktime))); ?></p>
            <?php } else { ?>
                <p><?php _e('The date has passed. Create the transaction in your wallet, broadcast it, and enter its transaction id.', ELEKTRON_ESCROW_DOMAIN); ?></p>
                <?php $psbtHelper($isBuyer ? 'refund' : 'claim', $isBuyer ? '' : (string) $deal['seller_payout_address']); ?>
                <?php $consoleHelp(__('How do I do this? (node wallet console)', ELEKTRON_ESCROW_DOMAIN), [
                    $cmdStep(__('1. Create the transaction', ELEKTRON_ESCROW_DOMAIN), __('In the command below replace <YOUR_ADDRESS> with an address of your own wallet and run it. The number before the options is the locked time, 10 is the fee in lep per byte.', ELEKTRON_ESCROW_DOMAIN), $createCommand('<YOUR_ADDRESS>', $exitLocktime, 4294967294), '{"psbt": "<COPY_PSBT_1>", "fee": 0.000016, "changepos": -1}', 'psbt'),
                    $cmdStep(__('2. Sign it', ELEKTRON_ESCROW_DOMAIN), __('In the command below replace <PASTE_PSBT_1> with <COPY_PSBT_1> and run it.', ELEKTRON_ESCROW_DOMAIN), 'walletprocesspsbt "<PASTE_PSBT_1>"', '{"psbt": "<COPY_PSBT_2>", "complete": true}', 'sign'),
                    $cmdStep(__('3. Finish it', ELEKTRON_ESCROW_DOMAIN), __('In the command below replace <PASTE_PSBT_2> with <COPY_PSBT_2> and run it.', ELEKTRON_ESCROW_DOMAIN), 'finalizepsbt "<PASTE_PSBT_2>"', '{"hex": "<COPY_HEX>", "complete": true}', 'finalize'),
                    $cmdStep(__('4. Send it', ELEKTRON_ESCROW_DOMAIN), __('In the command below replace <PASTE_HEX> with <COPY_HEX> and run it.', ELEKTRON_ESCROW_DOMAIN), 'sendrawtransaction "<PASTE_HEX>"', '<COPY_TXID>', 'broadcast'),
                    osc_esc_html(__('5. Paste <COPY_TXID> (64 characters) into the field "Transaction id" below.', ELEKTRON_ESCROW_DOMAIN)),
                ]); ?>
                <?php elektron_escrow_action_form($deal, $isBuyer ? 'buyer_mark_refunded' : 'seller_mark_claimed', $isBuyer ? __('I have broadcast the refund', ELEKTRON_ESCROW_DOMAIN) : __('I have broadcast the claim', ELEKTRON_ESCROW_DOMAIN), [
                    'txid' => ['label' => __('Paste here: <COPY_TXID> from step 4', ELEKTRON_ESCROW_DOMAIN), 'help' => 'txid'],
                ]); ?>
            <?php } ?>
        </div>
    <?php } ?>

    <h2><?php _e('History and messages', ELEKTRON_ESCROW_DOMAIN); ?></h2>
    <ul class="elektron-escrow-events">
        <?php foreach ($events as $event) { ?>
            <li>
                <small><?php echo osc_esc_html($event['dt_pub_date']); ?></small>
                <?php if ($event['s_type'] === 'arbiter_message') { ?>
                    <strong class="elektron-escrow-arbiter-label"><?php _e('Arbiter', ELEKTRON_ESCROW_DOMAIN); ?>:</strong>
                    <?php echo nl2br(osc_esc_html((string) $event['s_text'])); ?>
                <?php } elseif ($event['s_type'] === 'message') { ?>
                    <strong><?php echo ((int) $event['fk_i_user_id'] === (int) $deal['fk_i_buyer_id']) ? osc_esc_html(__('Buyer', ELEKTRON_ESCROW_DOMAIN)) : osc_esc_html(__('Seller', ELEKTRON_ESCROW_DOMAIN)); ?>:</strong>
                    <?php echo nl2br(osc_esc_html((string) $event['s_text'])); ?>
                <?php } else { ?>
                    <?php echo osc_esc_html(elektron_escrow_event_label($event['s_type'])); ?>
                    <?php if (!empty($event['s_text'])) { ?>
                        <?php $eventLink = elektron_escrow_explorer_url('tx', $event['s_text']); ?>
                        <?php echo $eventLink !== null ? '<a href="' . osc_esc_html($eventLink) . '" target="_blank" rel="noopener noreferrer">' . osc_esc_html(substr($event['s_text'], 0, 16)) . '...</a>' : ''; ?>
                    <?php } ?>
                <?php } ?>
            </li>
        <?php } ?>
    </ul>
    <?php elektron_escrow_action_form($deal, 'message', __('Send message', ELEKTRON_ESCROW_DOMAIN), [
        'message' => ['label' => __('Message to the other party', ELEKTRON_ESCROW_DOMAIN), 'help' => 'message'],
    ]); ?>

    <?php
    $canCancel = in_array($status, DealStatus::CANCELLABLE, true);
    $canDispute = !empty($deal['arbiter_pubkey']) && in_array($status, DealStatus::DISPUTABLE, true);
    if (!DealStatus::isTerminal($status)) { ?>
        <details class="elektron-escrow-emergency">
            <summary><strong><?php _e('Emergency', ELEKTRON_ESCROW_DOMAIN); ?></strong><?php echo $help('emergency'); ?></summary>
            <?php if ($canCancel) { ?>
                <h4><?php _e('Cancel this deal', ELEKTRON_ESCROW_DOMAIN); ?></h4>
                <p><?php _e('Only possible while no payment was made. Either side can do it.', ELEKTRON_ESCROW_DOMAIN); ?></p>
                <?php elektron_escrow_action_form($deal, 'cancel', __('Cancel this deal', ELEKTRON_ESCROW_DOMAIN), [], __('Cancel this deal? Only possible while no payment was made.', ELEKTRON_ESCROW_DOMAIN)); ?>
            <?php } ?>
            <?php if ($canDispute) { ?>
                <h4><?php _e('Call the arbiter', ELEKTRON_ESCROW_DOMAIN); ?><?php echo $help('dispute'); ?></h4>
                <p><?php _e('Use this only if buyer and seller cannot agree, for example if the goods did not arrive or the buyer does not release the money after delivery. Talk to each other first, using the messages above.', ELEKTRON_ESCROW_DOMAIN); ?></p>
                <?php elektron_escrow_action_form($deal, 'open_dispute', __('Call the arbiter', ELEKTRON_ESCROW_DOMAIN), [
                    'reason' => ['label' => __('What is the problem? The arbiter and the other party read this.', ELEKTRON_ESCROW_DOMAIN), 'type' => 'textarea'],
                ], __('Call the arbiter now? Both parties can no longer continue without the arbiter.', ELEKTRON_ESCROW_DOMAIN)); ?>
            <?php } ?>
            <?php if (!$canCancel && !$canDispute) { ?>
                <p><?php _e('Nothing to do here at the moment.', ELEKTRON_ESCROW_DOMAIN); ?></p>
            <?php } ?>
        </details>
    <?php } ?>

    <details>
        <summary><?php _e('Glossary', ELEKTRON_ESCROW_DOMAIN); ?></summary>
        <?php foreach ([
            'escrow' => __('Escrow address', ELEKTRON_ESCROW_DOMAIN),
            'pubkey' => __('Public key', ELEKTRON_ESCROW_DOMAIN),
            'private_key' => __('Private key', ELEKTRON_ESCROW_DOMAIN),
            'descriptor' => __('Descriptor', ELEKTRON_ESCROW_DOMAIN),
            'psbt' => __('PSBT', ELEKTRON_ESCROW_DOMAIN),
            'sign' => __('Signing', ELEKTRON_ESCROW_DOMAIN),
            'finalize' => __('Finalizing', ELEKTRON_ESCROW_DOMAIN),
            'broadcast' => __('Broadcasting', ELEKTRON_ESCROW_DOMAIN),
            'txid' => __('Transaction id', ELEKTRON_ESCROW_DOMAIN),
            'confirmations' => __('Confirmations', ELEKTRON_ESCROW_DOMAIN),
            'locktimes' => __('Refund and claim date', ELEKTRON_ESCROW_DOMAIN),
        ] as $key => $term) { ?>
            <p><strong><?php echo osc_esc_html($term); ?>:</strong> <?php echo osc_esc_html(elektron_escrow_help_text($key)); ?></p>
        <?php } ?>
    </details>
</div>
