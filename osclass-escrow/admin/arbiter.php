<?php if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');

use ElektronNet\Escrow\DealException;
use ElektronNet\Escrow\DealStatus;

/**
 * Arbiter page in the admin area: the list of cases that need a decision or
 * a signature, and one case at a time. Osclass guards admin pages with the
 * admin login, so only an administrator reaches this file.
 */

$statusMessage = null;
$errorMessage = null;
$dealDao = EscrowDealDAO::newInstance();
$caseId = (int) Params::getParam('deal');
$deal = $caseId > 0 ? $dealDao->findByPrimaryKey($caseId) : null;
if ($caseId > 0 && (!$deal || empty($deal['arbiter_pubkey']))) {
    $deal = null;
    $errorMessage = __('Case not found.', ELEKTRON_ESCROW_DOMAIN);
}

$action = (string) Params::getParam('arbiter_action');
if ($deal && $action !== '') {
    osc_csrf_check();
    if ($action === 'arbiter_message') {
        $text = trim((string) Params::getParam('message', false, false));
        if ($text !== '') {
            $dealDao->addEvent((int) $deal['pk_i_id'], 0, 'arbiter_message', mb_substr($text, 0, 2000));
            elektron_escrow_send_arbiter_message_email($deal, $text);
            $statusMessage = __('Message sent to buyer and seller.', ELEKTRON_ESCROW_DOMAIN);
        }
        $action = '';
    }
}
if ($deal && $action !== '') {
    $input = [];
    foreach (['ruling', 'note', 'txid'] as $field) {
        $input[$field] = (string) Params::getParam($field, false, false);
    }
    try {
        $deal = elektron_escrow_arbiter_perform($deal, $action, $input);
        $statusMessage = __('Saved.', ELEKTRON_ESCROW_DOMAIN);
    } catch (DealException $e) {
        $errorMessage = __($e->getMessage(), ELEKTRON_ESCROW_DOMAIN);
    }
} elseif ($deal) {
    $deal = elektron_escrow_deal_check_chain($deal);
}

$cases = $dealDao->findArbiterCases();
require osc_plugins_path() . 'osclass-escrow/views/admin-arbiter.php';
