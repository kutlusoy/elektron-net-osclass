<?php if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');

/**
 * Runs once when the plugin is activated (see index.php's
 * osc_register_plugin() call). Creates the plugin's own order table and
 * seeds the admin-settings preferences with the suite-wide defaults.
 *
 * Nothing here touches Osclass/Shopclass's own tables (t_item, t_user, ...).
 * Shopclass does have a native billing/order system (mindstellar\billing\
 * Billing, Order, t_billing_order), but it is a credits-purchase flow: a
 * user buys credits from the marketplace operator itself, which are later
 * spent on premium features. There is no seller, no per-item payment, and
 * Billing::markPaid() mints credits to the buyer's own account, not funds
 * to a different user. That does not fit escrow (buyer pays a specific
 * seller for a specific item, funds sit in a 2-of-2 address, not an
 * account balance), so this plugin keeps its own order table rather than
 * forcing the flow through an API built for a different problem.
 */
function elektron_escrow_install()
{
    $dao = EscrowOrderDAO::newInstance();
    $dao->dao->query(
        'CREATE TABLE IF NOT EXISTS ' . DB_TABLE_PREFIX . 'elektron_escrow_order (
            pk_i_id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            fk_i_item_id INT(10) UNSIGNED NOT NULL,
            fk_i_buyer_id INT(10) UNSIGNED NOT NULL,
            fk_i_seller_id INT(10) UNSIGNED NOT NULL,
            buyer_pubkey VARCHAR(66) NOT NULL,
            buyer_pubkey_index INT(10) UNSIGNED NOT NULL,
            seller_pubkey VARCHAR(66) NOT NULL,
            seller_pubkey_index INT(10) UNSIGNED NOT NULL,
            seller_payout_address VARCHAR(90) NOT NULL,
            seller_payout_index INT(10) UNSIGNED NOT NULL,
            buyer_shipping_name VARCHAR(190) NOT NULL,
            buyer_shipping_address TEXT NOT NULL,
            buyer_shipping_phone VARCHAR(40) NOT NULL,
            buyer_contact_email VARCHAR(190) NOT NULL,
            s_address VARCHAR(90) NOT NULL,
            redeem_script_hex TEXT NOT NULL,
            buyer_refund_locktime INT(10) UNSIGNED NOT NULL,
            seller_release_locktime INT(10) UNSIGNED NOT NULL,
            amount_lep BIGINT(20) UNSIGNED NOT NULL,
            status VARCHAR(40) NOT NULL DEFAULT \'awaiting_payment\',
            psbt_base64 MEDIUMTEXT NULL,
            psbt_signature_count TINYINT(1) NOT NULL DEFAULT 0,
            buyer_refund_psbt_base64 MEDIUMTEXT NULL,
            seller_claim_psbt_base64 MEDIUMTEXT NULL,
            dt_pub_date DATETIME NOT NULL,
            dt_mod_date DATETIME NULL,

            PRIMARY KEY (pk_i_id),
            INDEX idx_item (fk_i_item_id),
            INDEX idx_buyer (fk_i_buyer_id),
            INDEX idx_seller (fk_i_seller_id),
            INDEX idx_status (status),
            INDEX idx_s_address (s_address)
        ) ENGINE=InnoDB DEFAULT CHARACTER SET \'UTF8\' COLLATE \'UTF8_GENERAL_CI\''
    );

    $dao->dao->query(
        'CREATE TABLE IF NOT EXISTS ' . DB_TABLE_PREFIX . 'elektron_escrow_wallet (
            fk_i_user_id INT(10) UNSIGNED NOT NULL,
            xpub VARCHAR(120) NOT NULL,
            next_index INT(10) UNSIGNED NOT NULL DEFAULT 0,
            dt_pub_date DATETIME NOT NULL,

            PRIMARY KEY (fk_i_user_id)
        ) ENGINE=InnoDB DEFAULT CHARACTER SET \'UTF8\' COLLATE \'UTF8_GENERAL_CI\''
    );

    elektron_escrow_set_default_preferences();
}

/**
 * Writes every admin-settings default, but only for a preference that does
 * not already have a value; an existing installation's saved values are
 * never overwritten. Only called at install time, since every field always
 * has a value after that (admin/settings.php always writes every field on
 * save, even one left as its current default).
 *
 * Uses Preference::replace(), not the DAO-inherited insert(). Shopclass's
 * Preference class caches every value in memory once, at construction
 * (Preference::toArray()); insert() writes straight to t_preference without
 * telling that cache, so a value inserted this way would read back empty
 * for the rest of the same request. replace() is what the rest of the
 * codebase uses for exactly this reason: it writes the row and updates the
 * cache together. Verified against github.com/mindstellar/shopclass's own
 * Preference.php, not assumed.
 */
function elektron_escrow_set_default_preferences()
{
    $defaults = [
        // Free-text list; the admin UI can add/remove/reorder entries, the
        // plugin tries them in order and fails over on timeout or error
        // (ChainData\FallbackChainDataProvider in the shared core).
        'chain_data_endpoints' => "https://mempool.elektron-net.org/api\nhttps://mempool2.elektron-net.org/api",
        'required_confirmations' => '3',
        'fee_rate_lep_per_vbyte' => '10',
        'buyer_refund_days' => (string) \ElektronNet\Payments\Core\Escrow\TimeoutPolicy::DEFAULT_BUYER_REFUND_DAYS,
        'seller_release_days' => (string) \ElektronNet\Payments\Core\Escrow\TimeoutPolicy::DEFAULT_SELLER_RELEASE_DAYS,
        'network' => 'mainnet',
        // Osclass's own currency table stores a code as CHAR(3) (confirmed
        // against a real installation's own
        // oc-includes/osclass/installer/struct.sql), so "ELEK" can never
        // actually be stored there; a list of accepted 3-letter codes lets
        // this cope with different operators already having picked
        // different contractions before that was known. See
        // elektron_escrow_currency_codes() in includes/config.php.
        'currency_codes' => "ELE\nELK",
    ];

    foreach ($defaults as $name => $value) {
        if (osc_get_preference($name, 'plugin-osclass-escrow') === '') {
            Preference::newInstance()->replace($name, $value, 'plugin-osclass-escrow', 'STRING');
        }
    }
}


/**
 * Ensures timeout-PSBT columns exist on existing installations.
 * Safe to call on every request; does nothing if the columns are already present.
 */
function elektron_escrow_ensure_schema()
{
    $dao = EscrowOrderDAO::newInstance();
    $table = DB_TABLE_PREFIX . 'elektron_escrow_order';

    // Check for one of the new columns; if missing, add both.
    $cols = $dao->dao->query("SHOW COLUMNS FROM {$table} LIKE 'buyer_refund_psbt_base64'");
    $exists = false;
    if ($cols) {
        $row = $cols->row();
        $exists = !empty($row);
    }
    if (!$exists) {
        $dao->dao->query(
            "ALTER TABLE {$table}
             ADD COLUMN buyer_refund_psbt_base64 MEDIUMTEXT NULL AFTER psbt_signature_count,
             ADD COLUMN seller_claim_psbt_base64 MEDIUMTEXT NULL AFTER buyer_refund_psbt_base64"
        );
    }
}

function elektron_escrow_uninstall()
{
    // delete() (DAO-inherited) rather than replace()/set(): there is no
    // native "remove a whole section" method on Preference, and nothing
    // reads a preference back again in this same request afterward, so the
    // cache-staleness that rules out insert()/update() above does not apply
    // here.
    Preference::newInstance()->delete(['s_section' => 'plugin-osclass-escrow']);
    // The order table is deliberately kept on uninstall (it is transaction
    // history, not configuration); a marketplace operator who wants it gone
    // drops elektron_escrow_order manually.
}
