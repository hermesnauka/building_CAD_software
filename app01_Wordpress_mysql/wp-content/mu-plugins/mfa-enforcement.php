<?php
/**
 * Plugin Name: CAD Edu MFA Enforcement
 * Description: Requires administrators and editors to enroll in two-factor
 * authentication (via the Two Factor plugin) before using wp-admin, per
 * PLAN.md Phase 4 and USER_STORIES.md US-07.
 */

if (!defined('ABSPATH')) {
    exit;
}

const CAD_EDU_MFA_ROLES = ['administrator', 'editor'];

function cad_edu_user_requires_mfa_enrollment(WP_User $user): bool
{
    if (!class_exists('Two_Factor_Core')) {
        return false;
    }

    if (!array_intersect(CAD_EDU_MFA_ROLES, (array) $user->roles)) {
        return false;
    }

    return !Two_Factor_Core::is_user_using_two_factor($user->ID);
}

/**
 * Blocks wp-admin for unenrolled admins/editors, redirecting them to their
 * profile page (where the Two Factor plugin renders its enrollment UI) until
 * they enable a provider. Their own profile page stays reachable so they
 * aren't locked out.
 *
 * admin_init also fires for admin-ajax.php requests, so ajax is handled here
 * too rather than being exempted outright: the Two Factor plugin's own
 * enrollment UI is REST-backed (see cad_edu_enforce_mfa_for_rest below), not
 * ajax-backed, so no legitimate 2FA-setup action needs an ajax exemption.
 * Only 'heartbeat' is allowed through (no privileged data access/change).
 */
function cad_edu_enforce_mfa_enrollment(): void
{
    if (wp_doing_cron()) {
        return;
    }

    $user = wp_get_current_user();
    if (!$user->exists() || !cad_edu_user_requires_mfa_enrollment($user)) {
        return;
    }

    if (wp_doing_ajax()) {
        $action = isset($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : '';
        if ($action === 'heartbeat') {
            return;
        }

        wp_send_json_error(
            ['message' => __('Two-factor authentication is required before using this feature.', 'cad-edu-core')],
            403
        );
    }

    global $pagenow;
    if ($pagenow === 'profile.php') {
        return;
    }

    wp_safe_redirect(add_query_arg('cad_mfa_required', '1', admin_url('profile.php')));
    exit;
}
add_action('admin_init', 'cad_edu_enforce_mfa_enrollment');

/**
 * admin_init never fires for /wp-json/ requests, so the REST API is a
 * separate, fully privileged bypass of the enrollment gate above unless
 * covered here too. Allows the Two Factor plugin's own setup routes and the
 * "who am I" endpoint (needed by wp-admin's own UI chrome) through; denies
 * everything else for an unenrolled administrator/editor.
 */
function cad_edu_enforce_mfa_for_rest($result)
{
    if (!empty($result)) {
        return $result;
    }

    $user = wp_get_current_user();
    if (!$user->exists() || !cad_edu_user_requires_mfa_enrollment($user)) {
        return $result;
    }

    $route = cad_edu_current_rest_route();
    $allowed_prefixes = ['/wp/v2/users/me'];
    if (class_exists('Two_Factor_Core')) {
        $allowed_prefixes[] = '/' . Two_Factor_Core::REST_NAMESPACE;
    }

    foreach ($allowed_prefixes as $prefix) {
        if (str_starts_with($route, $prefix)) {
            return $result;
        }
    }

    return new WP_Error(
        'cad_edu_mfa_required',
        __('Two-factor authentication is required before using the REST API.', 'cad-edu-core'),
        ['status' => 403]
    );
}
add_filter('rest_authentication_errors', 'cad_edu_enforce_mfa_for_rest');

function cad_edu_current_rest_route(): string
{
    if (isset($_GET['rest_route'])) {
        return (string) wp_unslash($_GET['rest_route']);
    }

    $path = (string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    $prefix = '/' . rest_get_url_prefix();
    $pos = strpos($path, $prefix . '/');

    return $pos === false ? '' : substr($path, $pos + strlen($prefix));
}

function cad_edu_mfa_required_notice(): void
{
    if (!isset($_GET['cad_mfa_required'])) {
        return;
    }

    echo '<div class="notice notice-error"><p>' .
        esc_html__('Your role requires two-factor authentication. Please enable a method below before accessing the rest of the dashboard.', 'cad-edu-core') .
        '</p></div>';
}
add_action('admin_notices', 'cad_edu_mfa_required_notice');
