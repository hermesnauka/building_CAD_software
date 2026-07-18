<?php
/**
 * Plugin Name: CAD Edu Payments Hardening
 * Description: Enforces the tokenized-payment posture required by PLAN.md
 * Phase 4 and USER_STORIES.md US-01/US-02/US-03/US-08: only gateways that
 * process funds off-site (never touching our MySQL DB with card/bank data)
 * may be enabled, and a completed order that grants premium-module access
 * upgrades the buyer to the cad_student role.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Allow-list of WooCommerce payment gateways permitted on this platform.
 * WooCommerce's built-in BACS (direct bank transfer), Cheque, and Cash on
 * Delivery gateways are manual/offline and out of scope for US-01/US-02/
 * US-03 (BLIK, PayPal, bank-login gateways) — they are removed here so an
 * admin can't accidentally enable a non-tokenized, manually-reconciled
 * payment method. Add BLIK/PayU/Przelewy24 gateway classes to this list
 * once their certified plugins are installed.
 */
function cad_edu_allowed_payment_gateways(array $gateways): array
{
    $denylist = [
        'WC_Gateway_BACS',
        'WC_Gateway_Cheque',
        'WC_Gateway_COD',
    ];

    return array_values(array_filter(
        $gateways,
        static fn ($gateway): bool => !in_array(is_object($gateway) ? get_class($gateway) : $gateway, $denylist, true)
    ));
}
add_filter('woocommerce_payment_gateways', 'cad_edu_allowed_payment_gateways', 20);

/**
 * Grants the cad_student role (see cad-edu-core.php) to a buyer once their
 * order is completed, if any purchased product is marked to grant premium
 * module access via the "_cad_grants_student_access" custom field
 * (Product data > Advanced > Custom Fields in wp-admin).
 */
function cad_edu_grant_student_role_on_order_completed(int $order_id): void
{
    $order = wc_get_order($order_id);
    if (!$order) {
        return;
    }

    $grants_access = false;
    foreach ($order->get_items() as $item) {
        $product = $item->get_product();
        if ($product && $product->get_meta('_cad_grants_student_access') === 'yes') {
            $grants_access = true;
            break;
        }
    }

    if (!$grants_access) {
        return;
    }

    $user_id = $order->get_customer_id();
    if ($user_id <= 0) {
        return;
    }

    $user = get_user_by('id', $user_id);
    if ($user !== false && !in_array('cad_student', (array) $user->roles, true)) {
        $user->add_role('cad_student');
    }
}
add_action('woocommerce_order_status_completed', 'cad_edu_grant_student_role_on_order_completed');
