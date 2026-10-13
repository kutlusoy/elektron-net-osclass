<?php
/*
Plugin Name: Elektron Net Escrow
Plugin URI: https://elektron-net.org
Description: Trustless 2-of-2 multisig escrow payments in Elektron (ELEK) on the Elektron Net network, for Osclass listings. The site only relays keys and PSBTs between the two parties. See osclass-escrow/README.MD for the full API specification.
Version: 0.1.0
Author: Elektron Net
Author URI: https://elektron-net.org
*/

    if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');

    define('ELEKTRON_ESCROW_DOMAIN', 'osclass-escrow');

    // __DIR__, not osc_plugin_path(__FILE__): that helper returns the full
    // path to THIS file (see its own source in oc-includes/osclass/helpers/
    // hPlugins.php -- it strips down to, and re-appends, the path
    // including the filename, it does not return a directory), which is
    // exactly right for the plugin-identifier uses below but silently
    // wrong for building a path to another file, since it leaves no
    // separator before the appended relative path.
    require_once __DIR__ . '/vendor/autoload.php';
    require_once __DIR__ . '/install.php';
    require_once __DIR__ . '/models/EscrowDealDAO.php';
    require_once __DIR__ . '/models/EscrowWalletDAO.php';
    elektron_escrow_ensure_schema();
    require_once __DIR__ . '/includes/config.php';   // elektron_escrow_config()
    require_once __DIR__ . '/includes/i18n.php';     // status labels and next-step texts
    require_once __DIR__ . '/includes/formatting.php'; // elektron_escrow_format_amount()
    require_once __DIR__ . '/includes/notice.php';   // elektron_escrow_render_notice()
    require_once __DIR__ . '/includes/order-status.php'; // elektron_escrow_render_order_status()
    require_once __DIR__ . '/includes/deal.php';     // workflow glue: perform, chain check, explorer links
    require_once __DIR__ . '/includes/mail.php';     // deal update emails
    require_once __DIR__ . '/includes/payment-watcher.php'; // elektron_escrow_check_all_deals()
    require_once __DIR__ . '/includes/arbiter.php'; // arbiter wallet, keys and decisions
    require_once __DIR__ . '/includes/wallet.php';   // connected wallet: keys per deal, setup commands
    require_once __DIR__ . '/includes/hooks.php';    // item widget, routes, cron

    // Activation / deactivation. See install.php.
    // osc_register_plugin() genuinely does want osc_plugin_path(__FILE__)
    // here (the full path), matching how Shopclass's own bundled
    // sample-widgets plugin calls it. The hook names below are different:
    // Plugins::uninstall() and CAdminPlugins's "Configure" action fire
    // '<relative-path-to-index.php>_uninstall'/'_configure' (PLUGINS_PATH
    // already stripped on their side, confirmed in Plugins.php), so the
    // hook must be registered under that same relative identifier, not
    // the full path osc_plugin_path(__FILE__) returns.
    osc_register_plugin(osc_plugin_path(__FILE__), 'elektron_escrow_install');
    osc_add_hook('osclass-escrow/index.php_uninstall', 'elektron_escrow_uninstall');

    // "Configure" action in the admin plugin list -> renders admin/settings.php
    // inside the oc-admin skin. See the comment above osc_register_plugin()
    // for why this hook name uses the relative path, not
    // osc_plugin_path(__FILE__).
    osc_add_hook('osclass-escrow/index.php_configure', function () {
        osc_admin_render_plugin('osclass-escrow/admin/settings.php');
    });

    // Dedicated top-level admin submenu entry, so the settings page is not
    // only reachable through the plugin list's "Configure" link.
    osc_add_hook('admin_menu_init', function () {
        osc_admin_menu_plugins(
            __('Elektron Net Escrow', ELEKTRON_ESCROW_DOMAIN),
            osc_admin_render_plugin_url('osclass-escrow/admin/settings.php'),
            'elektron_escrow_settings'
        );
        osc_admin_menu_plugins(
            __('Elektron Net Arbiter', ELEKTRON_ESCROW_DOMAIN),
            osc_admin_render_plugin_url('osclass-escrow/admin/arbiter.php'),
            'elektron_escrow_arbiter'
        );
    });

    // Frontend custom pages (checkout + order status). Handler files live
    // under controllers/, which does not match the "/admin/" substring the
    // core custom-page controller (CWebCustom) rejects for frontend routes.
    //
    // Every regexp below ends in (?![a-zA-Z]): Rewrite::init() matches with
    // '#^' . $regexp . '#' -- no trailing anchor at all (confirmed in
    // oc-includes/osclass/classes/Rewrite.php) -- and takes the first
    // registered route whose regexp matches as a *prefix* of the request
    // URI, `break`-ing immediately. Without the lookahead,
    // 'elektron-escrow/order' is itself a valid prefix match for a request
    // to 'elektron-escrow/orders', so the order-status route (registered
    // first) silently claimed every /orders request instead of the orders
    // route below ever being reached -- confirmed live: it rendered
    // order-status.php's own "Order not found." message, since
    // Params::getParam('order') was never set on that URL. The lookahead
    // requires whatever follows the literal path segment to not be another
    // letter, so 'order' can no longer match the start of 'orders' (it
    // still matches 'order' itself, 'order?...', 'order/...').
    osc_add_route(
        'elektron_escrow_checkout',
        'elektron-escrow/checkout(?![a-zA-Z])',
        'elektron-escrow/checkout',
        'osclass-escrow/controllers/checkout.php'
    );
    osc_add_route(
        'elektron_escrow_order_status',
        'elektron-escrow/order(?![a-zA-Z])',
        'elektron-escrow/order',
        'osclass-escrow/controllers/order-status.php',
        true // renders inside the account-menu chrome (CWebCustom sets 'in_user_menu'), not just the public
             // theme wrapper -- this does NOT by itself add a link to that menu; see includes/hooks.php's
             // 'user_menu_filter' hook for the actual link (osc_private_user_menu() ignores this flag
             // entirely; confirmed from Rewrite::addRoute()/CWebCustom.php/hUtils.php source)
    );
    osc_add_route(
        'elektron_escrow_orders',
        'elektron-escrow/orders(?![a-zA-Z])',
        'elektron-escrow/orders',
        'osclass-escrow/controllers/orders.php',
        true // same as above: account-menu chrome only; includes/hooks.php's 'user_menu_filter' hook
             // adds the actual link, alongside the wallet one.
    );
    osc_add_route(
        'elektron_escrow_wallet',
        'elektron-escrow/wallet(?![a-zA-Z])',
        'elektron-escrow/wallet',
        'osclass-escrow/controllers/wallet.php',
        true // account-menu chrome, see the routes above
    );
    osc_add_route(
        'elektron_escrow_relist',
        'elektron-escrow/relist(?![a-zA-Z])',
        'elektron-escrow/relist',
        'osclass-escrow/controllers/relist.php'
    );
