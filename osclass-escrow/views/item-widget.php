<?php if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');
/** @var array $item Osclass item row */
/** @var array|null $order this buyer's existing EscrowDealDAO row for this item, if any */

// Escrow only ever applies to listings priced directly in one of the
// admin-configured currency codes: converting a fiat-priced listing would
// need a live exchange rate, and no reliable ELEK price feed exists yet
// (see elektron-net-mempool's README). This is a list, not a single fixed
// "ELEK", because Osclass's own currency table stores a code as exactly 3
// characters (see elektron_escrow_currency_codes() in includes/config.php),
// so different marketplace operators may already have picked different
// 3-letter contractions (e.g. "ELE", "ELK") before that was known.
if (!in_array(strtoupper((string) ($item['fk_c_currency_code'] ?? '')), elektron_escrow_currency_codes(), true)) {
    return;
}
if (elektron_escrow_item_price_elek($item) === null) {
    return; // "price on request": no fixed amount to encode into an address
}
?>

<div class="elektron-escrow-widget">
    <?php if ($order === null) { ?>
        <a class="btn btn-primary" href="<?php echo osc_route_url('elektron_escrow_checkout', ['item' => $item['pk_i_id']]); ?>">
            <?php _e('Buy with Elektron (ELEK)', ELEKTRON_ESCROW_DOMAIN); ?>
        </a>
    <?php } else { ?>
        <a class="btn btn-default" href="<?php echo osc_route_url('elektron_escrow_order_status', ['order' => $order['pk_i_id']]); ?>">
            <?php _e('View your Elektron order', ELEKTRON_ESCROW_DOMAIN); ?>
        </a>
    <?php } ?>
</div>
