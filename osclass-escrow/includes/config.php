<?php if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');

use ElektronNet\Escrow\Chain\EsploraChainDataProvider;
use ElektronNet\Escrow\Chain\FallbackChainDataProvider;
use ElektronNet\Escrow\Config;
use ElektronNet\Escrow\TimeoutPolicy;

/**
 * Builds the plugin config from its admin preferences (section
 * 'plugin-osclass-escrow', defaults in install.php).
 */
function elektron_escrow_config(): Config
{
    $endpointsRaw = osc_get_preference('chain_data_endpoints', 'plugin-osclass-escrow');
    $endpoints = array_values(array_filter(array_map('trim', explode("\n", $endpointsRaw))));

    return new Config(
        osc_get_preference('network', 'plugin-osclass-escrow'),
        $endpoints,
        (int) osc_get_preference('required_confirmations', 'plugin-osclass-escrow'),
        TimeoutPolicy::fromDays(
            (int) osc_get_preference('buyer_refund_days', 'plugin-osclass-escrow'),
            (int) osc_get_preference('seller_release_days', 'plugin-osclass-escrow')
        )
    );
}

/**
 * Osclass's own currency table stores a code as CHAR(3) (confirmed against
 * a real installation's oc-includes/osclass/installer/struct.sql:
 * `pk_c_code CHAR(3) NOT NULL`), so "ELEK" (4 characters) can never
 * actually be stored as a currency code there -- an operator has to use a
 * 3-letter contraction instead (e.g. "ELE" or "ELK"), and different
 * operators may already have picked different ones before this was known.
 * A list, not a single fixed value, so an operator never has to migrate
 * existing listings just because a second one picked differently.
 *
 * @return string[] non-empty, deduplicated, uppercased 3-letter codes
 */
function elektron_escrow_currency_codes(): array
{
    $raw = osc_get_preference('currency_codes', 'plugin-osclass-escrow');
    $codes = array_map('trim', explode("\n", $raw));
    $codes = array_map('strtoupper', array_filter($codes, function (string $code) {
        return $code !== '';
    }));

    return array_values(array_unique($codes));
}

/**
 * The item's price in ELEK, or null if Osclass has no numeric price set for
 * it at all ("price on request" listings leave `i_price` empty; escrow has
 * no fixed amount to encode into an address in that case).
 *
 * Reads `i_price`, not the also-present `f_price` column on `t_item`: a
 * real installation's own item-submission code
 * (oc-includes/osclass/controller/item.php) never writes `f_price` at all,
 * only `i_price` (confirmed by grepping a real checkout for every write
 * site of either column); `osc_item_price()`/`osc_format_price()` read
 * `i_price` and divide by 1,000,000 to get the actual price, which is the
 * scale this function matches. `f_price` is a legacy/unused column that
 * always reads back empty, which is why an earlier version of this
 * function (reading `f_price`) always computed an amount of 0 ELEK.
 *
 * @param array $item Osclass item row
 */
function elektron_escrow_item_price_elek(array $item): ?float
{
    if (($item['i_price'] ?? '') === '' || $item['i_price'] === null) {
        return null;
    }

    return ((int) $item['i_price']) / 1000000;
}

/**
 * Chain-data provider with automatic failover across the configured endpoints.
 */
function elektron_escrow_chain_data(): FallbackChainDataProvider
{
    $providers = array_map(
        function (string $endpoint) {
            return new EsploraChainDataProvider($endpoint);
        },
        elektron_escrow_config()->chainDataEndpoints()
    );

    return new FallbackChainDataProvider($providers);
}
