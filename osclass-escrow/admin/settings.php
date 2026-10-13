<?php if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');

use ElektronNet\Escrow\TimeoutPolicy;

/**
 * Admin settings page, rendered via osc_admin_render_plugin_url() (see
 * index.php's "_configure" hook). Every value here maps 1:1 to a
 * 'plugin-osclass-escrow' preference written by install.php; see this
 * plugin's README for the full field list.
 */

// The 'save' marker alone decides whether this is a submission; the CSRF
// token itself is deliberately NOT inspected before calling
// osc_csrf_check(). See controllers/wallet.php for why: hardcoding
// Shopclass's own 'CSRFName' token field name here is an assumption about
// a specific platform's internals that does not hold on every real,
// deployed Osclass-family platform (confirmed live against one that uses
// a single 'octoken' field instead).
if (Params::getParam('save') === 'elektron_escrow_settings') {
    osc_csrf_check();

    $errorMessage = null;
    try {
        // Validated up front so a bad admin input can never write a policy
        // that reopens either of the two timeout attacks described in the
        // README ("Why neither party can set these per order").
        TimeoutPolicy::fromDays(
            (int) Params::getParam('buyer_refund_days'),
            (int) Params::getParam('seller_release_days')
        );
    } catch (InvalidArgumentException $e) {
        $errorMessage = $e->getMessage();
    }

    if ($errorMessage === null) {
        $fields = [
            'network' => Params::getParam('network'),
            'currency_codes' => Params::getParam('currency_codes'),
            'chain_data_endpoints' => Params::getParam('chain_data_endpoints'),
            'required_confirmations' => (string) (int) Params::getParam('required_confirmations'),
            'buyer_refund_days' => (string) (int) Params::getParam('buyer_refund_days'),
            'seller_release_days' => (string) (int) Params::getParam('seller_release_days'),
        ];
        // Preference::replace(), not update(): see install.php's docblock on
        // elektron_escrow_set_default_preferences() for why the DAO-inherited
        // insert()/update() bypass Shopclass's in-memory preference cache.
        foreach ($fields as $name => $value) {
            Preference::newInstance()->replace($name, $value, 'plugin-osclass-escrow', 'STRING');
        }
        osc_add_flash_ok_message(__('Elektron Net Escrow settings updated', ELEKTRON_ESCROW_DOMAIN), 'admin');
    } else {
        osc_add_flash_error_message($errorMessage, 'admin');
    }

    osc_redirect_to(osc_admin_render_plugin_url('osclass-escrow/admin/settings.php'));
    exit;
}

require osc_plugins_path() . 'osclass-escrow/views/admin-settings.php';
