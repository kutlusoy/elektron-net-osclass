<?php if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');

use ElektronNet\Escrow\DealStatus;

/**
 * DAO for the plugin's own deal table. Osclass's native tables are only
 * read, never modified.
 */
class EscrowDealDAO extends DAO
{
    /** @var EscrowDealDAO */
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
        $this->setTableName('elektron_escrow_deal');
        $this->setPrimaryKey('pk_i_id');
        $this->setFields([
            'pk_i_id', 'fk_i_item_id', 'fk_i_buyer_id', 'fk_i_seller_id', 'amount_lep', 'status',
            'buyer_shipping_name', 'buyer_shipping_address', 'buyer_shipping_phone', 'buyer_contact_email',
            'seller_pubkey', 'seller_exit_pubkey', 'buyer_pubkey', 'buyer_exit_pubkey', 'escrow_address', 'witness_script_hex',
            'buyer_refund_locktime', 'seller_release_locktime', 'funding_outputs',
            'seller_payout_address', 'release_psbt_signed', 'closing_txid',
            'dt_pub_date', 'dt_mod_date',
        ]);
    }

    /**
     * The buyer's most recent deal for this item that is not finished yet.
     */
    public function findOpenByItemAndBuyer(int $itemId, int $buyerId): ?array
    {
        $this->dao->select();
        $this->dao->from($this->getTableName());
        $this->dao->where('fk_i_item_id', $itemId);
        $this->dao->where('fk_i_buyer_id', $buyerId);
        $this->dao->whereNotIn('status', DealStatus::TERMINAL);
        $this->dao->orderBy('pk_i_id', 'DESC');
        $this->dao->limit(1);
        $result = $this->dao->get();
        if ($result === false || $result->numRows() < 1) {
            return null;
        }

        return $result->row();
    }

    public function insertDeal(array $fields): int
    {
        $this->insert($fields);

        return (int) $this->dao->insertedId();
    }

    /**
     * @return array[]
     */
    public function findByBuyer(int $userId): array
    {
        return $this->findAllByColumn('fk_i_buyer_id', $userId);
    }

    /**
     * @return array[]
     */
    public function findBySeller(int $userId): array
    {
        return $this->findAllByColumn('fk_i_seller_id', $userId);
    }

    /**
     * Number of unfinished deals the user takes part in, as buyer or seller.
     */
    public function countOpenForUser(int $userId): int
    {
        $this->dao->select('COUNT(*) AS total');
        $this->dao->from($this->getTableName());
        $this->dao->whereNotIn('status', DealStatus::TERMINAL);
        $this->dao->where('(fk_i_buyer_id = ' . $userId . ' OR fk_i_seller_id = ' . $userId . ')');
        $result = $this->dao->get();

        return $result === false ? 0 : (int) $result->row()['total'];
    }

    /**
     * Deals whose payment the chain watcher still has to look for.
     *
     * @return array[]
     */
    public function findAwaitingChain(): array
    {
        return $this->findByStatuses([DealStatus::AWAITING_PAYMENT, DealStatus::CONFIRMING]);
    }

    /**
     * Deals in which the funds may be on chain and a timeout can still apply.
     *
     * @return array[]
     */
    public function findFundsAtRisk(): array
    {
        return $this->findByStatuses(DealStatus::FUNDS_AT_RISK);
    }

    /**
     * @param string[] $statuses
     * @return array[]
     */
    private function findByStatuses(array $statuses): array
    {
        $this->dao->select();
        $this->dao->from($this->getTableName());
        $this->dao->whereIn('status', $statuses);
        $result = $this->dao->get();

        return $result === false ? [] : $result->result();
    }

    /**
     * @return array[]
     */
    private function findAllByColumn(string $column, int $userId): array
    {
        $this->dao->select();
        $this->dao->from($this->getTableName());
        $this->dao->where($column, $userId);
        $this->dao->orderBy('pk_i_id', 'DESC');
        $result = $this->dao->get();

        return $result === false ? [] : $result->result();
    }

    public function addEvent(int $dealId, int $userId, string $type, ?string $text = null): void
    {
        $this->dao->insert(DB_TABLE_PREFIX . 'elektron_escrow_deal_event', [
            'fk_i_deal_id' => $dealId,
            'fk_i_user_id' => $userId,
            's_type' => $type,
            's_text' => $text,
            'dt_pub_date' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @return array[] oldest first
     */
    public function events(int $dealId): array
    {
        $this->dao->select();
        $this->dao->from(DB_TABLE_PREFIX . 'elektron_escrow_deal_event');
        $this->dao->where('fk_i_deal_id', $dealId);
        $this->dao->orderBy('pk_i_id', 'ASC');
        $result = $this->dao->get();

        return $result === false ? [] : $result->result();
    }
}
