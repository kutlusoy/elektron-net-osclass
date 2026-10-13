<?php if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');

use ElektronNet\Escrow\DealException;
use ElektronNet\Escrow\DealStatus;
use ElektronNet\Escrow\DealWorkflow;
use ElektronNet\Escrow\NetworkParams;

function elektron_escrow_network_params(): NetworkParams
{
    return NetworkParams::forNetwork(elektron_escrow_config()->network());
}

function elektron_escrow_deal_role(array $deal, int $userId): ?string
{
    if ($userId === (int) $deal['fk_i_buyer_id']) {
        return 'buyer';
    }
    if ($userId === (int) $deal['fk_i_seller_id']) {
        return 'seller';
    }

    return null;
}

function elektron_escrow_workflow(): DealWorkflow
{
    return new DealWorkflow(elektron_escrow_network_params(), elektron_escrow_config()->timeoutPolicy());
}

/**
 * Runs one user action through the workflow, persists the result and
 * notifies the party whose turn it is next.
 *
 * @param array<string,string> $input submitted form fields
 * @return array the updated deal row
 * @throws DealException with a message safe to show to the user
 */
function elektron_escrow_deal_perform(array $deal, string $action, int $userId, array $input): array
{
    $role = elektron_escrow_deal_role($deal, $userId);
    if ($role === null) {
        throw new DealException('Deal not found.');
    }

    if ($action === 'cancel' && $deal['status'] === DealStatus::AWAITING_PAYMENT) {
        $deal = elektron_escrow_deal_check_chain($deal);
        if ($deal['status'] !== DealStatus::AWAITING_PAYMENT || elektron_escrow_deal_payment_seen($deal)) {
            throw new DealException('A payment to the escrow address was detected, so this deal can no longer be cancelled.');
        }
    }
    if ($action === 'seller_submit_release') {
        $deal = elektron_escrow_deal_refresh_funding($deal);
    }

    $updates = elektron_escrow_workflow()->perform($action, $deal, $role, $input, time());

    return elektron_escrow_deal_store($deal, $updates, $userId, $action, $input);
}

/**
 * @param array<string,mixed> $updates
 * @param array<string,string> $input
 */
function elektron_escrow_deal_store(array $deal, array $updates, int $userId, string $eventType, array $input = []): array
{
    $dao = EscrowDealDAO::newInstance();
    $updates['dt_mod_date'] = date('Y-m-d H:i:s');
    $dao->updateByPrimaryKey($updates, (int) $deal['pk_i_id']);

    $dao->addEvent((int) $deal['pk_i_id'], $userId, $eventType, isset($input['txid']) ? trim($input['txid']) : null);

    if (isset($updates['escrow_address']) && empty($deal['escrow_address'])) {
        $dao->addEvent((int) $deal['pk_i_id'], 0, 'escrow_fixed');
    }

    $updated = array_merge($deal, $updates);
    if ($updated['status'] !== $deal['status'] || ($updated['seller_pubkey'] ?? null) !== ($deal['seller_pubkey'] ?? null) || ($updated['buyer_pubkey'] ?? null) !== ($deal['buyer_pubkey'] ?? null)) {
        elektron_escrow_send_turn_email($updated);
    }

    return $updated;
}

/**
 * @return array{receivedLep:int,bestConfirmations:int}|null null if the chain could not be read
 */
function elektron_escrow_deal_payment_totals(array $deal): ?array
{
    try {
        $transactions = elektron_escrow_chain_data()->getAddressTransactions($deal['escrow_address']);
    } catch (\Throwable $e) {
        error_log('elektron-escrow: chain lookup failed for deal ' . $deal['pk_i_id'] . ': ' . $e->getMessage());

        return null;
    }

    $received = 0;
    $best = 0;
    foreach ($transactions as $transaction) {
        $received += $transaction->receivedLep();
        $best = max($best, $transaction->confirmations());
    }

    return ['receivedLep' => $received, 'bestConfirmations' => $best];
}

function elektron_escrow_deal_payment_seen(array $deal): bool
{
    $totals = elektron_escrow_deal_payment_totals($deal);

    return $totals === null || $totals['receivedLep'] > 0;
}

/**
 * Looks the payment up on chain and moves the deal forward when it has been
 * seen or has enough confirmations. Read-only towards the chain.
 */
function elektron_escrow_deal_check_chain(array $deal): array
{
    if (in_array($deal['status'], [DealStatus::AWAITING_PAYMENT, DealStatus::CONFIRMING], true)) {
        $totals = elektron_escrow_deal_payment_totals($deal);
        if ($totals !== null) {
            $updates = elektron_escrow_workflow()->advanceFromChain(
                $deal,
                $totals['receivedLep'],
                $totals['bestConfirmations'],
                elektron_escrow_config()->requiredConfirmations()
            );
            if ($updates !== []) {
                $deal = elektron_escrow_deal_store($deal, $updates, 0, 'chain_' . $updates['status']);
            }
        }
    }

    if (in_array($deal['status'], DealStatus::FUNDS_AT_RISK, true) && $deal['status'] !== DealStatus::CONFIRMING && empty($deal['funding_outputs'])) {
        $deal = elektron_escrow_deal_refresh_funding($deal);
    }

    return $deal;
}

/**
 * Stores the confirmed, unspent outputs at the escrow address. The release
 * PSBT must spend exactly these.
 */
function elektron_escrow_deal_refresh_funding(array $deal): array
{
    try {
        $outputs = elektron_escrow_chain_data()->getFundingOutputs($deal['escrow_address']);
    } catch (\Throwable $e) {
        return $deal;
    }
    if ($outputs === []) {
        return $deal;
    }

    $list = array_map(function ($output) {
        return ['txid' => $output->txid(), 'vout' => $output->vout(), 'amountLep' => $output->amountLep()];
    }, $outputs);
    $json = json_encode($list);
    if ($json === ($deal['funding_outputs'] ?? null)) {
        return $deal;
    }
    EscrowDealDAO::newInstance()->updateByPrimaryKey(['funding_outputs' => $json], (int) $deal['pk_i_id']);
    $deal['funding_outputs'] = $json;

    return $deal;
}

/**
 * @return array<int,array{txid:string,vout:int,amountLep:int}>
 */
function elektron_escrow_deal_funding_list(array $deal): array
{
    $list = json_decode((string) ($deal['funding_outputs'] ?? ''), true);

    return is_array($list) ? $list : [];
}

/**
 * Explorer link derived from the first configured chain-data endpoint
 * (its API base without the trailing /api).
 */
function elektron_escrow_explorer_url(string $kind, string $value): ?string
{
    $endpoints = elektron_escrow_config()->chainDataEndpoints();
    $base = preg_replace('#/api/?$#', '', rtrim($endpoints[0], '/'));
    if (!preg_match('#^https?://#', $base)) {
        return null;
    }

    return $base . '/' . $kind . '/' . rawurlencode($value);
}
