<?php if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');

use ElektronNet\Payments\Core\Escrow\OrderStateMachine;
use ElektronNet\Payments\Core\Escrow\OrderStatus;
use ElektronNet\Payments\Core\Escrow\TimeoutPolicy;
use ElektronNet\Payments\Core\Psbt\PsbtRelay;
use ElektronNet\Payments\Core\Psbt\PsbtSignatureInspector;

/**
 * Route: elektron-escrow/order?order=<id>
 * The permanent details page for one order: item, amount, and (per status)
 * the payment address + QR code, countdown, or "confirm receipt" action, to
 * whichever of the two parties is looking at it. This is the one page an
 * order's details stay reachable from after checkout ends -- see this
 * plugin's README, "Routes" and "Order lifecycle".
 */

if (!osc_is_web_user_logged_in()) {
    elektron_escrow_render_notice(
        __('Please log in to view this order.', ELEKTRON_ESCROW_DOMAIN),
        osc_user_login_url(),
        __('Log in', ELEKTRON_ESCROW_DOMAIN)
    );
    exit;
}

$orderId = (int) Params::getParam('order');
$orderDao = EscrowOrderDAO::newInstance();
$order = $orderDao->findByPrimaryKey($orderId);
$userId = (int) osc_logged_user_id();

if (!$order || ($userId !== (int) $order['fk_i_buyer_id'] && $userId !== (int) $order['fk_i_seller_id'])) {
    elektron_escrow_render_notice(
        __('Order not found.', ELEKTRON_ESCROW_DOMAIN),
        osc_base_url(),
        __('Back to homepage', ELEKTRON_ESCROW_DOMAIN)
    );
    exit;
}

$isBuyer = $userId === (int) $order['fk_i_buyer_id'];
$statusMessage = null;
$errorMessage = null;

// Either party can trigger this: it only ever reads the chain and, if
// justified, advances this order's own status -- there is nothing
// buyer/seller-specific about asking "has this been paid yet?". See
// elektron_escrow_check_payment()'s docblock (includes/payment-watcher.php)
// for why this manual action exists alongside the 'cron_minutely' watcher.
if (Params::getParam('check_payment') === '1') {
    osc_csrf_check();

    $statusBefore = $order['status'];
    $order = elektron_escrow_check_payment($order);
    $statusMessage = $order['status'] === $statusBefore
        ? __('No new payment detected yet.', ELEKTRON_ESCROW_DOMAIN)
        : __('Payment status updated.', ELEKTRON_ESCROW_DOMAIN);
}

// Buyer-only, and only while still awaiting_payment: cancelling deletes the
// order outright rather than moving it to some new "cancelled" status (see
// EscrowOrderDAO::cancelByBuyer()'s docblock), so there is nothing left to
// render as an order-status page afterward -- a notice is shown instead.
// The buyer/seller emails are sent with the item and order data fetched
// *before* the row is gone, since the DAO method itself only ever returns
// whether the delete happened, not the row it deleted.
if ($isBuyer && Params::getParam('cancel_order') === '1') {
    osc_csrf_check();

    if ($orderDao->cancelByBuyer($orderId, $userId)) {
        $item = Item::newInstance()->findByPrimaryKey((int) $order['fk_i_item_id']);
        if ($item !== false) {
            elektron_escrow_send_order_cancelled_emails($order, $item);
        }

        elektron_escrow_render_notice(
            __('This order has been cancelled.', ELEKTRON_ESCROW_DOMAIN),
            osc_route_url('elektron_escrow_orders'),
            __('Back to your orders', ELEKTRON_ESCROW_DOMAIN)
        );
        exit;
    }

    // Did not delete anything. Re-check what actually happened rather than
    // assume why: either the order moved past awaiting_payment (e.g. the
    // payment watcher, includes/hooks.php's 'cron_minutely' hook, saw a
    // payment arrive between this page loading and the cancel button being
    // pressed), or it is already gone (a double-submitted cancel: this same
    // buyer's own first request already deleted it a moment earlier).
    $order = $orderDao->findByPrimaryKey($orderId);
    if ($order === false) {
        elektron_escrow_render_notice(
            __('This order has been cancelled.', ELEKTRON_ESCROW_DOMAIN),
            osc_route_url('elektron_escrow_orders'),
            __('Back to your orders', ELEKTRON_ESCROW_DOMAIN)
        );
        exit;
    }
    $errorMessage = __('This order can no longer be cancelled.', ELEKTRON_ESCROW_DOMAIN);
}


// Seller confirms shipment (only from FUNDED)
if (!$isBuyer && Params::getParam('mark_shipped') === '1') {
    osc_csrf_check();
    if (OrderStateMachine::canTransition($order['status'], OrderStatus::SHIPPED)) {
        $orderDao->updateByPrimaryKey(
            ['status' => OrderStatus::SHIPPED, 'dt_mod_date' => date('Y-m-d H:i:s')],
            $orderId
        );
        $order['status'] = OrderStatus::SHIPPED;
        $statusMessage = __('Shipment confirmed. Waiting for the buyer to confirm receipt.', ELEKTRON_ESCROW_DOMAIN);
    }
}

// The 'confirm_receipt' marker alone decides whether this is a
// submission; the CSRF token itself is deliberately NOT inspected before
// calling osc_csrf_check(). See controllers/wallet.php for why: an
// earlier version also required Params::getParam('CSRFName') !== '',
// which hardcoded Shopclass's own token field name as an assumption about
// a different real, deployed platform's internals, confirmed wrong live
// (that platform's actual field is a single 'octoken' input). Renders
// directly into this same response rather than osc_add_flash_*_message()
// + a redirect: see includes/notice.php's docblock for why a same-request
// render, not a redirect, is what actually works here.
if ($isBuyer && Params::getParam('confirm_receipt') === '1') {
    osc_csrf_check();

    if ($order['status'] !== OrderStatus::SHIPPED || !OrderStateMachine::canTransition($order['status'], OrderStatus::RELEASE_PENDING_SELLER_SIGNATURE)) {
        // Nothing to do: wrong status for this action (e.g. a stale page,
        // double submit, or a terminal order). No error shown, matching
        // this action's existing silent-no-op behavior for that case.
    } elseif (!TimeoutPolicy::canSafelyStartRelease((int) $order['buyer_refund_locktime'], time())) {
        // See TimeoutPolicy::canSafelyStartRelease's docblock: refused, not
        // merely warned about, because "confirm receipt" has no on-chain
        // effect -- the buyer's own refund path stays spendable by the
        // buyer alone from T1 onward no matter what this plugin's database
        // says, so starting a release this close to T1 could tell the
        // seller funds are coming while the buyer can still reclaim them.
        $errorMessage = elektron_escrow_t('order.confirm_receipt.too_close_to_refund');
    } else {
        $updateFields = ['status' => OrderStatus::RELEASE_PENDING_SELLER_SIGNATURE, 'dt_mod_date' => date('Y-m-d H:i:s')];

        // Best-effort: building the PSBT needs a live chain-data lookup for
        // the exact funding outpoint (see includes/psbt.php's docblock for
        // the cases this can fail on, e.g. a split payment). A failure here
        // still lets the order move to RELEASE_PENDING_SELLER_SIGNATURE --
        // the view falls back to the raw redeem script for that rare case
        // rather than blocking the buyer's "confirm receipt" action on it.
        try {
            $relay = elektron_escrow_build_release_psbt($order);
            $updateFields['psbt_base64'] = $relay->base64();
            $updateFields['psbt_signature_count'] = $relay->signatureCount();
        } catch (\Throwable $e) {
            // Fall through with no PSBT; see this block's own comment above.
            // Logged (not silently swallowed) since this is meant to be the
            // rare case, not something to lose visibility into.
            error_log('elektron-escrow: could not build release PSBT for order ' . $orderId . ': ' . $e);
        }

        $orderDao->updateByPrimaryKey($updateFields, $orderId);
        // Reflects the just-applied change locally so the view below
        // renders the new status without a second database round trip.
        $order = array_merge($order, $updateFields);
        $statusMessage = __('Receipt confirmed.', ELEKTRON_ESCROW_DOMAIN);
    }
}

// Either party can trigger this: once an order is in
// RELEASE_PENDING_SELLER_SIGNATURE with no PSBT (the rare
// elektron_escrow_build_release_psbt() failure case above), there was
// previously no way to ever get one -- the state machine only builds it on
// the FUNDED -> RELEASE_PENDING_SELLER_SIGNATURE transition itself, which
// this order has already made. This lets either side ask for another
// attempt (e.g. after a split payment fully confirms, or a chain-data
// endpoint recovers) without falling back to the manual redeem-script path
// for good.
if (Params::getParam('retry_psbt') === '1') {
    osc_csrf_check();

    if ($order['status'] === OrderStatus::RELEASE_PENDING_SELLER_SIGNATURE && $order['psbt_base64'] === null) {
        try {
            $relay = elektron_escrow_build_release_psbt($order);
            $updateFields = [
                'psbt_base64' => $relay->base64(),
                'psbt_signature_count' => $relay->signatureCount(),
                'dt_mod_date' => date('Y-m-d H:i:s'),
            ];
            $orderDao->updateByPrimaryKey($updateFields, $orderId);
            $order = array_merge($order, $updateFields);
            $statusMessage = __('The release transaction was prepared. Please continue below.', ELEKTRON_ESCROW_DOMAIN);
        } catch (\Throwable $e) {
            error_log('elektron-escrow: could not build release PSBT for order ' . $orderId . ': ' . $e);
            $errorMessage = __('Still could not prepare the release transaction automatically. Please try again later, or cooperate manually using the redeem script below.', ELEKTRON_ESCROW_DOMAIN);
        }
    }
}

// Buyer-only: the buyer is the first of the two parties to sign the release
// PSBT (see OrderStatus::RELEASE_PENDING_SELLER_SIGNATURE's own docblock --
// by the time it is "pending the seller's signature", the buyer's is
// already supposed to be on it), done entirely in the buyer's own wallet
// (see the README, "Trustless by design": the platform never signs). This
// only relays what the buyer's wallet already produced; PsbtSignatureInspector
// is what actually checks it did not change anything besides adding a
// signature under the buyer's own pubkey before this plugin ever shows it to
// the seller.
if ($isBuyer && Params::getParam('submit_signed_psbt') === '1') {
    osc_csrf_check();

    $submitted = trim((string) Params::getParam('signed_psbt'));

    if ($order['status'] !== OrderStatus::RELEASE_PENDING_SELLER_SIGNATURE
        || $order['psbt_base64'] === null
        || (int) $order['psbt_signature_count'] > 0
    ) {
        // Stale page, double submit, or already past this step: silent
        // no-op, matching this page's existing convention for that case
        // (see the 'confirm_receipt' block above).
    } elseif ($submitted === '') {
        $errorMessage = __('Please paste your signed PSBT.', ELEKTRON_ESCROW_DOMAIN);
    } else {
        $signatureCount = null;
        try {
            $signatureCount = PsbtSignatureInspector::countSignatures($submitted, $order['psbt_base64']);
        } catch (\Throwable $e) {
            // Falls through to the generic error message below; the
            // specific reason (wrong tx, tampered field, wrong pubkey) is
            // not shown to the user -- none of them are actionable
            // information for a buyer pasting from their own wallet, only
            // "try again with the PSBT shown above" is.
        }

        if ($signatureCount === null || $signatureCount < 1) {
            $errorMessage = __('This does not look like a signed version of the release PSBT shown below. Please sign the exact PSBT shown here in your own wallet and paste the result back.', ELEKTRON_ESCROW_DOMAIN);
        } else {
            $relay = new PsbtRelay($submitted, $signatureCount);
            $orderDao->updateByPrimaryKey(
                ['psbt_base64' => $relay->base64(), 'psbt_signature_count' => $relay->signatureCount(), 'dt_mod_date' => date('Y-m-d H:i:s')],
                $orderId
            );
            $order['psbt_base64'] = $relay->base64();
            $order['psbt_signature_count'] = $relay->signatureCount();
            $statusMessage = __('Your signature was added. The seller can now sign and broadcast the release from their own wallet.', ELEKTRON_ESCROW_DOMAIN);
        }
    }
}


// Buyer submits signed refund PSBT (T1 unilateral exit)
if ($isBuyer && Params::getParam('submit_signed_refund') === '1') {
    osc_csrf_check();
    $submitted = trim((string) Params::getParam('signed_refund_psbt'));
    if (empty($order['buyer_refund_psbt_base64'])) {
        $errorMessage = __('No refund PSBT is available yet.', ELEKTRON_ESCROW_DOMAIN);
    } elseif ($submitted === '') {
        $errorMessage = __('Please paste your signed refund PSBT.', ELEKTRON_ESCROW_DOMAIN);
    } else {
        try {
            $sigCount = PsbtSignatureInspector::countSignatures($submitted, $order['buyer_refund_psbt_base64']);
            if ($sigCount < 1) {
                throw new RuntimeException('no signature');
            }
            $orderDao->updateByPrimaryKey(
                ['buyer_refund_psbt_base64' => $submitted, 'dt_mod_date' => date('Y-m-d H:i:s')],
                $orderId
            );
            $order['buyer_refund_psbt_base64'] = $submitted;
            $statusMessage = __('Your refund signature was saved. You can broadcast this transaction after T1 if needed.', ELEKTRON_ESCROW_DOMAIN);
        } catch (Throwable $e) {
            $errorMessage = __('This does not look like a signed version of the refund PSBT. Please sign the exact one shown below.', ELEKTRON_ESCROW_DOMAIN);
        }
    }
}

// Seller submits signed claim PSBT (T2 unilateral exit)
if (!$isBuyer && Params::getParam('submit_signed_claim') === '1') {
    osc_csrf_check();
    $submitted = trim((string) Params::getParam('signed_claim_psbt'));
    if (empty($order['seller_claim_psbt_base64'])) {
        $errorMessage = __('No claim PSBT is available yet.', ELEKTRON_ESCROW_DOMAIN);
    } elseif ($submitted === '') {
        $errorMessage = __('Please paste your signed claim PSBT.', ELEKTRON_ESCROW_DOMAIN);
    } else {
        try {
            $sigCount = PsbtSignatureInspector::countSignatures($submitted, $order['seller_claim_psbt_base64']);
            if ($sigCount < 1) {
                throw new RuntimeException('no signature');
            }
            $orderDao->updateByPrimaryKey(
                ['seller_claim_psbt_base64' => $submitted, 'dt_mod_date' => date('Y-m-d H:i:s')],
                $orderId
            );
            $order['seller_claim_psbt_base64'] = $submitted;
            $statusMessage = __('Your claim signature was saved. You can broadcast this transaction after T2 if needed.', ELEKTRON_ESCROW_DOMAIN);
        } catch (Throwable $e) {
            $errorMessage = __('This does not look like a signed version of the claim PSBT. Please sign the exact one shown below.', ELEKTRON_ESCROW_DOMAIN);
        }
    }
}

// Seller-only: records that the cooperative release has actually happened
// (both parties signed and broadcast a spend from the escrow address to the
// seller's own wallet, outside this plugin -- see ReleasePsbtBuilderInterface's
// docblock in ../core: there is no reference implementation yet, so this
// plugin cannot build or relay that PSBT itself today). This is a pure
// database status change with no on-chain effect of its own; it exists
// because RELEASE_PENDING_SELLER_SIGNATURE otherwise has no way to ever
// reach RELEASED at all, even after a real, successful release.
if (!$isBuyer && Params::getParam('mark_released') === '1') {
    osc_csrf_check();

    if (OrderStateMachine::canTransition($order['status'], OrderStatus::RELEASED)) {
        $orderDao->updateByPrimaryKey(
            ['status' => OrderStatus::RELEASED, 'dt_mod_date' => date('Y-m-d H:i:s')],
            $orderId
        );
        $order['status'] = OrderStatus::RELEASED;
        $statusMessage = __('Order marked as released.', ELEKTRON_ESCROW_DOMAIN);
    }
}

elektron_escrow_render_order_status($order, $userId, $statusMessage, $errorMessage);
