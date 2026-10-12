<?php if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');
/** @var array $order */
/** @var array|false $item Osclass item row, or false if the listing was since removed (see DAO::findByPrimaryKey()) */
/** @var float $amountElek */
/** @var int $requiredConfirmations */
/** @var bool $isBuyer */
/** @var string|null $statusMessage */
/** @var string|null $errorMessage */

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use ElektronNet\Payments\Core\Escrow\OrderStatus;

$now = time();
$pastT1 = $now >= (int) $order['buyer_refund_locktime'];
$showPaymentDetails = $order['status'] === OrderStatus::AWAITING_PAYMENT || $order['status'] === OrderStatus::CONFIRMING;

elektron_escrow_table_style();
?>

<div class="elektron-escrow-order">
    <h1><?php _e('Elektron order', ELEKTRON_ESCROW_DOMAIN); ?> #<?php echo (int) $order['pk_i_id']; ?></h1>

    <table class="elektron-escrow-table">
        <tr>
            <td><?php _e('Item', ELEKTRON_ESCROW_DOMAIN); ?></td>
            <td>
                <?php if ($item !== false) { ?>
                    <a href="<?php echo osc_esc_html(osc_item_url_from_item($item)); ?>"><?php echo osc_esc_html($item['s_title']); ?></a>
                <?php } else { ?>
                    <?php _e('(listing removed)', ELEKTRON_ESCROW_DOMAIN); ?>
                <?php } ?>
            </td>
        </tr>
        <tr>
            <td><?php _e('Amount', ELEKTRON_ESCROW_DOMAIN); ?></td>
            <td><?php echo osc_esc_html(elektron_escrow_format_amount($amountElek)); ?> ELEK</td>
        </tr>
        <tr>
            <td><?php _e('Status', ELEKTRON_ESCROW_DOMAIN); ?></td>
            <td class="elektron-escrow-status" data-status="<?php echo osc_esc_html($order['status']); ?>"><?php echo osc_esc_html(elektron_escrow_status_label($order['status'])); ?></td>
        </tr>
    </table>

    <?php if ($statusMessage !== null) { ?>
        <p class="elektron-escrow-success"><?php echo osc_esc_html($statusMessage); ?></p>
    <?php } ?>

    <?php if ($errorMessage !== null) { ?>
        <p class="elektron-escrow-error"><?php echo osc_esc_html($errorMessage); ?></p>
    <?php } ?>

    <?php
    // Prominent "Next step" box — role- and status-aware so both parties
    // immediately see what they need to do after the initial payment.
    $nextStep = null;
    $nextStepClass = 'elektron-escrow-next';
    if ($order['status'] === OrderStatus::AWAITING_PAYMENT || $order['status'] === OrderStatus::CONFIRMING) {
        if ($isBuyer) {
            $nextStep = __('Pay the amount shown to the escrow address (QR code below). Once confirmed, the seller will see your shipping details.', ELEKTRON_ESCROW_DOMAIN);
        } else {
            $nextStep = __('Waiting for the buyer to pay. You will receive the shipping details once the payment is confirmed.', ELEKTRON_ESCROW_DOMAIN);
        }
    } elseif ($order['status'] === OrderStatus::FUNDED) {
        if ($isBuyer) {
            $nextStep = __('Payment confirmed. Waiting for the seller to confirm shipment.', ELEKTRON_ESCROW_DOMAIN);
        } else {
            $nextStep = __('Payment confirmed. Ship the goods, then click "I have shipped" below.', ELEKTRON_ESCROW_DOMAIN);
        }
    } elseif ($order['status'] === OrderStatus::SHIPPED) {
        if ($isBuyer) {
            $nextStep = __('Seller has shipped. When you receive the goods, click "I have received" below.', ELEKTRON_ESCROW_DOMAIN);
        } else {
            $nextStep = __('You confirmed shipment. Waiting for the buyer to confirm receipt.', ELEKTRON_ESCROW_DOMAIN);
        }
    } elseif ($order['status'] === OrderStatus::RELEASE_PENDING_SELLER_SIGNATURE) {
        if ($isBuyer) {
            if ((int)($order['psbt_signature_count'] ?? 0) < 1) {
                $nextStep = __('Sign the release transaction in your wallet (QR / text below), then paste the signed version back here.', ELEKTRON_ESCROW_DOMAIN);
            } else {
                $nextStep = __('You already signed. Waiting for the seller to sign and broadcast the release.', ELEKTRON_ESCROW_DOMAIN);
            }
        } else {
            if ((int)($order['psbt_signature_count'] ?? 0) < 1) {
                $nextStep = __('Waiting for the buyer to sign the release first.', ELEKTRON_ESCROW_DOMAIN);
            } else {
                $nextStep = __('Scan the QR code below (or copy the text) into your wallet, add your signature and broadcast. Then mark the order as released.', ELEKTRON_ESCROW_DOMAIN);
            }
        }
    } elseif (in_array($order['status'], OrderStatus::TERMINAL, true)) {
        $nextStep = __('This order is finished.', ELEKTRON_ESCROW_DOMAIN);
        $nextStepClass .= ' elektron-escrow-next--done';
    }
    ?>
    <?php if ($nextStep !== null) { ?>
        <div class="<?php echo $nextStepClass; ?>" style="border:1px solid #4a90d9; background:#f0f7ff; padding:1em; margin:1em 0; border-radius:6px;">
            <strong><?php _e('Next step', ELEKTRON_ESCROW_DOMAIN); ?>:</strong>
            <p style="margin:0.4em 0 0;"><?php echo osc_esc_html($nextStep); ?></p>
        </div>
    
    <?php if (!$isBuyer && $order['status'] === OrderStatus::FUNDED) { ?>
        <form method="post" style="margin:1em 0;">
            <?php echo osc_csrf_token_form(); ?>
            <input type="hidden" name="mark_shipped" value="1" />
            <button type="submit" class="btn btn-primary" style="font-size:1.2em; padding:0.6em 1.4em;">
                <?php _e('I have shipped', ELEKTRON_ESCROW_DOMAIN); ?>
            </button>
        </form>
    <?php } ?>

<?php } ?>

    <?php
    // Only shown to the seller, and only once payment is actually
    // confirmed (i.e. no longer awaiting_payment/confirming) -- see
    // controllers/checkout.php for why this is collected per order rather
    // than once on the buyer's profile, and includes/mail.php for the
    // same details sent in the 'funded' email.
    ?>
    <?php if (!$isBuyer && !$showPaymentDetails) { ?>
        <h2><?php _e('Shipping details', ELEKTRON_ESCROW_DOMAIN); ?></h2>
        <table class="elektron-escrow-table">
            <tr>
                <td><?php _e('Full name', ELEKTRON_ESCROW_DOMAIN); ?></td>
                <td><?php echo osc_esc_html($order['buyer_shipping_name']); ?></td>
            </tr>
            <tr>
                <td><?php _e('Shipping address', ELEKTRON_ESCROW_DOMAIN); ?></td>
                <td style="white-space: pre-wrap;"><?php echo osc_esc_html($order['buyer_shipping_address']); ?></td>
            </tr>
            <tr>
                <td><?php _e('Phone number', ELEKTRON_ESCROW_DOMAIN); ?></td>
                <td><?php echo osc_esc_html($order['buyer_shipping_phone']); ?></td>
            </tr>
            <tr>
                <td><?php _e('Contact email for this order', ELEKTRON_ESCROW_DOMAIN); ?></td>
                <td><?php echo osc_esc_html($order['buyer_contact_email']); ?></td>
            </tr>
        </table>
    <?php } ?>

    <?php
    // Payment details (address, QR code, payment URI) and the "send X ELEK"
    // instruction are buyer-only: the seller has nothing to pay and no
    // action to take yet, so showing them the buyer's own payment
    // instructions here was confusing -- a real install showed the seller
    // "the same payment page as the buyer". The seller instead gets a
    // status message written for them (MessageCatalog's
    // 'order.awaiting_payment.seller' / 'order.confirming.seller', see
    // core/src/I18n/MessageCatalog.php), with no payment details at all.
    ?>
    <?php if ($showPaymentDetails && $isBuyer) { ?>
        <?php
        // Encodes the full elek: payment URI (address + amount), not just
        // the bare address, so a wallet that understands the scheme can
        // prefill both from one scan. Scheme confirmed against the actual
        // Elektron Net wallet fork's own source, not invented here -- see
        // elektron_escrow_payment_uri()'s docblock (includes/formatting.php).
        $paymentUri = elektron_escrow_payment_uri($order['s_address'], $amountElek, (int) $order['pk_i_id']);
        $qrCode = new QrCode($paymentUri);
        $qrPngBase64 = base64_encode((new PngWriter())->write($qrCode)->getString());
        ?>
        <img src="data:image/png;base64,<?php echo $qrPngBase64; ?>" alt="<?php echo osc_esc_html($paymentUri); ?>" />

        <p><?php _e('Address', ELEKTRON_ESCROW_DOMAIN); ?></p>
        <code class="elektron-escrow-code"><?php echo osc_esc_html($order['s_address']); ?></code>

        <p><label for="elektron-escrow-uri-<?php echo (int) $order['pk_i_id']; ?>"><?php _e('Payment URI (tap to select, then copy)', ELEKTRON_ESCROW_DOMAIN); ?></label></p>
        <input type="text" id="elektron-escrow-uri-<?php echo (int) $order['pk_i_id']; ?>" class="elektron-escrow-code" readonly="readonly" value="<?php echo osc_esc_html($paymentUri); ?>" onclick="this.select();" style="width: 100%; box-sizing: border-box; border: none;" />
    <?php } ?>

    <?php if ($showPaymentDetails) { ?>
        <form method="post" action="<?php echo osc_route_url('elektron_escrow_order_status', ['order' => $order['pk_i_id']]); ?>">
            <?php echo osc_csrf_token_form(); ?>
            <input type="hidden" name="check_payment" value="1" />
            <input type="submit" class="btn btn-default" value="<?php echo osc_esc_html(__('Check payment now', ELEKTRON_ESCROW_DOMAIN)); ?>" />
        </form>
    <?php } ?>

    <?php if ($order['status'] === OrderStatus::AWAITING_PAYMENT) { ?>
        <p><?php echo osc_esc_html(elektron_escrow_t($isBuyer ? 'order.awaiting_payment' : 'order.awaiting_payment.seller', [
            '{amount}' => elektron_escrow_format_amount($amountElek),
        ])); ?></p>

        <?php if ($isBuyer) { ?>
            <form method="post" action="<?php echo osc_route_url('elektron_escrow_order_status', ['order' => $order['pk_i_id']]); ?>" onsubmit="return confirm('<?php echo osc_esc_js(__('Cancel this order? This cannot be undone.', ELEKTRON_ESCROW_DOMAIN)); ?>');">
                <?php echo osc_csrf_token_form(); ?>
                <input type="hidden" name="cancel_order" value="1" />
                <input type="submit" class="btn btn-default" value="<?php echo osc_esc_html(__('Cancel order', ELEKTRON_ESCROW_DOMAIN)); ?>" />
            </form>
        <?php } ?>

    <?php } elseif ($order['status'] === OrderStatus::CONFIRMING) { ?>
        <p><?php echo osc_esc_html(elektron_escrow_t($isBuyer ? 'order.confirming' : 'order.confirming.seller', [
            '{confirmations}' => (string) $requiredConfirmations,
        ])); ?></p>

    <?php } elseif ($order['status'] === OrderStatus::FUNDED) { ?>
        <p><?php echo osc_esc_html(elektron_escrow_t('order.funded.' . ($isBuyer ? 'buyer' : 'seller'))); ?></p>

        <?php if (!$pastT1) { ?>
            <p class="elektron-escrow-countdown" data-locktime="<?php echo (int) $order['buyer_refund_locktime']; ?>">
                <?php echo osc_esc_html(elektron_escrow_t('order.countdown.buyer_refund', [
                    '{date}' => date('Y-m-d', (int) $order['buyer_refund_locktime']),
                ])); ?>
            </p>
        <?php } else { ?>
            <p class="elektron-escrow-countdown" data-locktime="<?php echo (int) $order['seller_release_locktime']; ?>">
                <?php echo osc_esc_html(elektron_escrow_t('order.countdown.seller_release', [
                    '{date}' => date('Y-m-d', (int) $order['seller_release_locktime']),
                ])); ?>
            </p>
        <?php } ?>

        <?php if ($isBuyer) { ?>
            <form method="post" action="<?php echo osc_route_url('elektron_escrow_order_status', ['order' => $order['pk_i_id']]); ?>">
                <?php echo osc_csrf_token_form(); ?>
                <input type="hidden" name="confirm_receipt" value="1" />
                <input type="submit" class="btn btn-primary" value="<?php echo osc_esc_html(__('Confirm receipt', ELEKTRON_ESCROW_DOMAIN)); ?>" />
            </form>
        <?php } ?>

    <?php } elseif ($order['status'] === OrderStatus::SHIPPED) { ?>
        <p><?php _e('Seller has confirmed shipment.', ELEKTRON_ESCROW_DOMAIN); ?></p>
        <?php if ($isBuyer) { ?>
            <form method="post" action="<?php echo osc_route_url('elektron_escrow_order_status', ['order' => $order['pk_i_id']]); ?>">
                <?php echo osc_csrf_token_form(); ?>
                <input type="hidden" name="confirm_receipt" value="1" />
                <button type="submit" class="btn btn-primary" style="font-size:1.2em; padding:0.6em 1.4em;">
                    <?php _e('I have received', ELEKTRON_ESCROW_DOMAIN); ?>
                </button>
            </form>
        <?php } ?>
    <?php } elseif ($order['status'] === OrderStatus::RELEASE_PENDING_SELLER_SIGNATURE) { ?>
        <p><?php echo osc_esc_html(elektron_escrow_t('order.release_pending_seller_signature')); ?></p>

        <?php if ($order['psbt_base64'] === null) { ?>
            <p class="text-muted"><?php _e('This release could not be prepared automatically (this is rare -- it can happen if the payment arrived in more than one transaction). The two parties will have to cooperatively sign and broadcast a spend from the escrow address using their own wallets, with the script below.', ELEKTRON_ESCROW_DOMAIN); ?></p>

            <form method="post" action="<?php echo osc_route_url('elektron_escrow_order_status', ['order' => $order['pk_i_id']]); ?>">
                <?php echo osc_csrf_token_form(); ?>
                <input type="hidden" name="retry_psbt" value="1" />
                <input type="submit" class="btn btn-default" value="<?php echo osc_esc_html(__('Try again', ELEKTRON_ESCROW_DOMAIN)); ?>" />
            </form>

            <p><?php _e('Redeem script', ELEKTRON_ESCROW_DOMAIN); ?></p>
            <code class="elektron-escrow-code"><?php echo osc_esc_html($order['redeem_script_hex']); ?></code>
        <?php } elseif ($isBuyer) { ?>
            <?php if ((int) $order['psbt_signature_count'] < 1) { ?>
                <p><?php _e('Scan this into your own wallet (or copy the text below) and sign it there. This platform never sees or handles your private key.', ELEKTRON_ESCROW_DOMAIN); ?></p>

                <?php
                $psbtQrCode = new QrCode($order['psbt_base64']);
                $psbtQrPngBase64 = base64_encode((new PngWriter())->write($psbtQrCode)->getString());
                ?>
                <img src="data:image/png;base64,<?php echo $psbtQrPngBase64; ?>" alt="<?php _e('Release transaction (unsigned)', ELEKTRON_ESCROW_DOMAIN); ?>" />

                <p><label for="elektron-escrow-psbt-unsigned-<?php echo (int) $order['pk_i_id']; ?>"><?php _e('Release transaction (unsigned, tap to select, then copy)', ELEKTRON_ESCROW_DOMAIN); ?></label></p>
                <textarea id="elektron-escrow-psbt-unsigned-<?php echo (int) $order['pk_i_id']; ?>" class="elektron-escrow-code" readonly="readonly" rows="4" style="width: 100%; box-sizing: border-box;" onclick="this.select();"><?php echo osc_esc_html($order['psbt_base64']); ?></textarea>

                <form method="post" action="<?php echo osc_route_url('elektron_escrow_order_status', ['order' => $order['pk_i_id']]); ?>">
                    <?php echo osc_csrf_token_form(); ?>
                    <input type="hidden" name="submit_signed_psbt" value="1" />
                    <label for="elektron-escrow-psbt-signed-<?php echo (int) $order['pk_i_id']; ?>"><?php _e('Once signed, paste the result from your wallet here', ELEKTRON_ESCROW_DOMAIN); ?></label>
                    <textarea name="signed_psbt" id="elektron-escrow-psbt-signed-<?php echo (int) $order['pk_i_id']; ?>" rows="4" style="width: 100%; box-sizing: border-box;"></textarea>
                    <input type="submit" class="btn btn-primary" value="<?php echo osc_esc_html(__('Submit my signature', ELEKTRON_ESCROW_DOMAIN)); ?>" />
                </form>
            <?php } else { ?>
                <p class="text-muted"><?php _e('You already signed. Waiting for the seller to sign and broadcast the release from their own wallet.', ELEKTRON_ESCROW_DOMAIN); ?></p>
            <?php } ?>
        <?php } else { ?>
            <?php if ((int) $order['psbt_signature_count'] < 1) { ?>
                <p class="text-muted"><?php _e('Waiting for the buyer to sign the release first.', ELEKTRON_ESCROW_DOMAIN); ?></p>
            <?php } else { ?>
                <p><?php _e('Scan this into your own wallet (or copy the text below). It already carries the buyer\'s signature; your wallet only needs to add yours. Sign and broadcast it there -- this platform never does either for you.', ELEKTRON_ESCROW_DOMAIN); ?></p>

                <?php
                $psbtQrCode = new QrCode($order['psbt_base64']);
                $psbtQrPngBase64 = base64_encode((new PngWriter())->write($psbtQrCode)->getString());
                ?>
                <img src="data:image/png;base64,<?php echo $psbtQrPngBase64; ?>" alt="<?php _e('Release transaction (buyer signed)', ELEKTRON_ESCROW_DOMAIN); ?>" />

                <p><label for="elektron-escrow-psbt-partial-<?php echo (int) $order['pk_i_id']; ?>"><?php _e('Release transaction (buyer signed, tap to select, then copy)', ELEKTRON_ESCROW_DOMAIN); ?></label></p>
                <textarea id="elektron-escrow-psbt-partial-<?php echo (int) $order['pk_i_id']; ?>" class="elektron-escrow-code" readonly="readonly" rows="4" style="width: 100%; box-sizing: border-box;" onclick="this.select();"><?php echo osc_esc_html($order['psbt_base64']); ?></textarea>
            <?php } ?>
        <?php } ?>

        <?php if (!$isBuyer) { ?>
            <form method="post" action="<?php echo osc_route_url('elektron_escrow_order_status', ['order' => $order['pk_i_id']]); ?>" onsubmit="return confirm('<?php echo osc_esc_js(__('Mark this order as released? Only do this once the funds have actually been sent to your own wallet.', ELEKTRON_ESCROW_DOMAIN)); ?>');">
                <?php echo osc_csrf_token_form(); ?>
                <input type="hidden" name="mark_released" value="1" />
                <input type="submit" class="btn btn-primary" value="<?php echo osc_esc_html(__('Mark as released', ELEKTRON_ESCROW_DOMAIN)); ?>" />
            </form>
        <?php } ?>

    <?php } else { ?>
        <p><?php echo osc_esc_html(elektron_escrow_t('order.' . $order['status'])); ?></p>
    <?php } ?>

    <?php
    $hasRefund = !empty($order['buyer_refund_psbt_base64']);
    $hasClaim = !empty($order['seller_claim_psbt_base64']);
    if ($hasRefund || $hasClaim) {
    ?>
        <h2><?php _e('Timeout transactions (unilateral exit)', ELEKTRON_ESCROW_DOMAIN); ?></h2>
        <p class="text-muted"><?php _e('These let either party exit alone after the timeout. Sign in your wallet and paste the result back here.', ELEKTRON_ESCROW_DOMAIN); ?></p>

        <?php if ($hasRefund && $isBuyer) { ?>
            <h3><?php _e('Your refund (valid after T1)', ELEKTRON_ESCROW_DOMAIN); ?></h3>
            <?php
            $qr = new QrCode($order['buyer_refund_psbt_base64']);
            $png = base64_encode((new PngWriter())->write($qr)->getString());
            ?>
            <img src="data:image/png;base64,<?php echo $png; ?>" alt="Buyer refund PSBT" style="max-width:240px;" />
            <p><textarea class="elektron-escrow-code" readonly rows="3" style="width:100%;" onclick="this.select();"><?php echo osc_esc_html($order['buyer_refund_psbt_base64']); ?></textarea></p>
            <form method="post" action="<?php echo osc_route_url('elektron_escrow_order_status', ['order' => $order['pk_i_id']]); ?>">
                <?php echo osc_csrf_token_form(); ?>
                <input type="hidden" name="submit_signed_refund" value="1" />
                <p><label><?php _e('Paste signed refund PSBT', ELEKTRON_ESCROW_DOMAIN); ?></label></p>
                <textarea name="signed_refund_psbt" rows="3" style="width:100%;"></textarea>
                <p><input type="submit" class="btn btn-default" value="<?php echo osc_esc_html(__('Save signed refund', ELEKTRON_ESCROW_DOMAIN)); ?>" /></p>
            </form>
        <?php } ?>

        <?php if ($hasClaim && !$isBuyer) { ?>
            <h3><?php _e('Your claim (valid after T2)', ELEKTRON_ESCROW_DOMAIN); ?></h3>
            <?php
            $qr = new QrCode($order['seller_claim_psbt_base64']);
            $png = base64_encode((new PngWriter())->write($qr)->getString());
            ?>
            <img src="data:image/png;base64,<?php echo $png; ?>" alt="Seller claim PSBT" style="max-width:240px;" />
            <p><textarea class="elektron-escrow-code" readonly rows="3" style="width:100%;" onclick="this.select();"><?php echo osc_esc_html($order['seller_claim_psbt_base64']); ?></textarea></p>
            <form method="post" action="<?php echo osc_route_url('elektron_escrow_order_status', ['order' => $order['pk_i_id']]); ?>">
                <?php echo osc_csrf_token_form(); ?>
                <input type="hidden" name="submit_signed_claim" value="1" />
                <p><label><?php _e('Paste signed claim PSBT', ELEKTRON_ESCROW_DOMAIN); ?></label></p>
                <textarea name="signed_claim_psbt" rows="3" style="width:100%;"></textarea>
                <p><input type="submit" class="btn btn-default" value="<?php echo osc_esc_html(__('Save signed claim', ELEKTRON_ESCROW_DOMAIN)); ?>" /></p>
            </form>
        <?php } ?>
    <?php } ?>

</div>
