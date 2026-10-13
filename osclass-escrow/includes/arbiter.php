<?php if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');

use ElektronNet\Escrow\DealWorkflow;
use ElektronNet\Escrow\EscrowKeys;
use ElektronNet\Escrow\TimeoutPolicy;
use ElektronNet\Escrow\WalletConnection;

/**
 * The arbiter is the marketplace operator's own wallet: its master public
 * key is connected in the admin settings and one key per deal is derived
 * from it, the same way as for buyers and sellers. The arbiter can never
 * spend alone, only together with one of the two parties.
 */
function elektron_escrow_arbiter_xpub(): string
{
    return trim((string) osc_get_preference('arbiter_xpub', 'plugin-osclass-escrow'));
}

function elektron_escrow_arbiter_enabled(): bool
{
    return osc_get_preference('arbiter_enabled', 'plugin-osclass-escrow') === '1' && elektron_escrow_arbiter_xpub() !== '';
}

/**
 * @return array{main:string,exit:string,mainIndex:int,exitIndex:int}
 */
function elektron_escrow_arbiter_keys(int $dealId): array
{
    return EscrowKeys::forDeal(elektron_escrow_arbiter_xpub(), $dealId, elektron_escrow_network_params());
}

/**
 * Timeouts for deals with an arbiter: later than without one, so that the
 * arbiter has time to decide before either exit opens.
 */
function elektron_escrow_arbiter_timeouts(): TimeoutPolicy
{
    return TimeoutPolicy::fromDays(
        (int) (osc_get_preference('arbiter_buyer_refund_days', 'plugin-osclass-escrow') ?: 45),
        (int) (osc_get_preference('arbiter_seller_release_days', 'plugin-osclass-escrow') ?: 90)
    );
}

function elektron_escrow_arbiter_workflow(): DealWorkflow
{
    return new DealWorkflow(elektron_escrow_network_params(), elektron_escrow_arbiter_timeouts());
}

/**
 * Runs one arbiter action through the workflow and stores the result. The
 * admin page is the only caller; Osclass guards it with the admin login.
 *
 * @param array<string,string> $input
 * @throws \ElektronNet\Escrow\DealException
 */
function elektron_escrow_arbiter_perform(array $deal, string $action, array $input): array
{
    $updates = elektron_escrow_workflow()->perform($action, $deal, 'arbiter', $input, time());

    return elektron_escrow_deal_store($deal, $updates, 0, $action, $input);
}

/**
 * Connects the arbiter wallet from the pasted output of gethdkeys.
 *
 * @throws InvalidArgumentException with a message safe to show
 */
function elektron_escrow_arbiter_connect(string $text, string $walletName): void
{
    $connection = WalletConnection::parse($text, elektron_escrow_network_params());
    Preference::newInstance()->replace('arbiter_xpub', $connection['xpub'], 'plugin-osclass-escrow', 'STRING');
    Preference::newInstance()->replace('arbiter_fingerprint', $connection['fingerprint'], 'plugin-osclass-escrow', 'STRING');
    Preference::newInstance()->replace('arbiter_wallet_name', mb_substr($walletName, 0, 100), 'plugin-osclass-escrow', 'STRING');
}
