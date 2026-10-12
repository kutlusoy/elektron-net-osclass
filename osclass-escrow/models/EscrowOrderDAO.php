<?php if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');

use ElektronNet\Payments\Core\Escrow\OrderStatus;

/**
 * DAO for the plugin's own order table (t_elektron_escrow_order). Never
 * touches Osclass's native t_item table beyond reading an item's id, price,
 * and owner; the order/escrow record lives entirely in this plugin's own
 * storage, since Osclass has no built-in order/checkout concept.
 *
 * Column-to-core mapping: `status` stores one of the
 * ElektronNet\Payments\Core\Escrow\OrderStatus constants; `redeem_script_hex`,
 * `buyer_refund_locktime`, and `seller_release_locktime` are copied verbatim
 * from the EscrowAddress returned at order creation and are never
 * recomputed later (see core/README.md).
 */
class EscrowOrderDAO extends DAO
{
    /** @var EscrowOrderDAO */
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
        $this->setTableName('elektron_escrow_order');
        $this->setPrimaryKey('pk_i_id');
        $this->setFields([
            'pk_i_id',
            'fk_i_item_id',
            'fk_i_buyer_id',
            'fk_i_seller_id',
            'buyer_pubkey',
            'buyer_pubkey_index',
            'seller_pubkey',
            'seller_pubkey_index',
            'seller_payout_address',
            'seller_payout_index',
            'buyer_shipping_name',
            'buyer_shipping_address',
            'buyer_shipping_phone',
            'buyer_contact_email',
            's_address',
            'redeem_script_hex',
            'buyer_refund_locktime',
            'seller_release_locktime',
            'amount_lep',
            'status',
            'psbt_base64',
            'psbt_signature_count',
            'buyer_refund_psbt_base64',
            'seller_claim_psbt_base64',
            'dt_pub_date',
            'dt_mod_date',
        ]);
    }

    /**
     * The order this buyer can still resume for this item, i.e. the most
     * recent one that has not reached a terminal state
     * (ElektronNet\Payments\Core\Escrow\OrderStatus::TERMINAL). A terminal
     * order (released, refunded, or claimed by the seller) is history, not
     * something a repurchase should resume into -- if it were, a buyer who
     * completed a purchase and later bought the same listing again would be
     * shown the old, already-settled escrow address instead of a fresh one.
     *
     * @return array|null the order row to resume, or null if this buyer has
     *     no non-terminal order for this item (so checkout.php should
     *     create a new one)
     */
    public function findByItemAndBuyer(int $itemId, int $buyerId): ?array
    {
        $this->dao->select();
        $this->dao->from($this->getTableName());
        $this->dao->where('fk_i_item_id', $itemId);
        $this->dao->where('fk_i_buyer_id', $buyerId);
        $this->dao->whereNotIn('status', OrderStatus::TERMINAL);
        $this->dao->orderBy('pk_i_id', 'DESC');
        $this->dao->limit(1);
        $result = $this->dao->get();

        if ($result === false || $result->numRows() < 1) {
            return null;
        }

        return $result->row();
    }

    /**
     * Deletes the order outright, but only if it still belongs to $buyerId
     * and is still `awaiting_payment` -- both checked in the same query, not
     * as a separate lookup beforehand, so this can never delete a different
     * buyer's order or one that already has a real payment associated with
     * it (`confirming` or later). A cancelled order is deleted rather than
     * moved to a new "cancelled" status: nothing about it (no address ever
     * paid to, no history worth keeping) is different from an order that
     * was never created in the first place.
     *
     * @return bool true if a row was actually deleted
     */
    public function cancelByBuyer(int $orderId, int $buyerId): bool
    {
        $affected = $this->delete([
            $this->getPrimaryKey() => $orderId,
            'fk_i_buyer_id' => $buyerId,
            'status' => OrderStatus::AWAITING_PAYMENT,
        ]);

        return is_int($affected) && $affected > 0;
    }

    /**
     * Every order still waiting on the buyer's own confirmation, i.e. one
     * whose buyer_refund_locktime the daily cron sweep needs to check
     * against Notifications\ReminderScheduler. Not filtered by how close
     * that threshold is; the scheduler decides that per order.
     *
     * @return array[] list of order rows
     */
    public function findFunded(): array
    {
        $this->dao->select();
        $this->dao->from($this->getTableName());
        $this->dao->where('status', 'funded');
        $result = $this->dao->get();

        return $result === false ? [] : $result->result();
    }

    /**
     * Every order the payment watcher (includes/hooks.php's 'cron_minutely'
     * hook) still needs to check against the chain: one whose own escrow
     * address might have received a payment that has not been reflected in
     * `status` yet.
     *
     * @return array[] list of order rows
     */
    public function findAwaitingPaymentOrConfirming(): array
    {
        $this->dao->select();
        $this->dao->from($this->getTableName());
        $this->dao->whereIn('status', [OrderStatus::AWAITING_PAYMENT, OrderStatus::CONFIRMING]);
        $result = $this->dao->get();

        return $result === false ? [] : $result->result();
    }

    /**
     * Every order that could still end in an unilateral seller release,
     * i.e. one whose seller_release_locktime the daily cron sweep needs to
     * check against Notifications\ReminderScheduler.
     *
     * @return array[] list of order rows
     */
    public function findOpenForSellerRelease(): array
    {
        $this->dao->select();
        $this->dao->from($this->getTableName());
        $this->dao->whereIn('status', ['funded', 'release_pending_seller_signature']);
        $result = $this->dao->get();

        return $result === false ? [] : $result->result();
    }

    /**
     * Every order (any status, including terminal ones) where $userId is
     * the buyer, most recent first. Powers the "My Elektron orders" page:
     * unlike findByItemAndBuyer(), a settled order must still show up here,
     * since that page is the shop-style order history, not a resume check.
     *
     * @return array[] list of order rows
     */
    public function findByBuyer(int $userId): array
    {
        return $this->findAllByColumn('fk_i_buyer_id', $userId);
    }

    /**
     * Same as findByBuyer(), for the seller side of the same page.
     *
     * @return array[] list of order rows
     */
    public function findBySeller(int $userId): array
    {
        return $this->findAllByColumn('fk_i_seller_id', $userId);
    }

    private function findAllByColumn(string $column, int $userId): array
    {
        $this->dao->select();
        $this->dao->from($this->getTableName());
        $this->dao->where($column, $userId);
        $this->dao->orderBy('pk_i_id', 'DESC');
        $result = $this->dao->get();

        return $result === false ? [] : $result->result();
    }

    /**
     * True if $userId has any non-terminal order, as buyer or as seller.
     * Used to block changing a connected wallet's public key while an
     * order still depends on the currently connected one (see
     * includes/wallet.php): every order's own buyer_pubkey/seller_pubkey/
     * redeem_script_hex is copied at creation time and never re-read from
     * elektron_escrow_wallet afterward, so changing this table has no
     * effect at all on an existing order either way -- this check exists
     * purely to stop a user from mistakenly believing it would.
     *
     * Two separate queries rather than one OR'd query: this DAO's
     * `$this->dao` query builder has no group()/parenthesization support,
     * and combining `(buyer = X OR seller = X)` with `status NOT IN (...)`
     * in a single call would risk SQL evaluating it as
     * `buyer = X OR (seller = X AND status NOT IN (...))`, which would
     * silently reintroduce a buyer's terminal orders as "active".
     */
    public function hasActiveOrderForUser(int $userId): bool
    {
        return $this->hasNonTerminalOrderByColumn('fk_i_buyer_id', $userId)
            || $this->hasNonTerminalOrderByColumn('fk_i_seller_id', $userId);
    }

    private function hasNonTerminalOrderByColumn(string $column, int $userId): bool
    {
        $this->dao->select();
        $this->dao->from($this->getTableName());
        $this->dao->where($column, $userId);
        $this->dao->whereNotIn('status', OrderStatus::TERMINAL);
        $result = $this->dao->get();

        return $result !== false && $result->numRows() > 0;
    }
}
