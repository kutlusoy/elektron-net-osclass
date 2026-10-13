<?php if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');
/** @var array $deal */
/** @var string $role 'buyer' or 'seller' */
/** @var array|false $item */
/** @var float $amountElek */
/** @var int $requiredConfirmations */
/** @var array[] $events */
/** @var string|null $statusMessage */
/** @var string|null $errorMessage */

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
$ownKey = $isBuyer ? $deal['buyer_pubkey'] : $deal['seller_pubkey'];
$ownExitKey = $isBuyer ? $deal['buyer_exit_pubkey'] : $deal['seller_exit_pubkey'];

$path = [
    DealStatus::AWAITING_KEYS,
    DealStatus::AWAITING_PAYMENT, DealStatus::CONFIRMING, DealStatus::FUNDED, DealStatus::SHIPPED,
    DealStatus::AWAITING_SELLER_RELEASE, DealStatus::AWAITING_BUYER_RELEASE, DealStatus::RELEASED,
];
$position = array_search($status, $path, true);

$publicDescriptor = null;
$descriptorTemplate = null;
if ($deal['witness_script_hex'] !== null) {
    $publicDescriptor = Descriptor::escrow($deal['seller_pubkey'], $deal['seller_exit_pubkey'], $deal['buyer_pubkey'], $deal['buyer_exit_pubkey'], $t1, $t2);
    $descriptorTemplate = Descriptor::escrowBody($deal['seller_pubkey'], $deal['seller_exit_pubkey'], $deal['buyer_pubkey'], $deal['buyer_exit_pubkey'], $t1, $t2, [
        $ownKey => '<YOUR_PRIVATE_KEY_1>',
        $ownExitKey => '<YOUR_PRIVATE_KEY_2>',
    ]);
}

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

elektron_escrow_table_style();
?>
<style>
    .elektron-escrow-steps { list-style: none; padding: 0; margin: 1em 0; display: flex; flex-wrap: wrap; gap: 6px; }
    .elektron-escrow-steps li { padding: 4px 10px; border: 1px solid #ccc; border-radius: 12px; font-size: 0.85em; color: #777; }
    .elektron-escrow-steps li.done { color: #2a7a2a; border-color: #2a7a2a; }
    .elektron-escrow-steps li.current { background: #fff3cd; border-color: #d4a017; color: #000; font-weight: bold; }
    .elektron-escrow-next { background: #eef6ff; border: 1px solid #9cc4f0; border-radius: 4px; padding: 10px 14px; margin: 1em 0; }
    .elektron-escrow-panel { border: 1px solid #ddd; border-radius: 4px; padding: 6px 14px; margin: 1em 0; }
    .elektron-escrow-order textarea { width: 100%; box-sizing: border-box; }
    .elektron-escrow-order details { margin: 0.5em 0; }
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

    <?php if ($deal['escrow_address'] !== null && !DealStatus::isTerminal($status)) { ?>
        <details class="elektron-escrow-panel">
            <summary><strong><?php _e('Check the address and prepare your wallet (recommended before you pay or ship)', ELEKTRON_ESCROW_DOMAIN); ?></strong><?php echo $help('check_address'); ?></summary>
            <?php echo $why(__('Why: your wallet calculates the address on its own, so you do not have to trust this page. Preparing it now means you can sign later without delay.', ELEKTRON_ESCROW_DOMAIN)); ?>
            <?php $consoleHelp(__('Commands for the node wallet console', ELEKTRON_ESCROW_DOMAIN), [
                '<strong>' . osc_esc_html(__('1. Check the address', ELEKTRON_ESCROW_DOMAIN)) . '</strong><br />' . osc_esc_html(__('Copy the whole line below, including the quotation marks, paste it into the console and press Enter. It must print exactly the escrow address shown above:', ELEKTRON_ESCROW_DOMAIN)) . $code('deriveaddresses "' . $publicDescriptor . '"'),
                '<strong>' . osc_esc_html(__('2. Prepare signing', ELEKTRON_ESCROW_DOMAIN)) . '</strong>' . $help('private_key') . '<br />' . osc_esc_html(__('Replace <YOUR_PRIVATE_KEY_1> by the private key that belongs to your main public key and <YOUR_PRIVATE_KEY_2> by the one that belongs to your exit public key (WIF keys, or keys from "listdescriptors true" written as [fingerprint/path]xprv.../0/N). Then add the checksum and import it into the wallet you sign with:', ELEKTRON_ESCROW_DOMAIN)) . $code('getdescriptorinfo "' . $descriptorTemplate . '"') . $code('importdescriptors \'[{"desc":"<descriptor from getdescriptorinfo>","timestamp":"now"}]\''),
            ]); ?>
        </details>
    <?php } ?>

    <?php if ($myTurn) { ?>
        <div class="elektron-escrow-panel">
        <?php switch ($status) {
            case DealStatus::AWAITING_KEYS:
                echo $why(__('Why: the escrow address is built from your two public keys and the other party\'s two public keys. Public keys can be shared safely.', ELEKTRON_ESCROW_DOMAIN));
                elektron_escrow_action_form($deal, $isBuyer ? 'buyer_submit_key' : 'seller_submit_key', __('Submit', ELEKTRON_ESCROW_DOMAIN), [
                    'pubkey' => ['label' => __('Your main public key (66 characters)', ELEKTRON_ESCROW_DOMAIN), 'placeholder' => '02...', 'help' => 'pubkey'],
                    'exit_pubkey' => ['label' => __('Your exit public key (66 characters, a different key)', ELEKTRON_ESCROW_DOMAIN), 'placeholder' => '03...', 'help' => 'exit_pubkey'],
                ]);
                echo elektron_escrow_howto('pubkey');
                break;

            case DealStatus::AWAITING_PAYMENT:
                $paymentUri = elektron_escrow_payment_uri($deal['escrow_address'], $amountElek, $dealId);
                echo $why(__('Why: the payment is a normal transaction from your wallet to the escrow address. From there it can only move with both signatures, or through the exits after the refund or claim date.', ELEKTRON_ESCROW_DOMAIN));
                echo '<p><strong>' . osc_esc_html(sprintf(__('Send %s to the escrow address', ELEKTRON_ESCROW_DOMAIN), $amountText)) . '</strong></p>';
                echo elektron_escrow_qr_image($paymentUri, $paymentUri);
                echo $code($deal['escrow_address']);
                echo '<p>' . osc_esc_html(__('Pay the full amount in one transaction. This page detects the payment by itself.', ELEKTRON_ESCROW_DOMAIN)) . '</p>';
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
                $consoleHelp(__('How do I create and sign the release? (node wallet console)', ELEKTRON_ESCROW_DOMAIN), [
                    '<strong>' . osc_esc_html(__('1. Create the transaction', ELEKTRON_ESCROW_DOMAIN)) . '</strong>' . $help('psbt') . '<br />' . osc_esc_html(__('Replace <YOUR_ADDRESS> with an address of your own wallet. The fee is taken from the amount. "fee_rate" is the fee in lep per virtual byte; 10 is a normal value, check the block explorer for current fees and change it if needed. Without it the wallet may stop with "Fee estimation failed".', ELEKTRON_ESCROW_DOMAIN)) . $code($createCommand('<YOUR_ADDRESS>', 0, null)),
                    '<strong>' . osc_esc_html(__('2. Sign it', ELEKTRON_ESCROW_DOMAIN)) . '</strong>' . $help('sign') . $code('walletprocesspsbt "<psbt from step 1>"'),
                    osc_esc_html(__('Paste the "psbt" value of the result below, together with your address.', ELEKTRON_ESCROW_DOMAIN)),
                ]);
                elektron_escrow_action_form($deal, 'seller_submit_release', __('Submit my signed release', ELEKTRON_ESCROW_DOMAIN), [
                    'address' => ['label' => __('The address you paid yourself to', ELEKTRON_ESCROW_DOMAIN), 'help' => 'payout', 'value' => (string) $deal['seller_payout_address']],
                    'psbt' => ['label' => __('Signed release transaction (PSBT)', ELEKTRON_ESCROW_DOMAIN), 'type' => 'textarea', 'help' => 'psbt'],
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
                    '<strong>' . osc_esc_html(__('1. Look at it in your wallet', ELEKTRON_ESCROW_DOMAIN)) . '</strong>' . $help('verify') . $code('decodepsbt "<the PSBT above>"'),
                    '<strong>' . osc_esc_html(__('2. Sign it', ELEKTRON_ESCROW_DOMAIN)) . '</strong>' . $help('sign') . $code('walletprocesspsbt "<the PSBT above>"'),
                    '<strong>' . osc_esc_html(__('3. Finish it', ELEKTRON_ESCROW_DOMAIN)) . '</strong>' . $help('finalize') . $code('finalizepsbt "<psbt from step 2>"'),
                    '<strong>' . osc_esc_html(__('4. Send it', ELEKTRON_ESCROW_DOMAIN)) . '</strong>' . $help('broadcast') . $code('sendrawtransaction "<hex from step 3>"'),
                    osc_esc_html(__('Paste the resulting transaction id below.', ELEKTRON_ESCROW_DOMAIN)),
                ]);
                elektron_escrow_action_form($deal, 'buyer_mark_released', __('I have broadcast the release', ELEKTRON_ESCROW_DOMAIN), [
                    'txid' => ['label' => __('Transaction id of the release', ELEKTRON_ESCROW_DOMAIN), 'help' => 'txid'],
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
                <?php $consoleHelp(__('How do I do this? (node wallet console)', ELEKTRON_ESCROW_DOMAIN), [
                    '<strong>' . osc_esc_html(__('1. Create the transaction', ELEKTRON_ESCROW_DOMAIN)) . '</strong>' . $help('psbt') . '<br />' . osc_esc_html(__('Replace <YOUR_ADDRESS> with an address of your own wallet. The number before the options is the locked time. "fee_rate" is the fee in lep per virtual byte; 10 is a normal value, check the block explorer for current fees and change it if needed.', ELEKTRON_ESCROW_DOMAIN)) . $code($createCommand('<YOUR_ADDRESS>', $exitLocktime, 4294967294)),
                    '<strong>' . osc_esc_html(__('2. Sign it', ELEKTRON_ESCROW_DOMAIN)) . '</strong>' . $help('sign') . $code('walletprocesspsbt "<psbt from step 1>"'),
                    '<strong>' . osc_esc_html(__('3. Finish it', ELEKTRON_ESCROW_DOMAIN)) . '</strong>' . $help('finalize') . $code('finalizepsbt "<psbt from step 2>"'),
                    '<strong>' . osc_esc_html(__('4. Send it', ELEKTRON_ESCROW_DOMAIN)) . '</strong>' . $help('broadcast') . $code('sendrawtransaction "<hex from step 3>"'),
                ]); ?>
                <?php elektron_escrow_action_form($deal, $isBuyer ? 'buyer_mark_refunded' : 'seller_mark_claimed', $isBuyer ? __('I have broadcast the refund', ELEKTRON_ESCROW_DOMAIN) : __('I have broadcast the claim', ELEKTRON_ESCROW_DOMAIN), [
                    'txid' => ['label' => __('Transaction id', ELEKTRON_ESCROW_DOMAIN), 'help' => 'txid'],
                ]); ?>
            <?php } ?>
        </div>
    <?php } ?>

    <?php
    if (in_array($status, DealStatus::CANCELLABLE, true)) {
        elektron_escrow_action_form($deal, 'cancel', __('Cancel this deal', ELEKTRON_ESCROW_DOMAIN), [], __('Cancel this deal? Only possible while no payment was made.', ELEKTRON_ESCROW_DOMAIN));
    }
    ?>

    <h2><?php _e('History and messages', ELEKTRON_ESCROW_DOMAIN); ?></h2>
    <ul class="elektron-escrow-events">
        <?php foreach ($events as $event) { ?>
            <li>
                <small><?php echo osc_esc_html($event['dt_pub_date']); ?></small>
                <?php if ($event['s_type'] === 'message') { ?>
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
