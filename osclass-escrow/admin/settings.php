<?php if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');

use ElektronNet\Escrow\TimeoutPolicy;

/**
 * Admin settings page, rendered via osc_admin_render_plugin_url() (see
 * index.php's "_configure" hook). Every value here maps 1:1 to a
 * 'plugin-osclass-escrow' preference written by install.php; see this
 * plugin's README for the full field list.
 */

$statusMessage = null;
$errorMessage = null;

// The 'save' marker alone decides whether this is a submission; the CSRF
// token field name differs between Osclass-family platforms, so it is not inspected here.
if (Params::getParam('save') === 'elektron_escrow_settings') {
    osc_csrf_check();

    try {
        // Validated up front so a bad admin input can never write a policy
        // that reopens either of the two timeout attacks described in the
        // README ("Why neither party can set these per order").
        TimeoutPolicy::fromDays(
            (int) Params::getParam('buyer_refund_days'),
            (int) Params::getParam('seller_release_days')
        );
        TimeoutPolicy::fromDays(
            (int) Params::getParam('arbiter_buyer_refund_days'),
            (int) Params::getParam('arbiter_seller_release_days')
        );
        $arbiterText = (string) Params::getParam('arbiter_text', false, false);
        if (trim($arbiterText) !== '') {
            elektron_escrow_arbiter_connect($arbiterText, trim((string) Params::getParam('arbiter_wallet_name', false, false)));
        }
        if (Params::getParam('arbiter_enabled') === '1' && elektron_escrow_arbiter_xpub() === '') {
            throw new InvalidArgumentException(__('Connect the arbiter wallet first, then enable the arbiter.', ELEKTRON_ESCROW_DOMAIN));
        }
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
            'arbiter_enabled' => Params::getParam('arbiter_enabled') === '1' ? '1' : '0',
            'arbiter_buyer_refund_days' => (string) (int) Params::getParam('arbiter_buyer_refund_days'),
            'arbiter_seller_release_days' => (string) (int) Params::getParam('arbiter_seller_release_days'),
        ];
        // Preference::replace(), not update(): see install.php's docblock on
        // elektron_escrow_set_default_preferences() for why the DAO-inherited
        // insert()/update() bypass Shopclass's in-memory preference cache.
        foreach ($fields as $name => $value) {
            Preference::newInstance()->replace($name, $value, 'plugin-osclass-escrow', 'STRING');
        }
        $statusMessage = __('Elektron Net Escrow settings updated', ELEKTRON_ESCROW_DOMAIN);
    }
}

// The result is shown in this same response: the admin header is already
// sent by the time this file runs, so a flash message plus redirect would
// fail with "headers already sent".

require osc_plugins_path() . 'osclass-escrow/views/admin-settings.php';
