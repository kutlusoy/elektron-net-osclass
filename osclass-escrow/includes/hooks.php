<?php if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');

/**
 * Account menu link. 'user_menu_filter' is what osc_private_user_menu()
 * reads (the same hook Shopclass's own billing links use); the
 * $user_menu flag on osc_add_route() only affects page chrome.
 */
osc_add_hook('user_menu_filter', function (array $options) {
    $options[] = array(
        'name' => __('My Elektron orders', ELEKTRON_ESCROW_DOMAIN),
        'url' => osc_route_url('elektron_escrow_orders'),
        'class' => 'opt_elektron_escrow_orders',
    );

    return $options;
});

/**
 * "Buy with Elektron" widget. 'item_detail' is fired by the theme template
 * inside the item content block; 'show_item' runs before any markup is
 * emitted and would place the widget above the page header.
 */
osc_add_hook('item_detail', function ($item) {
    if (!osc_logged_user_id() || osc_logged_user_id() === osc_item_user_id()) {
        return;
    }
    $order = EscrowDealDAO::newInstance()->findOpenByItemAndBuyer((int) $item['pk_i_id'], (int) osc_logged_user_id());
    require osc_plugins_path() . 'osclass-escrow/views/item-widget.php';
});

/**
 * Optional seller fields in the listing form. 'item_form' (new listing) and
 * 'item_edit' (edit) render the fields, 'posted_item' and 'edited_item'
 * save them. The marker input makes a theme that does not render the fields
 * leave saved values untouched.
 */
osc_add_hook('item_form', function ($catId = null) {
    elektron_escrow_render_item_fields(null);
});
osc_add_hook('item_edit', function ($catId = null, $itemId = null) {
    elektron_escrow_render_item_fields($itemId === null ? null : (int) $itemId);
});
osc_add_hook('posted_item', 'elektron_escrow_save_item_fields');
osc_add_hook('edited_item', 'elektron_escrow_save_item_fields');

/**
 * Chain watcher. Osclass throttles 'cron_minutely' to once per 5 minutes and
 * only fires it if auto_cron is on or a system cron calls it; the deal page
 * runs the same check on load.
 */
osc_add_hook('cron_minutely', 'elektron_escrow_check_all_deals');

/**
 * Daily sweep raising the T1/T2 reminder hooks. Delivery of these
 * reminders is left to whoever listens to them.
 */
osc_add_hook('cron_daily', function () {
    $scheduler = new \ElektronNet\Escrow\ReminderScheduler();
    $now = time();
    foreach (EscrowDealDAO::newInstance()->findFundsAtRisk() as $deal) {
        if ($scheduler->isReminderDue((int) $deal['buyer_refund_locktime'], $now)) {
            osc_run_hook('elektron_escrow_buyer_refund_reminder_due', $deal);
        }
        if ($scheduler->isReminderDue((int) $deal['seller_release_locktime'], $now)) {
            osc_run_hook('elektron_escrow_seller_release_reminder_due', $deal);
        }
    }
});
