<?php if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');
/** @var array|null $deal the open case, null for the list */
/** @var array[] $cases */
/** @var string|null $statusMessage */
/** @var string|null $errorMessage */

use ElektronNet\Escrow\DealStatus;
use ElektronNet\Escrow\Psbt;
use ElektronNet\Escrow\PsbtVerifier;

elektron_escrow_table_style();
$page = osc_admin_render_plugin_url('osclass-escrow/admin/arbiter.php');
$userName = function (int $id): string {
    $user = User::newInstance()->findByPrimaryKey($id);

    return $user ? trim($user['s_name'] . ' <' . $user['s_email'] . '>') : '#' . $id;
};
$roleName = function (string $role): string {
    return $role === 'buyer' ? __('BUYER', ELEKTRON_ESCROW_DOMAIN) : __('SELLER', ELEKTRON_ESCROW_DOMAIN);
};
$amountText = function (int $lep): string {
    return elektron_escrow_format_amount($lep / 100000000) . ' ELEK';
};
?>
<div class="element-inside elektron-escrow-order">
    <h2><?php _e('Elektron Net Arbiter', ELEKTRON_ESCROW_DOMAIN); ?></h2>

    <?php if ($statusMessage !== null) { ?>
        <p class="elektron-escrow-success"><?php echo osc_esc_html($statusMessage); ?></p>
    <?php } ?>
    <?php if ($errorMessage !== null) { ?>
        <p class="elektron-escrow-error"><?php echo osc_esc_html($errorMessage); ?></p>
    <?php } ?>

    <?php if (elektron_escrow_arbiter_xpub() === '') { ?>
        <p class="elektron-escrow-error"><?php _e('The arbiter wallet is not connected yet. Connect it in the Elektron Net Escrow settings.', ELEKTRON_ESCROW_DOMAIN); ?></p>
    <?php } ?>

    <?php if ($deal === null) { ?>
        <h3><?php _e('Cases', ELEKTRON_ESCROW_DOMAIN); ?></h3>
        <?php if ($cases === []) { ?>
            <p><?php _e('No open cases.', ELEKTRON_ESCROW_DOMAIN); ?></p>
        <?php } else { ?>
            <table class="elektron-escrow-table">
                <tr><th>#</th><th><?php _e('Item', ELEKTRON_ESCROW_DOMAIN); ?></th><th><?php _e('Amount', ELEKTRON_ESCROW_DOMAIN); ?></th><th><?php _e('What is needed', ELEKTRON_ESCROW_DOMAIN); ?></th><th><?php _e('Opened', ELEKTRON_ESCROW_DOMAIN); ?></th><th></th></tr>
                <?php foreach ($cases as $case) { ?>
                    <?php $caseItem = Item::newInstance()->findByPrimaryKey((int) $case['fk_i_item_id']); ?>
                    <tr>
                        <td><?php echo (int) $case['pk_i_id']; ?></td>
                        <td><?php echo osc_esc_html(elektron_escrow_item_title($case, $caseItem)); ?></td>
                        <td><?php echo osc_esc_html($amountText((int) $case['amount_lep'])); ?></td>
                        <td><strong><?php
                            echo osc_esc_html($case['status'] === DealStatus::DISPUTED
                                ? __('Decision needed', ELEKTRON_ESCROW_DOMAIN)
                                : ($case['status'] === DealStatus::AWAITING_ARBITER_SIGNATURE ? __('Signature needed', ELEKTRON_ESCROW_DOMAIN) : __('Waiting for the party in the right', ELEKTRON_ESCROW_DOMAIN)));
                        ?></strong></td>
                        <td><?php echo osc_esc_html((string) $case['dispute_opened_at']); ?></td>
                        <td><a class="btn btn-submit" href="<?php echo osc_esc_html($page . '&deal=' . (int) $case['pk_i_id']); ?>"><?php _e('Open case', ELEKTRON_ESCROW_DOMAIN); ?></a></td>
                    </tr>
                <?php } ?>
            </table>
        <?php } ?>
    <?php } else { ?>
        <?php
        $dealId = (int) $deal['pk_i_id'];
        $item = Item::newInstance()->findByPrimaryKey((int) $deal['fk_i_item_id']);
        $funding = elektron_escrow_deal_funding_list($deal);
        $fundingLep = (int) array_sum(array_column($funding, 'amountLep'));
        $events = EscrowDealDAO::newInstance()->events($dealId);
        $names = ['buyer' => $userName((int) $deal['fk_i_buyer_id']), 'seller' => $userName((int) $deal['fk_i_seller_id'])];
        $status = $deal['status'];
        ?>
        <p><a href="<?php echo osc_esc_html($page); ?>">&larr; <?php _e('All cases', ELEKTRON_ESCROW_DOMAIN); ?></a></p>
        <h3><?php printf(osc_esc_html(__('Case #%d', ELEKTRON_ESCROW_DOMAIN)), $dealId); ?>: <?php echo osc_esc_html(elektron_escrow_status_label($status)); ?></h3>

        <table class="elektron-escrow-table">
            <tr><td><?php _e('Item', ELEKTRON_ESCROW_DOMAIN); ?></td><td><?php echo elektron_escrow_item_exists($item) ? '<a href="' . osc_esc_html(osc_item_url_from_item($item)) . '" target="_blank">' . osc_esc_html($item['s_title']) . '</a>' : osc_esc_html(elektron_escrow_item_title($deal, $item)); ?></td></tr>
            <tr><td><?php _e('Amount in the escrow', ELEKTRON_ESCROW_DOMAIN); ?></td><td><?php echo osc_esc_html($amountText($fundingLep ?: (int) $deal['amount_lep'])); ?></td></tr>
            <tr><td><?php echo osc_esc_html($roleName('buyer')); ?></td><td><?php echo osc_esc_html($names['buyer']); ?></td></tr>
            <tr><td><?php echo osc_esc_html($roleName('seller')); ?></td><td><?php echo osc_esc_html($names['seller']); ?></td></tr>
            <tr><td><?php _e('Buyer can reclaim after', ELEKTRON_ESCROW_DOMAIN); ?></td><td><?php echo osc_esc_html(date('Y-m-d H:i T', (int) $deal['buyer_refund_locktime'])); ?></td></tr>
            <tr><td><?php _e('Seller can claim after', ELEKTRON_ESCROW_DOMAIN); ?></td><td><?php echo osc_esc_html(date('Y-m-d H:i T', (int) $deal['seller_release_locktime'])); ?></td></tr>
            <tr><td><?php _e('Escrow address', ELEKTRON_ESCROW_DOMAIN); ?></td><td><?php
                $link = elektron_escrow_deal_explorer_link($deal);
                echo $link !== null ? '<a href="' . osc_esc_html($link) . '" target="_blank" rel="noopener noreferrer">' . osc_esc_html($deal['escrow_address']) . '</a>' : osc_esc_html($deal['escrow_address']); ?></td></tr>
        </table>
        <p class="elektron-escrow-why"><?php _e('Check on the explorer that the money is still in the escrow address before you decide. Either party can also take it out after the dates above.', ELEKTRON_ESCROW_DOMAIN); ?></p>

        <div class="elektron-escrow-panel elektron-escrow-dispute">
            <h4><?php printf(osc_esc_html(__('Dispute opened by the %1$s on %2$s', ELEKTRON_ESCROW_DOMAIN)), osc_esc_html($roleName((string) $deal['dispute_opened_by'])), osc_esc_html((string) $deal['dispute_opened_at'])); ?></h4>
            <p><?php echo nl2br(osc_esc_html((string) $deal['dispute_reason'])); ?></p>
        </div>

        <h4><?php _e('History and messages', ELEKTRON_ESCROW_DOMAIN); ?></h4>
        <ul class="elektron-escrow-events">
            <?php foreach ($events as $event) { ?>
                <li><small><?php echo osc_esc_html($event['dt_pub_date']); ?></small>
                    <?php if ($event['s_type'] === 'arbiter_message') { ?>
                        <strong><?php _e('ARBITER', ELEKTRON_ESCROW_DOMAIN); ?> (<?php _e('you', ELEKTRON_ESCROW_DOMAIN); ?>):</strong>
                        <?php echo nl2br(osc_esc_html((string) $event['s_text'])); ?>
                    <?php } elseif ($event['s_type'] === 'message') { ?>
                        <strong><?php echo osc_esc_html((int) $event['fk_i_user_id'] === (int) $deal['fk_i_buyer_id'] ? $roleName('buyer') : $roleName('seller')); ?>:</strong>
                        <?php echo nl2br(osc_esc_html((string) $event['s_text'])); ?>
                    <?php } else { ?>
                        <?php echo osc_esc_html(elektron_escrow_event_label($event['s_type'])); ?>
                    <?php } ?>
                </li>
            <?php } ?>
        </ul>

        <form method="post" action="<?php echo osc_esc_html($page . '&deal=' . $dealId); ?>">
            <?php echo osc_csrf_token_form(); ?>
            <input type="hidden" name="arbiter_action" value="arbiter_message" />
            <p><label for="elektron-escrow-chat"><?php _e('Write to buyer and seller (both see it and get an email)', ELEKTRON_ESCROW_DOMAIN); ?></label><br />
                <textarea name="message" id="elektron-escrow-chat" rows="3" cols="70" required="required"></textarea></p>
            <input type="submit" class="btn btn-submit" value="<?php echo osc_esc_html(__('Send message', ELEKTRON_ESCROW_DOMAIN)); ?>" />
        </form>

        <?php if ($status === DealStatus::DISPUTED) { ?>
            <div class="elektron-escrow-panel">
                <h3><?php _e('Your decision', ELEKTRON_ESCROW_DOMAIN); ?></h3>
                <p><?php _e('Decide who receives the money. Both parties see your reason.', ELEKTRON_ESCROW_DOMAIN); ?></p>
                <form method="post" action="<?php echo osc_esc_html($page . '&deal=' . $dealId); ?>">
                    <?php echo osc_csrf_token_form(); ?>
                    <input type="hidden" name="arbiter_action" value="arbiter_rule" />
                    <p><label for="elektron-escrow-note"><?php _e('Reason for the decision (required)', ELEKTRON_ESCROW_DOMAIN); ?></label><br />
                        <textarea name="note" id="elektron-escrow-note" rows="4" cols="70" required="required"></textarea></p>
                    <p>
                        <button type="submit" name="ruling" value="seller" class="btn btn-submit" style="background:#1e7e34;color:#fff;" onclick="return confirm('<?php echo osc_esc_js(sprintf(__('Pay the money to the SELLER (%s)?', ELEKTRON_ESCROW_DOMAIN), $names['seller'])); ?>');">
                            <?php _e('Pay the SELLER', ELEKTRON_ESCROW_DOMAIN); ?>
                        </button>
                        <button type="submit" name="ruling" value="buyer" class="btn btn-submit" style="background:#1f5fa8;color:#fff;" onclick="return confirm('<?php echo osc_esc_js(sprintf(__('Pay the money back to the BUYER (%s)?', ELEKTRON_ESCROW_DOMAIN), $names['buyer'])); ?>');">
                            <?php _e('Pay the BUYER back', ELEKTRON_ESCROW_DOMAIN); ?>
                        </button>
                    </p>
                </form>
            </div>
        <?php } elseif ($status === DealStatus::RULED) { ?>
            <div class="elektron-escrow-panel">
                <p><strong><?php printf(osc_esc_html(__('Decided: the money goes to the %s.', ELEKTRON_ESCROW_DOMAIN)), osc_esc_html($roleName((string) $deal['ruling']))); ?></strong>
                    <?php _e('Waiting for that party to prepare and sign the transaction. You get an email when it is your turn.', ELEKTRON_ESCROW_DOMAIN); ?></p>
            </div>
        <?php } elseif ($status === DealStatus::AWAITING_ARBITER_SIGNATURE) { ?>
            <?php
            $summary = PsbtVerifier::inspect(Psbt::parse($deal['ruling_psbt']), elektron_escrow_network_params());
            $winner = (string) $deal['ruling'];
            $descriptor = elektron_escrow_private_descriptor($deal, 'arbiter');
            $walletName = (string) osc_get_preference('arbiter_wallet_name', 'plugin-osclass-escrow');
            ?>
            <div class="elektron-escrow-panel" style="border:3px solid <?php echo $winner === 'seller' ? '#1e7e34' : '#1f5fa8'; ?>;">
                <h3><?php printf(osc_esc_html(__('Send %1$s to the %2$s', ELEKTRON_ESCROW_DOMAIN)), osc_esc_html($amountText((int) $summary['outputs'][0]['amountLep'])), osc_esc_html($roleName($winner))); ?></h3>
                <p><strong><?php echo osc_esc_html($names[$winner]); ?></strong><br />
                    <code class="elektron-escrow-code"><?php echo osc_esc_html((string) $summary['outputs'][0]['address']); ?></code>
                    <?php printf(osc_esc_html(__('Fee: %d lep.', ELEKTRON_ESCROW_DOMAIN)), (int) $summary['feeLep']); ?></p>
                <p class="elektron-escrow-why"><?php _e('The party in the right has already signed. Your signature completes it.', ELEKTRON_ESCROW_DOMAIN); ?></p>
            </div>
            <?php elektron_escrow_copy_block(__('Transaction signed by the party in the right (PSBT)', ELEKTRON_ESCROW_DOMAIN), $deal['ruling_psbt'], 'elektron-escrow-ruling-psbt', 'ruling-' . $dealId . '.psbt', 'psbt'); ?>
            <?php if ($descriptor !== null) { ?>
                <details class="elektron-escrow-panel">
                    <summary><strong><?php _e('A. Prepare the arbiter wallet for this deal (once per deal)', ELEKTRON_ESCROW_DOMAIN); ?></strong></summary>
                    <?php if ($walletName !== '') { echo '<p class="elektron-escrow-why">' . osc_esc_html(sprintf(__('Choose the wallet "%s" in the dropdown at the top of the console.', ELEKTRON_ESCROW_DOMAIN), $walletName)) . '</p>'; } ?>
                    <?php foreach ([
                        elektron_escrow_cmd_step(__('1. Get your wallet key', ELEKTRON_ESCROW_DOMAIN), __('Run this command. Copy the key from the result. Keep it for steps 2 and 3 and never show it to anyone.', ELEKTRON_ESCROW_DOMAIN), "gethdkeys '{\"active_only\":true,\"private\":true}'", '[{"xpub": "xpub661My...", "has_private": true, "xprv": "<COPY_XPRV>", ...}]'),
                        elektron_escrow_cmd_step(__('2. Get the checksum', ELEKTRON_ESCROW_DOMAIN), __('In the command below replace <PASTE_XPRV> with <COPY_XPRV> from step 1 and run it. Do not change anything else.', ELEKTRON_ESCROW_DOMAIN), 'getdescriptorinfo "' . $descriptor . '"', '{"descriptor": "(ignore this line)", "checksum": "<COPY_CHECKSUM>", ...}'),
                        elektron_escrow_cmd_step(__('3. Import', ELEKTRON_ESCROW_DOMAIN), __('In the command below replace <PASTE_XPRV> with <COPY_XPRV> and <PASTE_CHECKSUM> with <COPY_CHECKSUM> from step 2, then run it. If the payment is already confirmed, write 0 instead of now.', ELEKTRON_ESCROW_DOMAIN), 'importdescriptors \'[{"desc":"' . $descriptor . '#<PASTE_CHECKSUM>","timestamp":"now"}]\'', '[{"success": true, "warnings": ["Not all private keys provided. ..."]}]'),
                        elektron_escrow_cmd_step(__('4. Check', ELEKTRON_ESCROW_DOMAIN), __('The result must show "ismine": true and "solvable": true.', ELEKTRON_ESCROW_DOMAIN), 'getaddressinfo "' . $deal['escrow_address'] . '"'),
                    ] as $stepHtml) { echo '<p>' . $stepHtml . '</p>'; } ?>
                </details>
            <?php } ?>
            <div class="elektron-escrow-panel">
                <h4><?php _e('B. Sign and send', ELEKTRON_ESCROW_DOMAIN); ?></h4>
                <?php foreach ([
                    elektron_escrow_cmd_step(__('1. Sign it', ELEKTRON_ESCROW_DOMAIN), __('In the command below replace <PASTE_PSBT> with the text of the box above (button Copy) and run it. Now "complete": true is expected.', ELEKTRON_ESCROW_DOMAIN), 'walletprocesspsbt "<PASTE_PSBT>"', '{"psbt": "<COPY_PSBT_2>", "complete": true}'),
                    elektron_escrow_cmd_step(__('2. Finish it', ELEKTRON_ESCROW_DOMAIN), __('In the command below replace <PASTE_PSBT_2> with <COPY_PSBT_2> and run it.', ELEKTRON_ESCROW_DOMAIN), 'finalizepsbt "<PASTE_PSBT_2>"', '{"hex": "<COPY_HEX>", "complete": true}'),
                    elektron_escrow_cmd_step(__('3. Send it', ELEKTRON_ESCROW_DOMAIN), __('In the command below replace <PASTE_HEX> with <COPY_HEX> and run it. Only now money moves.', ELEKTRON_ESCROW_DOMAIN), 'sendrawtransaction "<PASTE_HEX>"', '<COPY_TXID>'),
                ] as $stepHtml) { echo '<p>' . $stepHtml . '</p>'; } ?>
                <form method="post" action="<?php echo osc_esc_html($page . '&deal=' . $dealId); ?>">
                    <?php echo osc_csrf_token_form(); ?>
                    <input type="hidden" name="arbiter_action" value="arbiter_complete" />
                    <p><label for="elektron-escrow-txid"><?php _e('Paste here: <COPY_TXID> from step 3 (64 characters)', ELEKTRON_ESCROW_DOMAIN); ?></label><br />
                        <input type="text" name="txid" id="elektron-escrow-txid" size="70" required="required" autocomplete="off" /></p>
                    <input type="submit" class="btn btn-submit" value="<?php echo osc_esc_html(__('I have sent it, close the case', ELEKTRON_ESCROW_DOMAIN)); ?>" />
                </form>
            </div>
        <?php } else { ?>
            <p><?php echo osc_esc_html(elektron_escrow_status_label($status)); ?></p>
        <?php } ?>
    <?php } ?>
</div>
