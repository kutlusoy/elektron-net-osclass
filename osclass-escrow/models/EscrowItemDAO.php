<?php if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');

/**
 * Optional seller data stored with a listing: the public key the seller
 * will sign with and the address that should receive the payout. Both are
 * copied into a deal when it is created, so later edits never touch a
 * running deal.
 */
class EscrowItemDAO extends DAO
{
    /** @var EscrowItemDAO */
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
        $this->setTableName('elektron_escrow_item');
        $this->setPrimaryKey('fk_i_item_id');
        $this->setFields(['fk_i_item_id', 'seller_pubkey', 'payout_address']);
    }

    /**
     * @return array{seller_pubkey:?string,payout_address:?string}
     */
    public function forItem(int $itemId): array
    {
        $row = $this->findByPrimaryKey($itemId);

        return [
            'seller_pubkey' => $row ? ($row['seller_pubkey'] ?: null) : null,
            'payout_address' => $row ? ($row['payout_address'] ?: null) : null,
        ];
    }

    public function saveForItem(int $itemId, ?string $pubKey, ?string $payoutAddress): void
    {
        $exists = (bool) $this->findByPrimaryKey($itemId);
        if (($pubKey === null || $pubKey === '') && ($payoutAddress === null || $payoutAddress === '')) {
            if ($exists) {
                $this->delete(['fk_i_item_id' => $itemId]);
            }

            return;
        }
        $fields = ['seller_pubkey' => $pubKey, 'payout_address' => $payoutAddress];
        if ($exists) {
            $this->updateByPrimaryKey($fields, $itemId);
        } else {
            $this->insert(['fk_i_item_id' => $itemId] + $fields);
        }
    }
}
