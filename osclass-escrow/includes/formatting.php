<?php if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');

/**
 * Formats an ELEK amount using the current visitor's locale settings
 * (decimal point vs. comma, thousands separator), the same per-locale
 * `t_locale` values Osclass's own osc_format_price() reads from.
 *
 * Not implemented as a call to osc_format_price() itself: that helper
 * divides by a hardcoded 1,000,000, matching Osclass's own generic
 * `i_price` column, not the 100,000,000 (1 ELEK = 100,000,000 lepton)
 * scale this plugin uses, and always shows a fixed number of decimals
 * where a crypto amount reads better with trailing zeros trimmed.
 */
function elektron_escrow_format_amount(float $amountElek): string
{
    $fixed = number_format($amountElek, 8, '.', '');
    $trimmed = rtrim(rtrim($fixed, '0'), '.');
    if ($trimmed === '') {
        $trimmed = '0';
    }

    [$integerPart, $fractionPart] = array_pad(explode('.', $trimmed, 2), 2, null);

    $formattedInteger = number_format((float) $integerPart, 0, '', osc_locale_thousands_sep());

    if ($fractionPart === null) {
        return $formattedInteger;
    }

    return $formattedInteger . osc_locale_dec_point() . $fractionPart;
}

/**
 * Osclass returns a row without a title instead of false when a listing has been
 * deleted, so the title is checked rather than the row itself.
 *
 * @param mixed $item result of Item::findByPrimaryKey()
 */
function elektron_escrow_item_exists($item): bool
{
    return is_array($item) && isset($item['pk_i_id'], $item['s_title']);
}

/**
 * Title of the deal's listing. Falls back to the title stored with the deal when the
 * listing is gone, so sold or bought items stay readable in the order lists.
 *
 * @param mixed $item result of Item::findByPrimaryKey()
 */
function elektron_escrow_item_title(array $deal, $item = null): string
{
    if (elektron_escrow_item_exists($item)) {
        return (string) $item['s_title'];
    }
    $stored = trim((string) ($deal['item_title'] ?? ''));

    return $stored !== '' ? $stored : sprintf(__('Listing #%d', ELEKTRON_ESCROW_DOMAIN), (int) $deal['fk_i_item_id']);
}

/**
 * Prints a small, self-contained <style> block for this plugin's own pages:
 * tables (class "elektron-escrow-table": borders, a header background, row
 * separators), paragraph spacing, and a wrapped, monospace block for long
 * values (class "elektron-escrow-code": addresses, redeem scripts, payment
 * URIs) -- regardless of whatever the active theme's own CSS does or does
 * not provide, since a value like a redeem script is long enough to run
 * straight into surrounding text without it.
 *
 * Not queued via osc_enqueue_style(): that helper only takes effect if
 * called before the theme prints its own <head>, but every one of this
 * plugin's pages is rendered through Osclass's custom-route pipeline,
 * which -- like osc_redirect_to() (see includes/notice.php's docblock) --
 * always renders the theme's header.php, and so its <head>, *before* this
 * plugin's own controller/view code ever runs. An inline <style> block
 * printed directly in the page body works regardless of that ordering.
 */
function elektron_escrow_table_style(): void
{
    ?>
    <style>
    .elektron-escrow-help { display: inline-block; position: relative; width: 1.35em; height: 1.35em; line-height: 1.35em; text-align: center; border-radius: 50%; background: #5b7fb5; color: #fff; font-size: 0.8em; font-weight: bold; cursor: help; vertical-align: middle; }
    .elektron-escrow-help:hover::after, .elektron-escrow-help:focus::after { content: attr(data-tip); position: absolute; z-index: 20; left: 0; top: 1.8em; width: 260px; max-width: 70vw; padding: 8px 10px; border-radius: 4px; background: #222; color: #fff; font-weight: normal; font-size: 1.05em; line-height: 1.4; text-align: left; white-space: normal; }
        .elektron-escrow-order { display: flow-root; }
        .elektron-escrow-order > * { clear: both; }
        .elektron-escrow-panel { display: flow-root; border: 1px solid #ddd; border-radius: 4px; padding: 10px 16px; margin: 1.4em 0; }
        .elektron-escrow-panel h3, .elektron-escrow-panel h4 { clear: both; margin: 0.8em 0 0.5em; }
        .elektron-escrow-order form { display: block; clear: both; margin: 1em 0; }
        .elektron-escrow-order .btn, .elektron-escrow-order input[type=submit] { float: none !important; display: inline-block; margin: 6px 6px 6px 0; position: static; }
        .elektron-escrow-order details { display: block; clear: both; margin: 0.9em 0; padding: 6px 0; }
        .elektron-escrow-order details > summary { cursor: pointer; padding: 4px 0; }
        .elektron-escrow-order details details { margin-left: 1em; border-top: 1px dotted #ccc; }
        .elektron-escrow-order pre.elektron-escrow-code, .elektron-escrow-order code.elektron-escrow-code { display: block; white-space: pre-wrap; word-break: break-all; margin: 0.6em 0 1em; }
        .elektron-escrow-done { background: #1e7e34; color: #fff; border-radius: 3px; padding: 0 6px; font-size: 0.8em; font-weight: normal; text-decoration: none; display: inline-block; }
        .elektron-escrow-badge { display: inline-block; border-radius: 3px; padding: 2px 10px; font-weight: bold; color: #fff; }
        .elektron-escrow-badge-reserved { background: #b36b00; }
        .elektron-escrow-badge-sold { background: #555; }
        .elektron-escrow-emergency { display: block; clear: both; border: 2px solid #b00020; border-radius: 4px; padding: 6px 16px; margin: 1.6em 0; }
        .elektron-escrow-emergency > summary { color: #b00020; cursor: pointer; padding: 4px 0; }
        .elektron-escrow-dispute { border-color: #b36b00; }
        .elektron-escrow-chain { display: block; clear: both; margin: 1em 0; }
        .elektron-escrow-chain-step { display: flow-root; border: 1px solid #ddd; border-left: 6px solid #ccc; border-radius: 4px; padding: 8px 16px; margin: 0 0 14px; position: relative; }
        .elektron-escrow-chain-step:not(:last-child)::after { content: ""; position: absolute; left: 20px; bottom: -14px; width: 2px; height: 14px; background: #ccc; }
        .elektron-escrow-chain-step.current { border-left-color: #d4a017; background: #fffdf5; }
        .elektron-escrow-chain-step.done { border-left-color: #1e7e34; }
        .elektron-escrow-chain-step.waiting { opacity: 0.6; }
        .elektron-escrow-why { color: #555; font-size: 0.92em; }
        .elektron-escrow-howto { margin: 0.4em 0 1em; }
        .elektron-escrow-table { width: 100%; border-collapse: collapse; margin: 1em 0; }
        .elektron-escrow-table th, .elektron-escrow-table td { border: 1px solid #ccc; padding: 8px 12px; text-align: left; }
        .elektron-escrow-table th { background: #f0f0f0; font-weight: bold; }
        .elektron-escrow-table tr:nth-child(even) td { background: #fafafa; }
        .elektron-escrow-order p, .elektron-escrow-checkout-preview p, .elektron-escrow-orders p, .elektron-escrow-notice p {
            margin: 0.75em 0;
            line-height: 1.5;
        }
        .elektron-escrow-code {
            display: block;
            font-family: monospace;
            font-size: 0.9em;
            background: #f5f5f5;
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 10px 12px;
            margin: 0.5em 0 1em;
            overflow-wrap: break-word;
            word-break: break-all;
            white-space: pre-wrap;
        }
    </style>
    <?php
}

/**
 * Plain (locale-independent, '.' decimal point, no thousands separator,
 * trailing zeros trimmed) decimal string for an ELEK amount. Used inside a
 * payment URI, which a wallet must parse unambiguously regardless of the
 * visitor's own locale -- unlike elektron_escrow_format_amount(), which is
 * for human display only.
 */
function elektron_escrow_plain_amount(float $amountElek): string
{
    $fixed = number_format($amountElek, 8, '.', '');
    $trimmed = rtrim(rtrim($fixed, '0'), '.');

    return $trimmed === '' ? '0' : $trimmed;
}

/**
 * BIP21-style payment URI for the given address/amount/order. Scheme is
 * 'elek', not 'bitcoin': confirmed directly in the actual Elektron Net
 * wallet fork's own source (kutlusoy/elektron-net-electrum,
 * electrum/bip21.py: `BITCOIN_BIP21_URI_SCHEME = 'elek'`, deliberately
 * renamed there specifically so a real bitcoin: URI is never silently
 * accepted for this, different, chain). `amount` is a plain decimal ELEK
 * value (matching that same file's create_bip21_uri(), which formats the
 * amount with format_satoshis_plain() -- i.e. a plain decimal string, not
 * locale-formatted and not in lepton), so a wallet that understands this
 * scheme can prefill both the address and the amount from one scan or one
 * paste.
 *
 * `label` is standard BIP21 (not this fork's own invention): that same
 * parser reads any query parameter it does not special-case straight into
 * its result array, so `label` arrives as `$out['label']` even though
 * `create_bip21_uri()` itself never sets one; several wallets show it on
 * their own send screen. Used here to carry this order's own id, so it is
 * still identifiable once it reaches whichever wallet the buyer pays from,
 * not only on this order's own status page. `rawurlencode()`, not
 * `urlencode()`: matches `urllib.parse.quote()`, which is what that same
 * Python `create_bip21_uri()` uses to encode its own `message` parameter
 * (percent-encoded spaces, not `+`).
 */
function elektron_escrow_payment_uri(string $address, float $amountElek, int $orderId): string
{
    return 'elek:' . $address
        . '?amount=' . elektron_escrow_plain_amount($amountElek)
        . '&label=' . rawurlencode(__('Elektron order', ELEKTRON_ESCROW_DOMAIN) . ' #' . $orderId);
}
