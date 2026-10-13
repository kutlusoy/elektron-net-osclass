<?php if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');

use ElektronNet\Escrow\Address;
use ElektronNet\Escrow\PubKey;

function elektron_escrow_render_item_fields(?int $itemId): void
{
    $values = $itemId === null ? ['seller_pubkey' => null, 'payout_address' => null] : EscrowItemDAO::newInstance()->forItem($itemId);
    $pubKey = (string) $values['seller_pubkey'];
    $payoutAddress = (string) $values['payout_address'];
    require osc_plugins_path() . 'osclass-escrow/views/item-fields.php';
}

/**
 * Saves the optional seller key and payout address of a listing. Invalid
 * values are dropped with a flash message instead of blocking the listing.
 *
 * @param array $item Osclass item row
 */
function elektron_escrow_save_item_fields($item): void
{
    if (Params::getParam('elektron_escrow_fields') !== '1' || empty($item['pk_i_id']) || empty($item['fk_i_user_id'])) {
        return;
    }

    $pubKey = strtolower(trim((string) Params::getParam('elektron_escrow_pubkey')));
    $address = trim((string) Params::getParam('elektron_escrow_payout_address'));

    if ($pubKey !== '' && !PubKey::isValidCompressed($pubKey)) {
        osc_add_flash_error_message(__('The Elektron public key was not saved: it must be 66 hex characters starting with 02 or 03.', ELEKTRON_ESCROW_DOMAIN));
        $pubKey = '';
    }
    if ($address !== '' && Address::toScriptPubKeyHex($address, elektron_escrow_network_params()) === null) {
        osc_add_flash_error_message(__('The Elektron payout address was not saved: it is not a valid address for this network.', ELEKTRON_ESCROW_DOMAIN));
        $address = '';
    }

    EscrowItemDAO::newInstance()->saveForItem((int) $item['pk_i_id'], $pubKey, $address);
}
