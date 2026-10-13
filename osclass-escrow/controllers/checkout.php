<?php if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');

use ElektronNet\Escrow\DealException;
use ElektronNet\Escrow\DealStatus;

/**
 * Route: elektron-escrow/checkout?item=<id>
 * GET shows a preview and creates nothing. Only a POST with
 * 'confirm_checkout' and a valid CSRF token creates the deal, which is then
 * rendered in the same response (a redirect from a custom-route controller
 * fails once the theme header was sent, see includes/notice.php).
 */

if (!osc_is_web_user_logged_in()) {
    elektron_escrow_render_notice(
        __('Please log in to buy with Elektron.', ELEKTRON_ESCROW_DOMAIN),
        osc_user_login_url(),
        __('Log in', ELEKTRON_ESCROW_DOMAIN)
    );
    exit;
}

$itemId = (int) Params::getParam('item');
$item = Item::newInstance()->findByPrimaryKey($itemId);
$buyerId = (int) osc_logged_user_id();

if (!$item) {
    elektron_escrow_render_notice(
        __('This listing does not exist.', ELEKTRON_ESCROW_DOMAIN),
        osc_base_url(),
        __('Back to homepage', ELEKTRON_ESCROW_DOMAIN)
    );
    exit;
}

if ($item['fk_i_user_id'] == $buyerId) {
    elektron_escrow_render_notice(
        __('You cannot buy your own listing.', ELEKTRON_ESCROW_DOMAIN),
        osc_item_url_from_item($item),
        __('Back to listing', ELEKTRON_ESCROW_DOMAIN)
    );
    exit;
}

// The "Buy" link is already hidden for an ineligible currency, but this route is reachable directly.
if (!in_array(strtoupper((string) ($item['fk_c_currency_code'] ?? '')), elektron_escrow_currency_codes(), true)) {
    elektron_escrow_render_notice(
        __('This listing is not priced in a currency accepted for escrow.', ELEKTRON_ESCROW_DOMAIN),
        osc_item_url_from_item($item),
        __('Back to listing', ELEKTRON_ESCROW_DOMAIN)
    );
    exit;
}

$amountElek = elektron_escrow_item_price_elek($item);
if ($amountElek === null) {
    elektron_escrow_render_notice(
        __('This listing has no fixed price, so it cannot be bought with escrow.', ELEKTRON_ESCROW_DOMAIN),
        osc_item_url_from_item($item),
        __('Back to listing', ELEKTRON_ESCROW_DOMAIN)
    );
    exit;
}

$walletDao = EscrowWalletDAO::newInstance();
if ($walletDao->forUser($buyerId) === null) {
    elektron_escrow_render_notice(
        __('Connect your wallet once before you buy with escrow. It takes a minute and needs no private key.', ELEKTRON_ESCROW_DOMAIN),
        osc_route_url('elektron_escrow_wallet', ['return' => osc_route_url('elektron_escrow_checkout', ['item' => $itemId])]),
        __('Connect wallet', ELEKTRON_ESCROW_DOMAIN)
    );
    exit;
}
if ($walletDao->forUser((int) $item['fk_i_user_id']) === null) {
    elektron_escrow_render_notice(
        __('The seller has not connected a wallet for escrow yet. Ask them to do so, then try again.', ELEKTRON_ESCROW_DOMAIN),
        osc_item_url_from_item($item),
        __('Back to listing', ELEKTRON_ESCROW_DOMAIN)
    );
    exit;
}

$dealDao = EscrowDealDAO::newInstance();
$existingDeal = $dealDao->findOpenByItemAndBuyer($itemId, $buyerId);
if ($existingDeal !== null && empty($existingDeal['escrow_address'])) {
    // A deal whose escrow was never fixed has nothing to pay and is dropped, so the buyer can start again.
    $dealDao->delete(['pk_i_id' => (int) $existingDeal['pk_i_id']]);
    $existingDeal = null;
}
if ($existingDeal !== null) {
    elektron_escrow_render_order_status($existingDeal, $buyerId);
    exit;
}

$availability = elektron_escrow_item_state($itemId);
if ($availability['state'] !== 'free') {
    elektron_escrow_render_notice(
        $availability['state'] === 'reserved'
            ? __('This item is reserved: another buyer has started an Elektron purchase of it.', ELEKTRON_ESCROW_DOMAIN)
            : __('This item is sold.', ELEKTRON_ESCROW_DOMAIN),
        osc_item_url_from_item($item),
        __('Back to listing', ELEKTRON_ESCROW_DOMAIN)
    );
    exit;
}

$config = elektron_escrow_config();
$buyer = User::newInstance()->findByPrimaryKey($buyerId);
$buyerEmail = $buyer !== false ? (string) $buyer['s_email'] : '';
$errorMessage = null;

if (Params::getParam('confirm_checkout') === '1') {
    osc_csrf_check();

    $shippingName = trim((string) Params::getParam('shipping_name'));
    $shippingAddress = trim((string) Params::getParam('shipping_address'));
    $shippingPhone = trim((string) Params::getParam('shipping_phone'));
    $contactEmail = trim((string) Params::getParam('contact_email'));

    if ($shippingName === '' || $shippingAddress === '' || $shippingPhone === '' || $contactEmail === '') {
        $errorMessage = __('Please fill in all shipping details.', ELEKTRON_ESCROW_DOMAIN);
    } elseif (!filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
        $errorMessage = __('Please enter a valid contact email.', ELEKTRON_ESCROW_DOMAIN);
    }

    if ($errorMessage !== null) {
        $timeoutPolicy = elektron_escrow_arbiter_enabled() ? elektron_escrow_arbiter_timeouts() : $config->timeoutPolicy();
        require osc_plugins_path() . 'osclass-escrow/views/checkout.php';
        exit;
    }

    $sellerId = (int) $item['fk_i_user_id'];
    $dealId = 0;
    try {
        $dealId = $dealDao->insertDeal([
            'fk_i_item_id' => $itemId,
            'fk_i_buyer_id' => $buyerId,
            'fk_i_seller_id' => $sellerId,
            'amount_lep' => (int) round($amountElek * 100000000),
            'status' => DealStatus::AWAITING_PAYMENT,
            'buyer_shipping_name' => $shippingName,
            'buyer_shipping_address' => $shippingAddress,
            'buyer_shipping_phone' => $shippingPhone,
            'buyer_contact_email' => $contactEmail,
            'seller_payout_address' => $walletDao->forUser($sellerId)['s_payout_address'],
            'dt_pub_date' => date('Y-m-d H:i:s'),
        ]);
        // The keys depend on the deal number, so the row comes first and the escrow is fixed right after.
        $withArbiter = elektron_escrow_arbiter_enabled();
        $escrowFields = elektron_escrow_workflow($withArbiter)->create(
            elektron_escrow_keys_for($sellerId, $dealId),
            elektron_escrow_keys_for($buyerId, $dealId),
            time(),
            $withArbiter ? elektron_escrow_arbiter_keys($dealId)['main'] : null
        );
    } catch (\Throwable $e) {
        if ($dealId > 0) {
            $dealDao->delete(['pk_i_id' => $dealId]);
        }
        $errorMessage = $e instanceof DealException ? __($e->getMessage(), ELEKTRON_ESCROW_DOMAIN) : __('The escrow could not be prepared. Reconnect your wallet and try again.', ELEKTRON_ESCROW_DOMAIN);
        $timeoutPolicy = elektron_escrow_arbiter_enabled() ? elektron_escrow_arbiter_timeouts() : $config->timeoutPolicy();
        require osc_plugins_path() . 'osclass-escrow/views/checkout.php';
        exit;
    }
    // Two buyers can start at the same moment: the older deal keeps the item.
    $holder = $dealDao->findOpenByItem($itemId);
    if ($holder !== null && (int) $holder['pk_i_id'] !== $dealId) {
        $dealDao->delete(['pk_i_id' => $dealId]);
        elektron_escrow_render_notice(
            __('This item is reserved: another buyer has started an Elektron purchase of it.', ELEKTRON_ESCROW_DOMAIN),
            osc_item_url_from_item($item),
            __('Back to listing', ELEKTRON_ESCROW_DOMAIN)
        );
        exit;
    }
    $dealDao->updateByPrimaryKey($escrowFields, $dealId);
    $deal = $dealDao->findByPrimaryKey($dealId);
    if (!$deal || empty($deal['escrow_address'])) {
        $dealDao->delete(['pk_i_id' => $dealId]);
        $errorMessage = __('The escrow could not be prepared. Reconnect your wallet and try again.', ELEKTRON_ESCROW_DOMAIN);
        $timeoutPolicy = elektron_escrow_arbiter_enabled() ? elektron_escrow_arbiter_timeouts() : $config->timeoutPolicy();
        require osc_plugins_path() . 'osclass-escrow/views/checkout.php';
        exit;
    }
    $dealDao->addEvent($dealId, $buyerId, 'created');
    $dealDao->addEvent($dealId, 0, 'escrow_fixed');
    elektron_escrow_send_turn_email($deal, true);
    elektron_escrow_render_order_status($deal, $buyerId);
    exit;
}

$timeoutPolicy = elektron_escrow_arbiter_enabled() ? elektron_escrow_arbiter_timeouts() : $config->timeoutPolicy();
require osc_plugins_path() . 'osclass-escrow/views/checkout.php';
