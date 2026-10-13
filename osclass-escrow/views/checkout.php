<?php if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');
/** @var array $item Osclass item row */
/** @var float $amountElek */
/** @var \ElektronNet\Escrow\TimeoutPolicy $timeoutPolicy */
/** @var string $buyerEmail */
/** @var string|null $errorMessage */

// Preview only: nothing is created until the form below is submitted.

elektron_escrow_table_style();
?>

<div class="elektron-escrow-checkout-preview">
    <h1><?php echo osc_esc_html($item['s_title']); ?></h1>

    <p><?php _e('You are about to start an Elektron Net escrow purchase for this listing.', ELEKTRON_ESCROW_DOMAIN); ?></p>

    <table class="elektron-escrow-table">
        <tr>
            <td><?php _e('Item', ELEKTRON_ESCROW_DOMAIN); ?></td>
            <td><a href="<?php echo osc_esc_html(osc_item_url_from_item($item)); ?>"><?php echo osc_esc_html($item['s_title']); ?></a></td>
        </tr>
        <tr>
            <td><?php _e('Price', ELEKTRON_ESCROW_DOMAIN); ?></td>
            <td><?php echo osc_esc_html(elektron_escrow_format_amount($amountElek)); ?> ELEK</td>
        </tr>
    </table>

    <p><?php _e('After you confirm, the order page shows a payment QR code. Your connected wallet provides the keys, your own wallet creates every signature, and this site never holds a key. The payment is held in an escrow between you and the seller.', ELEKTRON_ESCROW_DOMAIN); ?></p>

    <p><?php printf(
        __('If you do not confirm receipt, the seller can automatically claim the funds %d days after your payment is confirmed.', ELEKTRON_ESCROW_DOMAIN),
        $timeoutPolicy->sellerReleaseDays()
    ); ?></p>
    <p><?php printf(
        __('If the seller never responds, you can automatically reclaim the funds %d days after your payment is confirmed.', ELEKTRON_ESCROW_DOMAIN),
        $timeoutPolicy->buyerRefundDays()
    ); ?></p>

    <?php if ($errorMessage !== null) { ?>
        <p class="elektron-escrow-error"><?php echo osc_esc_html($errorMessage); ?></p>
    <?php } ?>

    <form method="post" action="<?php echo osc_route_url('elektron_escrow_checkout', ['item' => $item['pk_i_id']]); ?>">
        <?php echo osc_csrf_token_form(); ?>
        <input type="hidden" name="confirm_checkout" value="1" />

        <h2><?php _e('Shipping details', ELEKTRON_ESCROW_DOMAIN); ?></h2>
        <p class="help-block">
            <?php _e('Only shared with the seller once your payment is confirmed, so they can send the item to you.', ELEKTRON_ESCROW_DOMAIN); ?>
        </p>

        <label for="shipping_name"><?php _e('Full name', ELEKTRON_ESCROW_DOMAIN); ?></label>
        <input type="text" name="shipping_name" id="shipping_name" required maxlength="190" size="50"
               value="<?php echo osc_esc_html((string) Params::getParam('shipping_name')); ?>" />

        <label for="shipping_address"><?php _e('Shipping address', ELEKTRON_ESCROW_DOMAIN); ?></label>
        <textarea name="shipping_address" id="shipping_address" required rows="4" cols="50"
                  placeholder="<?php echo osc_esc_html(__('Street, postal code, city, country', ELEKTRON_ESCROW_DOMAIN)); ?>"
        ><?php echo osc_esc_html((string) Params::getParam('shipping_address')); ?></textarea>

        <label for="shipping_phone"><?php _e('Phone number', ELEKTRON_ESCROW_DOMAIN); ?></label>
        <input type="text" name="shipping_phone" id="shipping_phone" required maxlength="40" size="25"
               value="<?php echo osc_esc_html((string) Params::getParam('shipping_phone')); ?>" />

        <label for="contact_email"><?php _e('Contact email for this order', ELEKTRON_ESCROW_DOMAIN); ?></label>
        <input type="email" name="contact_email" id="contact_email" required maxlength="190" size="40"
               value="<?php echo osc_esc_html((string) (Params::getParam('contact_email') ?: $buyerEmail)); ?>" />

        <input type="submit" class="btn btn-primary" value="<?php echo osc_esc_html(__('Confirm and start the escrow', ELEKTRON_ESCROW_DOMAIN)); ?>" />
        <a class="btn btn-default" href="<?php echo osc_esc_html(osc_item_url_from_item($item)); ?>"><?php _e('Cancel', ELEKTRON_ESCROW_DOMAIN); ?></a>
    </form>
</div>
