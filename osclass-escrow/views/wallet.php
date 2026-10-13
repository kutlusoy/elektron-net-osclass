<?php if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');
/** @var array|null $wallet */
/** @var string|null $statusMessage */
/** @var string|null $errorMessage */
/** @var string $returnUrl */

elektron_escrow_table_style();
?>
<div class="elektron-escrow-order">
    <h1><?php _e('My Elektron wallet', ELEKTRON_ESCROW_DOMAIN); ?></h1>

    <p><?php _e('Connect your wallet once. After that, buying and selling with escrow needs no keys or addresses from you: this site derives the public keys for each deal from your wallet.', ELEKTRON_ESCROW_DOMAIN); ?></p>
    <p class="elektron-escrow-next"><strong><?php _e('Only public data is used. No private key is ever needed here.', ELEKTRON_ESCROW_DOMAIN); ?></strong>
        <?php _e('The text below contains public keys only. This site cannot spend your money with them, and it refuses any text that contains a private key.', ELEKTRON_ESCROW_DOMAIN); ?><?php echo elektron_escrow_help('wallet_public'); ?></p>

    <?php if ($statusMessage !== null) { ?>
        <p class="elektron-escrow-success"><?php echo osc_esc_html($statusMessage); ?></p>
        <?php if ($returnUrl !== '') { ?>
            <p><a class="btn btn-primary" href="<?php echo osc_esc_html($returnUrl); ?>"><?php _e('Continue', ELEKTRON_ESCROW_DOMAIN); ?></a></p>
        <?php } ?>
    <?php } ?>
    <?php if ($errorMessage !== null) { ?>
        <p class="elektron-escrow-error"><?php echo osc_esc_html($errorMessage); ?></p>
    <?php } ?>

    <?php if ($wallet !== null) { ?>
        <table class="elektron-escrow-table">
            <tr><td><?php _e('Status', ELEKTRON_ESCROW_DOMAIN); ?></td><td><?php _e('Connected', ELEKTRON_ESCROW_DOMAIN); ?></td></tr>
            <tr><td><?php _e('Wallet name', ELEKTRON_ESCROW_DOMAIN); ?></td><td><?php echo $wallet['s_wallet_name'] ? osc_esc_html($wallet['s_wallet_name']) : osc_esc_html(__('not set', ELEKTRON_ESCROW_DOMAIN)); ?></td></tr>
            <tr><td><?php _e('Wallet id', ELEKTRON_ESCROW_DOMAIN); ?><?php echo elektron_escrow_help('wallet_id'); ?></td><td><code><?php echo osc_esc_html($wallet['s_fingerprint']); ?></code></td></tr>
            <tr><td><?php _e('Payout address', ELEKTRON_ESCROW_DOMAIN); ?><?php echo elektron_escrow_help('payout'); ?></td><td><?php echo $wallet['s_payout_address'] ? '<code>' . osc_esc_html($wallet['s_payout_address']) . '</code>' : osc_esc_html(__('not set', ELEKTRON_ESCROW_DOMAIN)); ?></td></tr>
        </table>
    <?php } ?>

    <form method="post" action="<?php echo osc_route_url('elektron_escrow_wallet'); ?>" id="elektron-escrow-wallet-form" autocomplete="off">
        <?php echo osc_csrf_token_form(); ?>
        <input type="hidden" name="wallet_action" value="connect" />
        <input type="hidden" name="return" value="<?php echo osc_esc_html($returnUrl); ?>" />

        <h2><?php echo $wallet === null ? osc_esc_html(__('Connect your wallet', ELEKTRON_ESCROW_DOMAIN)) : osc_esc_html(__('Replace the connected wallet', ELEKTRON_ESCROW_DOMAIN)); ?></h2>
        <ol>
            <li><?php _e('Open the Elektron Net node wallet, menu Window, then Console. Choose your wallet in the dropdown at the top.', ELEKTRON_ESCROW_DOMAIN); ?><?php echo elektron_escrow_help('console'); ?></li>
            <li><?php _e('Type the command below and press Enter. Do not add "private": that would show the private key.', ELEKTRON_ESCROW_DOMAIN); ?>
                <code class="elektron-escrow-code">gethdkeys {"active_only":true}</code></li>
            <li><?php _e('Select the whole result, copy it and paste it here.', ELEKTRON_ESCROW_DOMAIN); ?></li>
        </ol>
        <p>
            <label for="wallet_text"><?php _e('Result of gethdkeys', ELEKTRON_ESCROW_DOMAIN); ?></label><br />
            <textarea name="wallet_text" id="wallet_text" rows="8" required spellcheck="false" autocomplete="off" style="width:100%;box-sizing:border-box"></textarea>
        </p>
        <p class="elektron-escrow-error" id="elektron-escrow-prv-warning" style="display:none"><?php _e('This text contains a private key. It was removed and nothing was sent. Run the command without "private" and paste that result instead.', ELEKTRON_ESCROW_DOMAIN); ?></p>
        <p>
            <label for="wallet_name"><?php _e('Wallet name (optional)', ELEKTRON_ESCROW_DOMAIN); ?></label><?php echo elektron_escrow_help('wallet_name'); ?><br />
            <input type="text" name="wallet_name" id="wallet_name" size="40" maxlength="100" autocomplete="off"
                   value="<?php echo osc_esc_html($wallet !== null ? (string) $wallet['s_wallet_name'] : ''); ?>" />
        </p>
        <p>
            <label for="payout_address"><?php _e('Payout address (optional)', ELEKTRON_ESCROW_DOMAIN); ?></label><?php echo elektron_escrow_help('payout'); ?><br />
            <input type="text" name="payout_address" id="payout_address" size="70" maxlength="100" autocomplete="off"
                   value="<?php echo osc_esc_html($wallet !== null ? (string) $wallet['s_payout_address'] : ''); ?>" />
        </p>
        <?php echo elektron_escrow_howto('address'); ?>
        <input type="submit" class="btn btn-primary" value="<?php echo osc_esc_html(__('Connect wallet', ELEKTRON_ESCROW_DOMAIN)); ?>" />
    </form>
    <script>
        (function () {
            var form = document.getElementById('elektron-escrow-wallet-form');
            form.addEventListener('submit', function (event) {
                var box = document.getElementById('wallet_text');
                if (/[xyztuv]prv[1-9A-HJ-NP-Za-km-z]{20,}/.test(box.value) || /\b[5KLc9][1-9A-HJ-NP-Za-km-z]{50,51}\b/.test(box.value)) {
                    event.preventDefault();
                    box.value = '';
                    document.getElementById('elektron-escrow-prv-warning').style.display = 'block';
                }
            });
        })();
    </script>

    <?php if ($wallet !== null) { ?>
        <form method="post" action="<?php echo osc_route_url('elektron_escrow_wallet'); ?>">
            <?php echo osc_csrf_token_form(); ?>
            <input type="hidden" name="wallet_action" value="disconnect" />
            <input type="submit" class="btn btn-default" value="<?php echo osc_esc_html(__('Disconnect wallet', ELEKTRON_ESCROW_DOMAIN)); ?>" />
        </form>
    <?php } ?>

    <details>
        <summary><?php _e('What does this site do with it?', ELEKTRON_ESCROW_DOMAIN); ?></summary>
        <p><?php _e('It reads the public master key of your wallet from the text and stores it with your wallet id. For every deal it derives two public keys from it, on a path that your wallet never uses for normal addresses, so nothing clashes with your usual receiving addresses. Whoever knows the public master key can see your addresses and balances but cannot spend. Your private keys stay in your wallet, and only your wallet can sign.', ELEKTRON_ESCROW_DOMAIN); ?></p>
        <p><?php _e('Electrum and other wallets that cannot import an escrow descriptor are not supported for signing. The node wallet is.', ELEKTRON_ESCROW_DOMAIN); ?></p>
    </details>
</div>
