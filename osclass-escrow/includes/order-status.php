<?php if (!defined('ABS_PATH')) exit('ABS_PATH is not loaded. Direct access is not allowed.');

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;

/**
 * Renders one deal page directly into the current response. Shared by
 * controllers/order-status.php and controllers/checkout.php (see
 * includes/notice.php for why this is rendered instead of redirected to).
 */
function elektron_escrow_render_order_status(array $deal, int $userId, ?string $statusMessage = null, ?string $errorMessage = null): void
{
    $role = elektron_escrow_deal_role($deal, $userId);
    $item = Item::newInstance()->findByPrimaryKey((int) $deal['fk_i_item_id']);
    $amountElek = ((int) $deal['amount_lep']) / 100000000;
    $requiredConfirmations = elektron_escrow_config()->requiredConfirmations();
    $events = EscrowDealDAO::newInstance()->events((int) $deal['pk_i_id']);
    require osc_plugins_path() . 'osclass-escrow/views/order-status.php';
}

function elektron_escrow_deal_url(array $deal): string
{
    return osc_route_url('elektron_escrow_order_status', ['order' => $deal['pk_i_id']]);
}

function elektron_escrow_qr_image(string $data, string $alt): string
{
    try {
        $png = (new PngWriter())->write(new QrCode($data))->getString();
    } catch (\Throwable $e) {
        return '';
    }

    return '<img src="data:image/png;base64,' . base64_encode($png) . '" alt="' . osc_esc_html($alt) . '" />';
}

/**
 * Read-only text block with a QR code and a download link, for passing
 * data (PSBT or transaction) to the other party's wallet.
 */
function elektron_escrow_copy_block(string $label, string $value, string $id, ?string $downloadName = null, ?string $helpKey = null, bool $qr = true): void
{
    echo '<p><label for="' . osc_esc_html($id) . '"><strong>' . osc_esc_html($label) . '</strong></label>' . ($helpKey !== null ? elektron_escrow_help($helpKey) : '') . '</p>';
    if ($qr) {
        echo elektron_escrow_qr_image($value, $label);
    }
    echo '<textarea id="' . osc_esc_html($id) . '" class="elektron-escrow-code" readonly="readonly" rows="5" onclick="this.select();">' . osc_esc_html($value) . '</textarea>';
    echo '<p><button type="button" class="btn btn-default" onclick="var t=document.getElementById(\'' . osc_esc_html($id) . '\');t.select();document.execCommand(\'copy\');">' . osc_esc_html(__('Copy', ELEKTRON_ESCROW_DOMAIN)) . '</button>';
    if ($downloadName !== null) {
        $binary = base64_decode($value, true);
        $href = $binary !== false
            ? 'data:application/octet-stream;base64,' . $value
            : 'data:text/plain;charset=utf-8,' . rawurlencode($value);
        echo ' <a class="btn btn-default" download="' . osc_esc_html($downloadName) . '" href="' . osc_esc_html($href) . '">' . osc_esc_html(__('Download file', ELEKTRON_ESCROW_DOMAIN)) . '</a>';
    }
    echo '</p>';
}

/**
 * One workflow action as a form: hidden action marker, CSRF token, optional input fields.
 *
 * @param array<string,array{label:string,type?:string,placeholder?:string,help?:string,value?:string}> $fields
 */
function elektron_escrow_action_form(array $deal, string $action, string $submitLabel, array $fields = [], ?string $confirmText = null): void
{
    $onsubmit = $confirmText !== null ? ' onsubmit="return confirm(\'' . osc_esc_js($confirmText) . '\');"' : '';
    echo '<form method="post" action="' . osc_esc_html(elektron_escrow_deal_url($deal)) . '"' . $onsubmit . '>';
    echo osc_csrf_token_form();
    echo '<input type="hidden" name="deal_action" value="' . osc_esc_html($action) . '" />';
    foreach ($fields as $name => $field) {
        $id = 'elektron-escrow-' . $action . '-' . $name;
        echo '<p><label for="' . osc_esc_html($id) . '">' . osc_esc_html($field['label']) . '</label>' . (isset($field['help']) ? elektron_escrow_help($field['help']) : '') . '<br />';
        if (($field['type'] ?? 'text') === 'textarea') {
            echo '<textarea name="' . osc_esc_html($name) . '" id="' . osc_esc_html($id) . '" rows="5" required="required" placeholder="' . osc_esc_html($field['placeholder'] ?? '') . '"></textarea>';
        } else {
            echo '<input type="text" name="' . osc_esc_html($name) . '" id="' . osc_esc_html($id) . '" size="70" required="required" autocomplete="off" placeholder="' . osc_esc_html($field['placeholder'] ?? '') . '" value="' . osc_esc_html($field['value'] ?? '') . '" />';
        }
        echo '</p>';
    }
    echo '<input type="submit" class="btn btn-primary" value="' . osc_esc_html($submitLabel) . '" /></form>';
}
