<?php if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');
/** @var string|null $statusMessage */
/** @var string|null $errorMessage */
?>

<div class="element-inside">
    <h2><?php _e('Elektron Net Escrow settings', ELEKTRON_ESCROW_DOMAIN); ?></h2>

    <?php if ($statusMessage !== null) { ?>
        <p style="padding:8px 12px;background:#e6f4ea;border:1px solid #2a7a2a;"><?php echo osc_esc_html($statusMessage); ?></p>
    <?php } ?>
    <?php if ($errorMessage !== null) { ?>
        <p style="padding:8px 12px;background:#fdecea;border:1px solid #c0392b;"><?php echo osc_esc_html($errorMessage); ?></p>
    <?php } ?>

    <form method="post" action="<?php echo osc_admin_render_plugin_url('osclass-escrow/admin/settings.php'); ?>">
        <?php echo osc_csrf_token_form(); ?>
        <input type="hidden" name="save" value="elektron_escrow_settings" />

        <table class="table">
            <tr>
                <td><label for="network"><?php _e('Network', ELEKTRON_ESCROW_DOMAIN); ?></label></td>
                <td>
                    <select name="network" id="network">
                        <?php foreach (['mainnet', 'testnet', 'regtest'] as $network) { ?>
                            <option value="<?php echo $network; ?>" <?php echo osc_get_preference('network', 'plugin-osclass-escrow') === $network ? 'selected' : ''; ?>>
                                <?php echo $network; ?>
                            </option>
                        <?php } ?>
                    </select>
                </td>
            </tr>
            <tr>
                <td><label for="currency_codes"><?php _e('Accepted currency codes (one per line)', ELEKTRON_ESCROW_DOMAIN); ?></label></td>
                <td>
                    <textarea name="currency_codes" id="currency_codes" rows="2" cols="60"><?php echo osc_esc_html(osc_get_preference('currency_codes', 'plugin-osclass-escrow')); ?></textarea>
                    <p class="description"><?php _e('A listing is only offered escrow if its currency code is one of these. Osclass stores a currency code as exactly 3 letters, so "ELEK" itself can never be one; use a 3-letter contraction such as ELE or ELK instead.', ELEKTRON_ESCROW_DOMAIN); ?></p>
                </td>
            </tr>
            <tr>
                <td><label for="chain_data_endpoints"><?php _e('Chain-data endpoints (one per line, tried in order)', ELEKTRON_ESCROW_DOMAIN); ?></label></td>
                <td>
                    <textarea name="chain_data_endpoints" id="chain_data_endpoints" rows="4" cols="60"><?php echo osc_esc_html(osc_get_preference('chain_data_endpoints', 'plugin-osclass-escrow')); ?></textarea>
                    <p class="description"><?php _e('Esplora/mempool-compatible base URLs, e.g. https://mempool.elektron-net.org/api', ELEKTRON_ESCROW_DOMAIN); ?></p>
                </td>
            </tr>
            <tr>
                <td><label for="required_confirmations"><?php _e('Confirmations required before an order is "funded"', ELEKTRON_ESCROW_DOMAIN); ?></label></td>
                <td><input type="number" min="1" name="required_confirmations" id="required_confirmations" value="<?php echo (int) osc_get_preference('required_confirmations', 'plugin-osclass-escrow'); ?>" /></td>
            </tr>
            <tr>
                <td><label for="buyer_refund_days"><?php _e('Buyer refund threshold, T1 (days)', ELEKTRON_ESCROW_DOMAIN); ?></label></td>
                <td><input type="number" min="1" name="buyer_refund_days" id="buyer_refund_days" value="<?php echo (int) osc_get_preference('buyer_refund_days', 'plugin-osclass-escrow'); ?>" /></td>
            </tr>
            <tr>
                <td><label for="seller_release_days"><?php _e('Seller release threshold, T2 (days)', ELEKTRON_ESCROW_DOMAIN); ?></label></td>
                <td><input type="number" min="1" name="seller_release_days" id="seller_release_days" value="<?php echo (int) osc_get_preference('seller_release_days', 'plugin-osclass-escrow'); ?>" /></td>
            </tr>
        </table>

        <p class="description">
            <?php printf(
                __('T2 must be at least %sx T1; see the plugin README, "Escrow timeout policy", for why.', ELEKTRON_ESCROW_DOMAIN),
                \ElektronNet\Escrow\TimeoutPolicy::MIN_RATIO
            ); ?>
        </p>

        <h3><?php _e('Arbiter', ELEKTRON_ESCROW_DOMAIN); ?></h3>
        <p class="description"><?php _e('With an arbiter, every new deal gets a third key and any two of the three can spend. In a dispute the arbiter decides together with the party in the right. The arbiter can never spend alone. The arbiter wallet is your own node wallet; its private key is never entered here.', ELEKTRON_ESCROW_DOMAIN); ?></p>
        <table class="table">
            <tr>
                <td><?php _e('Arbiter wallet', ELEKTRON_ESCROW_DOMAIN); ?></td>
                <td>
                    <?php if (elektron_escrow_arbiter_xpub() !== '') { ?>
                        <strong><?php _e('Connected', ELEKTRON_ESCROW_DOMAIN); ?></strong>:
                        <?php echo osc_esc_html((string) osc_get_preference('arbiter_wallet_name', 'plugin-osclass-escrow')); ?>
                        (<code><?php echo osc_esc_html((string) osc_get_preference('arbiter_fingerprint', 'plugin-osclass-escrow')); ?></code>)
                    <?php } else { ?>
                        <?php _e('Not connected', ELEKTRON_ESCROW_DOMAIN); ?>
                    <?php } ?>
                </td>
            </tr>
            <tr>
                <td><label for="arbiter_text"><?php _e('Connect or replace the arbiter wallet', ELEKTRON_ESCROW_DOMAIN); ?></label></td>
                <td>
                    <ol>
                        <li><?php _e('In the node wallet open Window, then Console, and choose the wallet that acts as arbiter.', ELEKTRON_ESCROW_DOMAIN); ?></li>
                        <li><?php _e('Run this command:', ELEKTRON_ESCROW_DOMAIN); ?> <code class="elektron-escrow-code">gethdkeys '{"active_only":true}'</code></li>
                        <li><?php _e('Copy the whole result (from the first [ to the last ]) and paste it into the field below. It contains only public keys. A private key is refused.', ELEKTRON_ESCROW_DOMAIN); ?></li>
                    </ol>
                    <textarea name="arbiter_text" id="arbiter_text" rows="6" cols="60" spellcheck="false" autocomplete="off"></textarea>
                    <p><label for="arbiter_wallet_name"><?php _e('Wallet name (as in the console dropdown, optional)', ELEKTRON_ESCROW_DOMAIN); ?></label><br />
                        <input type="text" name="arbiter_wallet_name" id="arbiter_wallet_name" size="40" maxlength="100" value="<?php echo osc_esc_html((string) osc_get_preference('arbiter_wallet_name', 'plugin-osclass-escrow')); ?>" /></p>
                </td>
            </tr>
            <tr>
                <td><label for="arbiter_enabled"><?php _e('Use the arbiter for new deals', ELEKTRON_ESCROW_DOMAIN); ?></label></td>
                <td><input type="checkbox" name="arbiter_enabled" id="arbiter_enabled" value="1" <?php echo osc_get_preference('arbiter_enabled', 'plugin-osclass-escrow') === '1' ? 'checked' : ''; ?> /> <?php _e('Running deals keep what they were created with.', ELEKTRON_ESCROW_DOMAIN); ?></td>
            </tr>
            <tr>
                <td><label for="arbiter_buyer_refund_days"><?php _e('With arbiter: buyer refund threshold, T1 (days)', ELEKTRON_ESCROW_DOMAIN); ?></label></td>
                <td><input type="number" min="1" name="arbiter_buyer_refund_days" id="arbiter_buyer_refund_days" value="<?php echo (int) (osc_get_preference('arbiter_buyer_refund_days', 'plugin-osclass-escrow') ?: 45); ?>" /></td>
            </tr>
            <tr>
                <td><label for="arbiter_seller_release_days"><?php _e('With arbiter: seller release threshold, T2 (days)', ELEKTRON_ESCROW_DOMAIN); ?></label></td>
                <td><input type="number" min="1" name="arbiter_seller_release_days" id="arbiter_seller_release_days" value="<?php echo (int) (osc_get_preference('arbiter_seller_release_days', 'plugin-osclass-escrow') ?: 90); ?>" /></td>
            </tr>
        </table>
        <p class="description"><?php _e('Give the arbiter time: both dates should be well after the expected delivery and the time you need to decide a dispute.', ELEKTRON_ESCROW_DOMAIN); ?></p>

        <input type="submit" class="btn btn-primary" value="<?php echo osc_esc_html(__('Save', ELEKTRON_ESCROW_DOMAIN)); ?>" />
    </form>
</div>
