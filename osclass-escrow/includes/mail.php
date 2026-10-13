<?php if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');

use ElektronNet\Escrow\DealStatus;

/**
 * Notifies whoever has to act next after a status change. A finished deal
 * notifies both parties, a new deal always also the seller. Uses osc_sendMail() directly with this plugin's own
 * gettext domain instead of Osclass's admin-editable email templates.
 */
function elektron_escrow_send_turn_email(array $deal, bool $created = false): void
{
    $status = $deal['status'];
    $roles = [];
    foreach (['buyer', 'seller'] as $role) {
        if (DealStatus::isTerminal($status) || DealStatus::mayAct($deal, $role)) {
            $roles[] = $role;
        }
    }
    if ($created && !in_array('seller', $roles, true)) {
        $roles[] = 'seller';
    }
    $item = Item::newInstance()->findByPrimaryKey((int) $deal['fk_i_item_id']);
    $itemTitle = $item !== false ? $item['s_title'] : '';
    $dealUrl = osc_route_url('elektron_escrow_order_status', ['order' => $deal['pk_i_id']]);

    foreach ($roles as $role) {
        $user = User::newInstance()->findByPrimaryKey((int) $deal['fk_i_' . $role . '_id']);
        if ($user === false || trim((string) $user['s_email']) === '') {
            continue;
        }
        $body = '<p>' . sprintf(osc_esc_html(__('Hello %s,', ELEKTRON_ESCROW_DOMAIN)), osc_esc_html($user['s_name'])) . '</p>'
            . '<p>' . sprintf(osc_esc_html(__('Update for your Elektron Net escrow deal #%d, "%s":', ELEKTRON_ESCROW_DOMAIN)), (int) $deal['pk_i_id'], osc_esc_html($itemTitle)) . '</p>'
            . '<p><strong>' . osc_esc_html(elektron_escrow_next_step($deal, $role)) . '</strong></p>'
            . '<p><a href="' . osc_esc_html($dealUrl) . '">' . osc_esc_html(__('Open the deal', ELEKTRON_ESCROW_DOMAIN)) . '</a></p>';
        osc_sendMail([
            'to' => $user['s_email'],
            'to_name' => $user['s_name'],
            'subject' => sprintf(__('Elektron Net escrow deal #%d: %s', ELEKTRON_ESCROW_DOMAIN), (int) $deal['pk_i_id'], elektron_escrow_status_label($status)),
            'body' => $body,
        ], 'elektron_escrow_deal_update');
    }
}
