<?php if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');

use ElektronNet\Escrow\DealException;
use ElektronNet\Escrow\DealStatus;

/**
 * Route: elektron-escrow/order?order=<id>
 * The deal page for both parties. Every state change goes through
 * elektron_escrow_deal_perform(), which enforces that only the party whose
 * turn it is can act.
 */

if (!osc_is_web_user_logged_in()) {
    elektron_escrow_render_notice(
        __('Please log in to view this order.', ELEKTRON_ESCROW_DOMAIN),
        osc_user_login_url(),
        __('Log in', ELEKTRON_ESCROW_DOMAIN)
    );
    exit;
}

$dealId = (int) Params::getParam('order');
$dealDao = EscrowDealDAO::newInstance();
$deal = $dealDao->findByPrimaryKey($dealId);
$userId = (int) osc_logged_user_id();

if (!$deal || elektron_escrow_deal_role($deal, $userId) === null) {
    elektron_escrow_render_notice(
        __('Order not found.', ELEKTRON_ESCROW_DOMAIN),
        osc_base_url(),
        __('Back to homepage', ELEKTRON_ESCROW_DOMAIN)
    );
    exit;
}

$statusMessage = null;
$errorMessage = null;
$action = (string) Params::getParam('deal_action');

if ($action !== '') {
    osc_csrf_check();

    if ($action === 'message') {
        $text = trim((string) Params::getParam('message'));
        if ($text !== '') {
            $dealDao->addEvent($dealId, $userId, 'message', mb_substr($text, 0, 2000));
        }
    } elseif ($action === 'check_chain') {
        $before = $deal['status'];
        $deal = elektron_escrow_deal_check_chain($deal);
        $statusMessage = $deal['status'] === $before
            ? __('No change on the chain yet.', ELEKTRON_ESCROW_DOMAIN)
            : __('Status updated from the chain.', ELEKTRON_ESCROW_DOMAIN);
    } else {
        $input = [];
        foreach (['pubkey', 'address', 'psbt', 'txid'] as $field) {
            $input[$field] = (string) Params::getParam($field, false, false);
        }
        try {
            $deal = elektron_escrow_deal_perform($deal, $action, $userId, $input);
            $statusMessage = __('Saved.', ELEKTRON_ESCROW_DOMAIN);
        } catch (DealException $e) {
            $errorMessage = __($e->getMessage(), ELEKTRON_ESCROW_DOMAIN);
        }
    }
} else {
    $deal = elektron_escrow_deal_check_chain($deal);
}

elektron_escrow_render_order_status($deal, $userId, $statusMessage, $errorMessage);
