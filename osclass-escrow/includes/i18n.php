<?php if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');

use ElektronNet\Escrow\DealStatus;

function elektron_escrow_status_label(string $status): string
{
    $labels = [
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
        'escrow_fixed' => __('Escrow address fixed from both wallets', ELEKTRON_ESCROW_DOMAIN),
        'chain_confirming' => __('Payment seen on chain', ELEKTRON_ESCROW_DOMAIN),
        'chain_funded' => __('Payment confirmed on chain', ELEKTRON_ESCROW_DOMAIN),
        'seller_mark_shipped' => __('Seller reports shipping', ELEKTRON_ESCROW_DOMAIN),
        'buyer_mark_received' => __('Buyer confirmed receipt', ELEKTRON_ESCROW_DOMAIN),
        'seller_submit_release' => __('Seller signed a release transaction', ELEKTRON_ESCROW_DOMAIN),
        'buyer_mark_released' => __('Buyer reports the release was broadcast', ELEKTRON_ESCROW_DOMAIN),
        'buyer_mark_refunded' => __('Buyer reports the refund was broadcast', ELEKTRON_ESCROW_DOMAIN),
        'seller_mark_claimed' => __('Seller reports the claim was broadcast', ELEKTRON_ESCROW_DOMAIN),
        'confirm_prepared' => __('Wallet prepared for this deal', ELEKTRON_ESCROW_DOMAIN),
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
        'wallet_public' => __('The text you paste contains only public keys, comparable to account numbers. They let this site work out the keys for each deal. They cannot sign or spend. Text with a private key is refused.', ELEKTRON_ESCROW_DOMAIN),
        'wallet_name' => __('The name of the wallet in your node software, shown in the wallet dropdown at the top of the console. It is only a reminder for you, so that you later choose the same wallet.', ELEKTRON_ESCROW_DOMAIN),
        'wallet_id' => __('A short id of your wallet, taken from the pasted text. Your wallet uses it to tell its keys apart.', ELEKTRON_ESCROW_DOMAIN),
        'pubkey' => __('A public key is like an account number for signatures. It is safe to share. The matching private key stays in your wallet and is never entered on this site.', ELEKTRON_ESCROW_DOMAIN),
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
        'fee_rate' => __('How much you pay the network for the transaction, in lep per byte. 10 is a normal value. Check the block explorer for current fees. The fee is taken from the amount you receive.', ELEKTRON_ESCROW_DOMAIN),
        'payout' => __('An address of your own wallet that should receive the money. Open "How do I get this?" below the field to see where your wallet shows it. Optional: you can also enter it when the release is created.', ELEKTRON_ESCROW_DOMAIN),
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
 * "How do I get this?" block for the payout address, with the steps for
 * the node wallet, Electrum and other wallets.
 */
function elektron_escrow_howto(string $what): string
{
    $code = function (string $text): string {
        return '<code class="elektron-escrow-code">' . osc_esc_html($text) . '</code>';
    };
    $hrp = elektron_escrow_network_params()->hrp();
    $body = '<p>' . sprintf(osc_esc_html(__('An address on this network starts with "%s1". It must belong to a wallet you control.', ELEKTRON_ESCROW_DOMAIN)), osc_esc_html($hrp)) . '</p>'
        . '<p><strong>' . osc_esc_html(__('Elektron Net node wallet', ELEKTRON_ESCROW_DOMAIN)) . '</strong></p>'
        . '<p>' . osc_esc_html(__('Receive tab, "Create new receiving address", or in the console:', ELEKTRON_ESCROW_DOMAIN)) . '</p>' . $code('getnewaddress "payout" bech32') . elektron_escrow_result_block('<COPY_ADDRESS>') . '<p>' . osc_esc_html(__('Paste <COPY_ADDRESS> (starts with', ELEKTRON_ESCROW_DOMAIN) . ' ' . $hrp . '1) ' . __('into the address field.', ELEKTRON_ESCROW_DOMAIN)) . '</p>'
        . '<p><strong>' . osc_esc_html(__('Electrum', ELEKTRON_ESCROW_DOMAIN)) . '</strong></p>'
        . '<p>' . osc_esc_html(__('Receive tab: the address shown there. Or Addresses tab, right-click an address, "Copy address".', ELEKTRON_ESCROW_DOMAIN)) . '</p>'
        . '<p><strong>' . osc_esc_html(__('Other wallets', ELEKTRON_ESCROW_DOMAIN)) . '</strong></p>'
        . '<p>' . osc_esc_html(__('Use any receiving address of the wallet. Prefer a fresh one, and never an address of a service that you do not control.', ELEKTRON_ESCROW_DOMAIN)) . '</p>'
        . '<p class="elektron-escrow-why">' . osc_esc_html(__('Menu names can differ slightly between versions.', ELEKTRON_ESCROW_DOMAIN)) . '</p>';

    return '<details class="elektron-escrow-howto"><summary>' . osc_esc_html(__('How do I get this?', ELEKTRON_ESCROW_DOMAIN)) . '</summary>' . $body . '</details>';
}

/**
 * Short instruction where to type node wallet commands.
 */
function elektron_escrow_where_console(): string
{
    return '<p class="elektron-escrow-why"><strong>' . osc_esc_html(__('Where do I type this?', ELEKTRON_ESCROW_DOMAIN)) . '</strong> '
        . osc_esc_html(__('In the Elektron Net node wallet open the menu Window, then Console. Paste the whole command, including all quotation marks, into the input line at the bottom and press Enter.', ELEKTRON_ESCROW_DOMAIN))
        . '</p>';
}

/**
 * Example of what a console command prints, with the part to copy highlighted.
 * Mark that part as <COPY_NAME> in $text.
 */
function elektron_escrow_result_block(string $text): string
{
    $html = preg_replace('/&lt;(COPY_[A-Z0-9_]+)&gt;/', '<mark>&lt;$1&gt;</mark>', osc_esc_html($text));

    return '<p>' . osc_esc_html(__('The result looks like this. Copy only the highlighted part:', ELEKTRON_ESCROW_DOMAIN)) . '</p><pre class="elektron-escrow-code">' . $html . '</pre>';
}
