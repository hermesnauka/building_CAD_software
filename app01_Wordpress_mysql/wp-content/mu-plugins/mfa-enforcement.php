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
 * they enable a provider. Their own profile page and the logout action stay
 * reachable so they aren't locked out.
 */
function cad_edu_enforce_mfa_enrollment(): void
{
    if (wp_doing_ajax() || wp_doing_cron() || (defined('DOING_AJAX') && DOING_AJAX)) {
        return;
    }

    $user = wp_get_current_user();
    if (!$user->exists() || !cad_edu_user_requires_mfa_enrollment($user)) {
        return;
    }

    global $pagenow;
    if (in_array($pagenow, ['profile.php', 'admin-ajax.php'], true)) {
        return;
    }

    wp_safe_redirect(add_query_arg('cad_mfa_required', '1', admin_url('profile.php')));
    exit;
}
add_action('admin_init', 'cad_edu_enforce_mfa_enrollment');

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
