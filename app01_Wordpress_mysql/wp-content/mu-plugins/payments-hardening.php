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
 * Allow-list (by class-name prefix) of payment gateway providers permitted on
 * this platform — everything else, including WooCommerce's own built-in
 * manual/offline gateways (BACS, Cheque, Cash on Delivery) and any future
 * third-party gateway plugin, is excluded by default per US-01/US-02/US-03/
 * US-08 (only off-site, tokenized processing may touch this platform).
 *
 * This was previously a denylist of three hardcoded class names, which let
 * any other manual/offline gateway (a differently-named custom or
 * third-party "pay on invoice" plugin, for example) pass through unnoticed.
 * An allow-list fails closed instead: a new gateway plugin is blocked until
 * explicitly trusted here.
 *
 * Add the certified BLIK/PayU/Przelewy24 plugin's gateway class prefix once
 * installed and its off-site/tokenized flow has been verified.
 */
function cad_edu_allowed_payment_gateways(array $gateways): array
{
    $allowed_prefixes = [
        'WooCommerce\\PayPalCommerce\\', // official WooCommerce PayPal Payments plugin
    ];

    return array_values(array_filter($gateways, static function ($gateway) use ($allowed_prefixes): bool {
        $class = is_object($gateway) ? get_class($gateway) : (string) $gateway;
        foreach ($allowed_prefixes as $prefix) {
            if (str_starts_with($class, $prefix)) {
                return true;
            }
        }
        return false;
    }));
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

/**
 * Revokes the cad_student role when a granting order is refunded or
 * cancelled, unless the buyer still has another completed order that
 * independently grants access (so refunding one module purchase doesn't
 * lock a student out of a second, still-valid one).
 */
function cad_edu_revoke_student_role_if_unentitled(int $order_id): void
{
    $order = wc_get_order($order_id);
    if (!$order) {
        return;
    }

    $user_id = $order->get_customer_id();
    if ($user_id <= 0) {
        return;
    }

    $user = get_user_by('id', $user_id);
    if ($user === false || !in_array('cad_student', (array) $user->roles, true)) {
        return;
    }

    if (cad_edu_customer_has_other_granting_order($user_id, $order_id)) {
        return;
    }

    $user->remove_role('cad_student');
}
add_action('woocommerce_order_status_refunded', 'cad_edu_revoke_student_role_if_unentitled');
add_action('woocommerce_order_status_cancelled', 'cad_edu_revoke_student_role_if_unentitled');

function cad_edu_customer_has_other_granting_order(int $user_id, int $excluding_order_id): bool
{
    $order_ids = wc_get_orders([
        'customer_id' => $user_id,
        'status' => ['completed'],
        'exclude' => [$excluding_order_id],
        'limit' => -1,
        'return' => 'ids',
    ]);

    foreach ($order_ids as $other_order_id) {
        $other_order = wc_get_order($other_order_id);
        if (!$other_order) {
            continue;
        }

        foreach ($other_order->get_items() as $item) {
            $product = $item->get_product();
            if ($product && $product->get_meta('_cad_grants_student_access') === 'yes') {
                return true;
            }
        }
    }

    return false;
}
