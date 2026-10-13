<?php if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');

use ElektronNet\Escrow\DealStatus;

function elektron_escrow_status_label(string $status): string
{
    $labels = [
        DealStatus::AWAITING_KEYS => __('Waiting for keys', ELEKTRON_ESCROW_DOMAIN),
        DealStatus::AWAITING_PAYMENT => __('Waiting for payment', ELEKTRON_ESCROW_DOMAIN),
        DealStatus::CONFIRMING => __('Confirming', ELEKTRON_ESCROW_DOMAIN),
        DealStatus::FUNDED => __('Funded', ELEKTRON_ESCROW_DOMAIN),
        DealStatus::SHIPPED => __('Shipped', ELEKTRON_ESCROW_DOMAIN),
        DealStatus::AWAITING_SELLER_RELEASE => __('Waiting for seller release', ELEKTRON_ESCROW_DOMAIN),
        DealStatus::AWAITING_BUYER_RELEASE => __('Waiting for buyer release', ELEKTRON_ESCROW_DOMAIN),
        DealStatus::RELEASED => __('Released', ELEKTRON_ESCROW_DOMAIN),
        DealStatus::REFUNDED => __('Refunded', ELEKTRON_ESCROW_DOMAIN),
        DealStatus::CLAIMED => __('Claimed by seller', ELEKTRON_ESCROW_DOMAIN),
        DealStatus::CANCELLED => __('Cancelled', ELEKTRON_ESCROW_DOMAIN),
    ];

    return $labels[$status] ?? $status;
}

/**
 * What the viewing party has to do now, or what they are waiting for.
 */
function elektron_escrow_next_step(array $deal, string $role): string
{
    $status = $deal['status'];
    $mine = DealStatus::mayAct($deal, $role);
    $other = $role === 'buyer' ? __('the seller', ELEKTRON_ESCROW_DOMAIN) : __('the buyer', ELEKTRON_ESCROW_DOMAIN);
    $waiting = sprintf(__('Waiting for %s. You cannot continue until they have completed their step.', ELEKTRON_ESCROW_DOMAIN), $other);

    switch ($status) {
        case DealStatus::AWAITING_KEYS:
            if ($mine) {
                return __('Submit your two public keys (main key and exit key). The private keys stay in your wallet. When both parties have given theirs, the escrow address is fixed.', ELEKTRON_ESCROW_DOMAIN);
            }
            return sprintf(__('Waiting for %s to submit their public keys.', ELEKTRON_ESCROW_DOMAIN), $other);
        case DealStatus::AWAITING_PAYMENT:
            return $mine ? __('Check the escrow address in your wallet, then pay the amount to it. This page detects the payment on its own.', ELEKTRON_ESCROW_DOMAIN) : $waiting;
        case DealStatus::CONFIRMING:
            return __('The payment was seen. Waiting for enough confirmations.', ELEKTRON_ESCROW_DOMAIN);
        case DealStatus::FUNDED:
            return $mine ? __('The payment is confirmed. Make sure your own wallet knows the escrow and shows the payment, then ship the goods and confirm shipping.', ELEKTRON_ESCROW_DOMAIN) : $waiting;
        case DealStatus::SHIPPED:
            return $mine ? __('The seller reports that the goods were shipped. Confirm receipt once they arrived.', ELEKTRON_ESCROW_DOMAIN) : $waiting;
        case DealStatus::AWAITING_SELLER_RELEASE:
            return $mine ? __('Create the release transaction in your wallet, sign it, and paste it below.', ELEKTRON_ESCROW_DOMAIN) : $waiting;
        case DealStatus::AWAITING_BUYER_RELEASE:
            return $mine ? __('Check the release transaction in your wallet, add your signature, broadcast it, and enter its transaction id.', ELEKTRON_ESCROW_DOMAIN) : $waiting;
        case DealStatus::RELEASED:
            return __('Completed: the funds were released to the seller.', ELEKTRON_ESCROW_DOMAIN);
        case DealStatus::REFUNDED:
            return __('Completed: the funds were refunded to the buyer.', ELEKTRON_ESCROW_DOMAIN);
        case DealStatus::CLAIMED:
            return __('Completed: the seller claimed the funds after the claim date.', ELEKTRON_ESCROW_DOMAIN);
        case DealStatus::CANCELLED:
            return __('This deal was cancelled. No funds were at risk.', ELEKTRON_ESCROW_DOMAIN);
    }

    return '';
}

function elektron_escrow_event_label(string $type): string
{
    $labels = [
        'created' => __('Deal created', ELEKTRON_ESCROW_DOMAIN),
        'seller_submit_key' => __('Seller submitted a public key', ELEKTRON_ESCROW_DOMAIN),
        'buyer_submit_key' => __('Buyer submitted a public key', ELEKTRON_ESCROW_DOMAIN),
        'escrow_fixed' => __('Both keys known, escrow address fixed', ELEKTRON_ESCROW_DOMAIN),
        'chain_confirming' => __('Payment seen on chain', ELEKTRON_ESCROW_DOMAIN),
        'chain_funded' => __('Payment confirmed on chain', ELEKTRON_ESCROW_DOMAIN),
        'seller_mark_shipped' => __('Seller reports shipping', ELEKTRON_ESCROW_DOMAIN),
        'buyer_mark_received' => __('Buyer confirmed receipt', ELEKTRON_ESCROW_DOMAIN),
        'seller_submit_release' => __('Seller signed a release transaction', ELEKTRON_ESCROW_DOMAIN),
        'buyer_mark_released' => __('Buyer reports the release was broadcast', ELEKTRON_ESCROW_DOMAIN),
        'buyer_mark_refunded' => __('Buyer reports the refund was broadcast', ELEKTRON_ESCROW_DOMAIN),
        'seller_mark_claimed' => __('Seller reports the claim was broadcast', ELEKTRON_ESCROW_DOMAIN),
        'cancel' => __('Deal cancelled', ELEKTRON_ESCROW_DOMAIN),
        'message' => __('Message', ELEKTRON_ESCROW_DOMAIN),
    ];

    return $labels[$type] ?? $type;
}

/**
 * Plain-language explanations for the "?" helpers next to technical terms.
 */
function elektron_escrow_help_text(string $key): string
{
    $texts = [
        'status' => __('Where this deal currently stands. Only the person named under "Next step" can act. The other side has to wait, so nobody can run ahead.', ELEKTRON_ESCROW_DOMAIN),
        'escrow' => __('A special payment address that needs a signature from both of you to release the money. Nobody else, including this website, can move the funds.', ELEKTRON_ESCROW_DOMAIN),
        'pubkey' => __('Your main public key. A public key is like an account number for signatures: 66 characters starting with 02 or 03. It is safe to share. The matching private key stays in your wallet and is never entered on this site. Open "How do I get this?" below the field to see where your wallet shows it.', ELEKTRON_ESCROW_DOMAIN),
        'exit_pubkey' => __('A second public key of yours, different from the main key. The main keys of both parties open the escrow together, the exit keys are for the refund and claim paths. The script rules do not allow the same key twice, therefore each party gives two. Create it the same way as the main key, with a second fresh address.', ELEKTRON_ESCROW_DOMAIN),
        'private_key' => __('Your private key proves you own the funds. Never paste it into this website. It is only used locally in your own wallet.', ELEKTRON_ESCROW_DOMAIN),
        'descriptor' => __('A text that describes the escrow address completely. Your wallet can calculate the same address from it, so you do not have to trust this page.', ELEKTRON_ESCROW_DOMAIN),
        'check_address' => __('Your wallet calculates the address on its own from both public keys. If it equals the address shown here, nobody swapped a key.', ELEKTRON_ESCROW_DOMAIN),
        'locktimes' => __('Safety exits written into the escrow address itself. After the refund date the buyer alone can take the money back. After the claim date the seller alone can take it. Neither date can be changed.', ELEKTRON_ESCROW_DOMAIN),
        'confirmations' => __('How many blocks were added after your payment. More blocks mean the payment is harder to reverse.', ELEKTRON_ESCROW_DOMAIN),
        'psbt' => __('A partially signed transaction. Think of it as a cheque with room for two signatures. You sign it in your wallet and pass it on. It cannot be changed without invalidating the signatures.', ELEKTRON_ESCROW_DOMAIN),
        'sign' => __('Signing happens inside your wallet with your private key. This website only receives the signed result.', ELEKTRON_ESCROW_DOMAIN),
        'finalize' => __('Turns the transaction with both signatures into the final form the network accepts.', ELEKTRON_ESCROW_DOMAIN),
        'broadcast' => __('Sends the finished transaction to the network. Only after this does money actually move.', ELEKTRON_ESCROW_DOMAIN),
        'txid' => __('The unique id of a transaction. Your wallet shows it after broadcasting. Others can look it up in a block explorer.', ELEKTRON_ESCROW_DOMAIN),
        'payout' => __('An address of your own wallet that should receive the money. Open "How do I get this?" below the field to see where your wallet shows it.', ELEKTRON_ESCROW_DOMAIN),
        'payout_optional' => __('Optional. If you give your two public keys and a payout address here, buyers do not have to wait for you to enter them and the deal is shorter. You can still change the payout address when you create the release.', ELEKTRON_ESCROW_DOMAIN),
        'buyer_key_optional' => __('Optional. If you give your two public keys now, the escrow address is ready as soon as the seller has keys too. You can also add it later on the order page.', ELEKTRON_ESCROW_DOMAIN),
        'message' => __('A short note to the other party. This is the only chat between you, nothing here changes the deal.', ELEKTRON_ESCROW_DOMAIN),
        'verify' => __('This site only shows what the other party submitted. Always check the amounts and addresses in your own wallet before you sign.', ELEKTRON_ESCROW_DOMAIN),
        'console' => __('Commands you type into the console of your Elektron Net node wallet: menu Window, then Console. Choose your wallet in the dropdown at the top of the console. Paste a command including its quotation marks and press Enter. Replace the parts in angle brackets with your own values.', ELEKTRON_ESCROW_DOMAIN),
        'refund' => __('If the seller does not deliver, the buyer can take the money back alone after the refund date, with a transaction made in the buyer\'s own wallet.', ELEKTRON_ESCROW_DOMAIN),
        'claim' => __('If the buyer neither confirms nor refunds, the seller can take the money alone after the claim date, with a transaction made in the seller\'s own wallet.', ELEKTRON_ESCROW_DOMAIN),
    ];

    return $texts[$key] ?? '';
}

/**
 * A small "?" that shows an explanation on hover, focus or tap.
 */
function elektron_escrow_help(string $key): string
{
    $text = elektron_escrow_help_text($key);
    if ($text === '') {
        return '';
    }

    return ' <span class="elektron-escrow-help" tabindex="0" role="note" aria-label="' . osc_esc_html($text) . '" data-tip="' . osc_esc_html($text) . '">?</span>';
}

/**
 * "How do I get this?" block for a field, with the steps for the node
 * wallet, Electrum and other wallets. $what is 'pubkey' or 'address'.
 */
function elektron_escrow_howto(string $what): string
{
    $note = '<p class="elektron-escrow-why">' . osc_esc_html(__('Menu names can differ slightly between versions.', ELEKTRON_ESCROW_DOMAIN)) . '</p>';
    $code = function (string $text): string {
        return '<code class="elektron-escrow-code">' . osc_esc_html($text) . '</code>';
    };

    if ($what === 'pubkey') {
        $body = '<p><strong>' . osc_esc_html(__('Elektron Net node wallet (console)', ELEKTRON_ESCROW_DOMAIN)) . '</strong></p>'
            . elektron_escrow_where_console()
            . '<p>' . osc_esc_html(__('You need two different keys, a main key and an exit key. Run the first line twice to get two fresh addresses, then the second line for each of them:', ELEKTRON_ESCROW_DOMAIN)) . '</p>'
            . $code('getnewaddress "escrow" bech32') . $code('getaddressinfo "<that address>"')
            . '<p>' . osc_esc_html(__('Each public key is the 66 character value in the field "pubkey" (or inside "desc"). The matching private keys stay in this wallet and are used later for signing.', ELEKTRON_ESCROW_DOMAIN)) . '</p>'
            . '<p><strong>' . osc_esc_html(__('Electrum', ELEKTRON_ESCROW_DOMAIN)) . '</strong></p>'
            . '<p>' . osc_esc_html(__('Take two different unused addresses: open the Addresses tab (View menu, Show Addresses), right-click an address and choose "Details". The public key is listed there. Electrum can neither check this escrow address nor sign for it, so you also need the matching private keys in the node wallet: right-click the address, choose "Private key", enter your password and copy the key without the leading "p2wpkh:". Never paste it into this website.', ELEKTRON_ESCROW_DOMAIN)) . '</p>'
            . '<p><strong>' . osc_esc_html(__('Other wallets', ELEKTRON_ESCROW_DOMAIN)) . '</strong></p>'
            . '<p>' . osc_esc_html(__('Any wallet works that shows the public key of one of its addresses and can export the private key of that address (WIF). Wallets that never reveal private keys, such as hardware wallets, cannot be used.', ELEKTRON_ESCROW_DOMAIN)) . '</p>';
    } else {
        $hrp = elektron_escrow_network_params()->hrp();
        $body = '<p>' . sprintf(osc_esc_html(__('An address on this network starts with "%s1". It must belong to a wallet you control.', ELEKTRON_ESCROW_DOMAIN)), osc_esc_html($hrp)) . '</p>'
            . '<p><strong>' . osc_esc_html(__('Elektron Net node wallet', ELEKTRON_ESCROW_DOMAIN)) . '</strong></p>'
            . '<p>' . osc_esc_html(__('Receive tab, "Create new receiving address", or in the console:', ELEKTRON_ESCROW_DOMAIN)) . '</p>' . $code('getnewaddress "payout" bech32')
            . '<p><strong>' . osc_esc_html(__('Electrum', ELEKTRON_ESCROW_DOMAIN)) . '</strong></p>'
            . '<p>' . osc_esc_html(__('Receive tab: the address shown there. Or Addresses tab, right-click an address, "Copy address".', ELEKTRON_ESCROW_DOMAIN)) . '</p>'
            . '<p><strong>' . osc_esc_html(__('Other wallets', ELEKTRON_ESCROW_DOMAIN)) . '</strong></p>'
            . '<p>' . osc_esc_html(__('Use any receiving address of the wallet. Prefer a fresh one, and never an address of a service that you do not control.', ELEKTRON_ESCROW_DOMAIN)) . '</p>';
    }

    return '<details class="elektron-escrow-howto"><summary>' . osc_esc_html(__('How do I get this?', ELEKTRON_ESCROW_DOMAIN)) . '</summary>' . $body . $note . '</details>';
}

/**
 * Short instruction where to type node wallet commands.
 */
function elektron_escrow_where_console(): string
{
    return '<p class="elektron-escrow-why"><strong>' . osc_esc_html(__('Where do I type this?', ELEKTRON_ESCROW_DOMAIN)) . '</strong> '
        . osc_esc_html(__('In the Elektron Net node wallet, open the menu Window, then Console. Choose your wallet in the dropdown at the top of the console. Paste the whole command including the quotation marks into the input line at the bottom and press Enter. Electrum has no such console and cannot check or sign this escrow.', ELEKTRON_ESCROW_DOMAIN))
        . '</p>';
}
