<?php if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');

/**
 * Route: elektron-escrow/orders
 * Every deal the logged-in user is a party to, as buyer or as seller, each
 * linking to its own deal page. Read-only.
 */

if (!osc_is_web_user_logged_in()) {
    elektron_escrow_render_notice(
        __('Please log in to view your Elektron orders.', ELEKTRON_ESCROW_DOMAIN),
        osc_user_login_url(),
        __('Log in', ELEKTRON_ESCROW_DOMAIN)
    );
    exit;
}

$userId = (int) osc_logged_user_id();
$dealDao = EscrowDealDAO::newInstance();
$purchases = $dealDao->findByBuyer($userId);
$sales = $dealDao->findBySeller($userId);
require osc_plugins_path() . 'osclass-escrow/views/orders.php';
