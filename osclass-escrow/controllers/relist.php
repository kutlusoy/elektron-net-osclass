<?php if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');

/**
 * Route: elektron-escrow/relist (POST)
 * The seller puts a sold listing back on offer, for sellers who have more of the same.
 */

if (!osc_is_web_user_logged_in()) {
    elektron_escrow_render_notice(
        __('Please log in to offer this item again.', ELEKTRON_ESCROW_DOMAIN),
        osc_user_login_url(),
        __('Log in', ELEKTRON_ESCROW_DOMAIN)
    );
    exit;
}

$itemId = (int) Params::getParam('item');
$item = Item::newInstance()->findByPrimaryKey($itemId);
if (!$item || (int) $item['fk_i_user_id'] !== (int) osc_logged_user_id() || Params::getParam('item') === '') {
    elektron_escrow_render_notice(__('Listing not found.', ELEKTRON_ESCROW_DOMAIN), osc_base_url(), __('Back to homepage', ELEKTRON_ESCROW_DOMAIN));
    exit;
}

osc_csrf_check();
EscrowDealDAO::newInstance()->relistItem($itemId);
elektron_escrow_render_notice(__('The item is on offer again.', ELEKTRON_ESCROW_DOMAIN), osc_item_url_from_item($item), __('Back to listing', ELEKTRON_ESCROW_DOMAIN));
