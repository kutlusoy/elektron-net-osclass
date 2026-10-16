<?php if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');
/** @var array[] $purchases EscrowDealDAO rows where the logged-in user is the buyer */
/** @var array[] $sales EscrowDealDAO rows where the logged-in user is the seller */

use ElektronNet\Escrow\DealStatus;

elektron_escrow_table_style();

$renderTable = function (array $deals, string $role): void {
    ?>
    <table class="elektron-escrow-table">
        <tr>
            <th><?php _e('Item', ELEKTRON_ESCROW_DOMAIN); ?></th>
            <th><?php _e('Amount', ELEKTRON_ESCROW_DOMAIN); ?></th>
            <th><?php _e('Status', ELEKTRON_ESCROW_DOMAIN); ?></th>
            <th><?php _e('Your turn', ELEKTRON_ESCROW_DOMAIN); ?></th>
            <th></th>
        </tr>
        <?php foreach ($deals as $deal) { ?>
            <?php $orderItem = Item::newInstance()->findByPrimaryKey((int) $deal['fk_i_item_id']); ?>
            <tr>
                <td>
                    <?php echo osc_esc_html(elektron_escrow_item_title($deal, $orderItem)); ?>
                    <?php if (!elektron_escrow_item_exists($orderItem)) { ?>
                        <small class="elektron-escrow-why">(<?php _e('listing removed', ELEKTRON_ESCROW_DOMAIN); ?>)</small>
                    <?php } ?>
                </td>
                <td><?php echo osc_esc_html(elektron_escrow_format_amount(((int) $deal['amount_lep']) / 100000000)); ?> ELEK</td>
                <td class="elektron-escrow-status" data-status="<?php echo osc_esc_html($deal['status']); ?>"><?php echo osc_esc_html(elektron_escrow_status_label($deal['status'])); ?></td>
                <td><?php echo DealStatus::mayAct($deal, $role) ? osc_esc_html(__('Yes', ELEKTRON_ESCROW_DOMAIN)) : ''; ?></td>
                <td><a href="<?php echo osc_route_url('elektron_escrow_order_status', ['order' => $deal['pk_i_id']]); ?>"><?php _e('View', ELEKTRON_ESCROW_DOMAIN); ?></a></td>
            </tr>
        <?php } ?>
    </table>
    <?php
};
?>
<div class="elektron-escrow-orders">
    <h1><?php _e('My Elektron orders', ELEKTRON_ESCROW_DOMAIN); ?></h1>

    <h2><?php _e('Purchases', ELEKTRON_ESCROW_DOMAIN); ?></h2>
    <?php if (empty($purchases)) { ?>
        <p><?php _e('You have not bought anything with Elektron escrow yet.', ELEKTRON_ESCROW_DOMAIN); ?></p>
    <?php } else { $renderTable($purchases, 'buyer'); } ?>

    <h2><?php _e('Sales', ELEKTRON_ESCROW_DOMAIN); ?></h2>
    <?php if (empty($sales)) { ?>
        <p><?php _e('You have not sold anything with Elektron escrow yet.', ELEKTRON_ESCROW_DOMAIN); ?></p>
    <?php } else { $renderTable($sales, 'seller'); } ?>
</div>
