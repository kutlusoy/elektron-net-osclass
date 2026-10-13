<?php if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');

/**
 * Runs once when the plugin is activated (see index.php's
 * osc_register_plugin() call). Creates the plugin's own tables (deals, deal
 * history, connected wallets) and
 * seeds the admin-settings preferences with the suite-wide defaults.
 *
 * Nothing here touches Osclass/Shopclass's own tables (t_item, t_user, ...).
 * Shopclass's native billing system is a credits-purchase flow between a
 * user and the marketplace operator and has no seller or per-item payment,
 * so it does not fit escrow and this plugin keeps its own tables.
 */
function elektron_escrow_install()
{
    elektron_escrow_create_deal_tables();
    elektron_escrow_set_default_preferences();
}

function elektron_escrow_create_deal_tables()
{
    $dao = EscrowDealDAO::newInstance();
    $dao->dao->query(
        'CREATE TABLE IF NOT EXISTS ' . DB_TABLE_PREFIX . 'elektron_escrow_deal (
            pk_i_id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            fk_i_item_id INT(10) UNSIGNED NOT NULL,
            fk_i_buyer_id INT(10) UNSIGNED NOT NULL,
            fk_i_seller_id INT(10) UNSIGNED NOT NULL,
            amount_lep BIGINT(20) UNSIGNED NOT NULL,
            status VARCHAR(40) NOT NULL DEFAULT \'awaiting_payment\',
            buyer_shipping_name VARCHAR(190) NOT NULL,
            buyer_shipping_address TEXT NOT NULL,
            buyer_shipping_phone VARCHAR(40) NOT NULL,
            buyer_contact_email VARCHAR(190) NOT NULL,
            seller_pubkey VARCHAR(66) NULL,
            seller_exit_pubkey VARCHAR(66) NULL,
            buyer_pubkey VARCHAR(66) NULL,
            buyer_exit_pubkey VARCHAR(66) NULL,
            escrow_address VARCHAR(100) NULL,
            witness_script_hex TEXT NULL,
            buyer_refund_locktime INT(10) UNSIGNED NULL,
            seller_release_locktime INT(10) UNSIGNED NULL,
            funding_outputs TEXT NULL,
            seller_payout_address VARCHAR(100) NULL,
            release_psbt_signed MEDIUMTEXT NULL,
            closing_txid VARCHAR(64) NULL,
            b_item_relisted TINYINT(1) NOT NULL DEFAULT 0,
            buyer_prepared_at DATETIME NULL,
            seller_prepared_at DATETIME NULL,
            dt_pub_date DATETIME NOT NULL,
            dt_mod_date DATETIME NULL,
            PRIMARY KEY (pk_i_id),
            INDEX idx_item (fk_i_item_id),
            INDEX idx_buyer (fk_i_buyer_id),
            INDEX idx_seller (fk_i_seller_id),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARACTER SET \'UTF8\' COLLATE \'UTF8_GENERAL_CI\''
    );

    $dao->dao->query(
        'CREATE TABLE IF NOT EXISTS ' . DB_TABLE_PREFIX . 'elektron_escrow_wallet (
            fk_i_user_id INT(10) UNSIGNED NOT NULL,
            s_fingerprint CHAR(8) NOT NULL,
            s_wallet_name VARCHAR(100) NULL,
            s_xpub VARCHAR(120) NOT NULL,
            s_payout_address VARCHAR(100) NULL,
            dt_pub_date DATETIME NOT NULL,
            PRIMARY KEY (fk_i_user_id)
        ) ENGINE=InnoDB DEFAULT CHARACTER SET \'UTF8\' COLLATE \'UTF8_GENERAL_CI\''
    );

    $dao->dao->query(
        'CREATE TABLE IF NOT EXISTS ' . DB_TABLE_PREFIX . 'elektron_escrow_deal_event (
            pk_i_id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            fk_i_deal_id INT(10) UNSIGNED NOT NULL,
            fk_i_user_id INT(10) UNSIGNED NOT NULL DEFAULT 0,
            s_type VARCHAR(40) NOT NULL,
            s_text TEXT NULL,
            dt_pub_date DATETIME NOT NULL,
            PRIMARY KEY (pk_i_id),
            INDEX idx_deal (fk_i_deal_id)
        ) ENGINE=InnoDB DEFAULT CHARACTER SET \'UTF8\' COLLATE \'UTF8_GENERAL_CI\''
    );
}

/**
 * Writes every admin-settings default, but only for a preference that does
 * not already have a value. Uses Preference::replace() because Shopclass's
 * Preference class caches values at construction and insert() would leave
 * that cache stale for the rest of the request.
 */
function elektron_escrow_set_default_preferences()
{
    $defaults = [
        'chain_data_endpoints' => "https://mempool.elektron-net.org/api\nhttps://mempool2.elektron-net.org/api",
        'required_confirmations' => '3',
        'buyer_refund_days' => (string) \ElektronNet\Escrow\TimeoutPolicy::DEFAULT_BUYER_REFUND_DAYS,
        'seller_release_days' => (string) \ElektronNet\Escrow\TimeoutPolicy::DEFAULT_SELLER_RELEASE_DAYS,
        'network' => 'mainnet',
        // Osclass stores currency codes as CHAR(3), so "ELEK" cannot be one; see elektron_escrow_currency_codes().
        'currency_codes' => "ELE\nELK",
    ];

    foreach ($defaults as $name => $value) {
        if (osc_get_preference($name, 'plugin-osclass-escrow') === '') {
            Preference::newInstance()->replace($name, $value, 'plugin-osclass-escrow', 'STRING');
        }
    }
}

/**
 * Creates missing tables and adds missing deal columns on installations
 * that were activated by an earlier version of the plugin.
 */
function elektron_escrow_ensure_schema()
{
    if (osc_get_preference('schema_version', 'plugin-osclass-escrow') === '5') {
        return;
    }
    $dao = EscrowDealDAO::newInstance();
    elektron_escrow_create_deal_tables();

    $columns = [
        'seller_pubkey' => 'VARCHAR(66) NULL',
        'seller_exit_pubkey' => 'VARCHAR(66) NULL',
        'buyer_pubkey' => 'VARCHAR(66) NULL',
        'buyer_exit_pubkey' => 'VARCHAR(66) NULL',
        'escrow_address' => 'VARCHAR(100) NULL',
        'witness_script_hex' => 'TEXT NULL',
        'buyer_refund_locktime' => 'INT(10) UNSIGNED NULL',
        'seller_release_locktime' => 'INT(10) UNSIGNED NULL',
        'funding_outputs' => 'TEXT NULL',
        'seller_payout_address' => 'VARCHAR(100) NULL',
        'release_psbt_signed' => 'MEDIUMTEXT NULL',
        'closing_txid' => 'VARCHAR(64) NULL',
        'b_item_relisted' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'buyer_prepared_at' => 'DATETIME NULL',
        'seller_prepared_at' => 'DATETIME NULL',
        'dt_mod_date' => 'DATETIME NULL',
    ];
    $table = DB_TABLE_PREFIX . 'elektron_escrow_deal';
    $result = $dao->dao->query('SHOW COLUMNS FROM ' . $table);
    if (!$result) {
        return;
    }
    $existing = array_column($result->result(), 'Field');
    foreach ($columns as $name => $definition) {
        if (!in_array($name, $existing, true)) {
            $dao->dao->query('ALTER TABLE ' . $table . ' ADD COLUMN ' . $name . ' ' . $definition);
        }
    }
    $walletTable = DB_TABLE_PREFIX . 'elektron_escrow_wallet';
    $walletColumns = $dao->dao->query('SHOW COLUMNS FROM ' . $walletTable);
    $walletExisting = $walletColumns ? array_column($walletColumns->result(), 'Field') : [];
    if ($walletExisting !== [] && !in_array('s_wallet_name', $walletExisting, true)) {
        $dao->dao->query('ALTER TABLE ' . $walletTable . ' ADD COLUMN s_wallet_name VARCHAR(100) NULL');
    }
    if (in_array('s_origin_path', $walletExisting, true)) {
        $dao->dao->query('ALTER TABLE ' . $walletTable . ' DROP COLUMN s_origin_path');
    }
    Preference::newInstance()->replace('schema_version', '5', 'plugin-osclass-escrow', 'STRING');
}

function elektron_escrow_uninstall()
{
    Preference::newInstance()->delete(['s_section' => 'plugin-osclass-escrow']);
    // The deal tables are kept on purpose: they are transaction history, not configuration.
}
