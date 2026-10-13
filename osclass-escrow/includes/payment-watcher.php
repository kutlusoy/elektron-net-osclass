<?php if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');

/**
 * Cron entry point: checks every deal that is waiting for its funding
 * transaction to appear or confirm. The same check runs when a party opens
 * or refreshes the deal page, since Osclass cron only fires if the operator
 * enabled it.
 */
function elektron_escrow_check_all_deals(): void
{
    foreach (EscrowDealDAO::newInstance()->findAwaitingChain() as $deal) {
        elektron_escrow_deal_check_chain($deal);
    }
}
