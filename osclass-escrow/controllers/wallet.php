<?php if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');

use ElektronNet\Escrow\Address;
use ElektronNet\Escrow\WalletConnection;

/**
 * Route: elektron-escrow/wallet
 * Connects a wallet once per account by its public data. Anything that
 * contains a private key is refused before it is looked at any further and
 * is never stored or shown again.
 */

if (!osc_is_web_user_logged_in()) {
    elektron_escrow_render_notice(
        __('Please log in to connect your wallet.', ELEKTRON_ESCROW_DOMAIN),
        osc_user_login_url(),
        __('Log in', ELEKTRON_ESCROW_DOMAIN)
    );
    exit;
}

$userId = (int) osc_logged_user_id();
$walletDao = EscrowWalletDAO::newInstance();
$statusMessage = null;
$errorMessage = null;
$returnUrl = (string) Params::getParam('return');
if ($returnUrl !== '' && strpos($returnUrl, osc_base_url()) !== 0) {
    $returnUrl = '';
}

if (Params::getParam('wallet_action') === 'connect') {
    osc_csrf_check();
    $text = (string) Params::getParam('wallet_text', false, false);
    $payout = trim((string) Params::getParam('payout_address', false, false));
    try {
        $connection = WalletConnection::parse($text, elektron_escrow_network_params());
        if ($payout !== '' && Address::toScriptPubKeyHex($payout, elektron_escrow_network_params()) === null) {
            throw new InvalidArgumentException('The payout address is not a valid address for this network.');
        }
        $walletDao->saveForUser($userId, $connection['fingerprint'], $connection['xpub'], $payout);
        $statusMessage = __('Your wallet is connected.', ELEKTRON_ESCROW_DOMAIN);
    } catch (InvalidArgumentException $e) {
        $errorMessage = __($e->getMessage(), ELEKTRON_ESCROW_DOMAIN);
    }
} elseif (Params::getParam('wallet_action') === 'disconnect') {
    osc_csrf_check();
    if (EscrowDealDAO::newInstance()->countOpenForUser($userId) > 0) {
        $errorMessage = __('You have open deals. Disconnect after they are finished, the keys of those deals come from this wallet.', ELEKTRON_ESCROW_DOMAIN);
    } else {
        $walletDao->remove($userId);
        $statusMessage = __('Your wallet is disconnected.', ELEKTRON_ESCROW_DOMAIN);
    }
}

$wallet = $walletDao->forUser($userId);
require osc_plugins_path() . 'osclass-escrow/views/wallet.php';
