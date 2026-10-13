<?php if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.'); ?>

<div class="element-inside">
    <h2><?php _e('Elektron Net Escrow settings', ELEKTRON_ESCROW_DOMAIN); ?></h2>

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

        <input type="submit" class="btn btn-primary" value="<?php echo osc_esc_html(__('Save', ELEKTRON_ESCROW_DOMAIN)); ?>" />
    </form>
</div>
