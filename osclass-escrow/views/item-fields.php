<?php if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');
/** @var string $pubKey */
/** @var string $exitPubKey */
/** @var string $payoutAddress */

elektron_escrow_table_style();
?>
<fieldset class="elektron-escrow-item-fields">
    <legend><?php _e('Elektron escrow (optional)', ELEKTRON_ESCROW_DOMAIN); ?><?php echo elektron_escrow_help('payout_optional'); ?></legend>
    <input type="hidden" name="elektron_escrow_fields" value="1" />
    <p>
        <label for="elektron_escrow_pubkey"><?php _e('Your main public key', ELEKTRON_ESCROW_DOMAIN); ?></label><?php echo elektron_escrow_help('pubkey'); ?><br />
        <input type="text" name="elektron_escrow_pubkey" id="elektron_escrow_pubkey" size="70" maxlength="66" autocomplete="off" placeholder="02..." value="<?php echo osc_esc_html($pubKey); ?>" />
    </p>
    <p>
        <label for="elektron_escrow_exit_pubkey"><?php _e('Your exit public key', ELEKTRON_ESCROW_DOMAIN); ?></label><?php echo elektron_escrow_help('exit_pubkey'); ?><br />
        <input type="text" name="elektron_escrow_exit_pubkey" id="elektron_escrow_exit_pubkey" size="70" maxlength="66" autocomplete="off" placeholder="03..." value="<?php echo osc_esc_html($exitPubKey); ?>" />
    </p>
    <?php echo elektron_escrow_howto('pubkey'); ?>
    <p>
        <label for="elektron_escrow_payout_address"><?php _e('Your payout address', ELEKTRON_ESCROW_DOMAIN); ?></label><?php echo elektron_escrow_help('payout'); ?><br />
        <input type="text" name="elektron_escrow_payout_address" id="elektron_escrow_payout_address" size="70" maxlength="100" autocomplete="off" value="<?php echo osc_esc_html($payoutAddress); ?>" />
    </p>
    <?php echo elektron_escrow_howto('address'); ?>
    <p class="elektron-escrow-why"><?php _e('Never enter a private key here. Both values are shown to buyers who start an escrow purchase.', ELEKTRON_ESCROW_DOMAIN); ?></p>
</fieldset>
