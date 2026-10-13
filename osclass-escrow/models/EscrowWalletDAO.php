<?php if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');

/**
 * The public part of a user's wallet: the master extended public key, plus an optional payout address. Nothing secret is stored.
 */
class EscrowWalletDAO extends DAO
{
    /** @var EscrowWalletDAO */
    private static $instance;

    public static function newInstance()
    {
        if (!self::$instance instanceof self) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function __construct()
    {
        parent::__construct();
        $this->setTableName('elektron_escrow_wallet');
        $this->setPrimaryKey('fk_i_user_id');
        $this->setFields(['fk_i_user_id', 's_fingerprint', 's_wallet_name', 's_xpub', 's_payout_address', 'dt_pub_date']);
    }

    public function forUser(int $userId): ?array
    {
        $row = $this->findByPrimaryKey($userId);

        return $row ?: null;
    }

    public function saveForUser(int $userId, string $fingerprint, ?string $walletName, string $xpub, ?string $payoutAddress): void
    {
        $fields = ['s_fingerprint' => $fingerprint, 's_wallet_name' => $walletName ?: null, 's_xpub' => $xpub, 's_payout_address' => $payoutAddress ?: null];
        if ($this->findByPrimaryKey($userId)) {
            $this->updateByPrimaryKey($fields, $userId);
        } else {
            $this->insert(['fk_i_user_id' => $userId, 'dt_pub_date' => date('Y-m-d H:i:s')] + $fields);
        }
    }

    public function remove(int $userId): void
    {
        $this->delete(['fk_i_user_id' => $userId]);
    }
}
