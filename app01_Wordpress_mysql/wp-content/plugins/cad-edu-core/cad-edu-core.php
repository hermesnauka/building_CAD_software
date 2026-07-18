<?php
/**
 * Plugin Name: CAD Edu Core
 * Description: Core custom functionality for the Construction Tech & CAD Edu-Commerce Platform (content gating, CAD extension catalog).
 * Version: 0.1.0
 * Author: hermesnauka
 * Text Domain: cad-edu-core
 */

if (!defined('ABSPATH')) {
    exit;
}

define('CAD_EDU_CORE_VERSION', '0.1.0');
define('CAD_EDU_CORE_PATH', plugin_dir_path(__FILE__));

/**
 * Registers the "cad_extension" custom post type used for the CAD software
 * license / extension sales catalog (see REQUIREMENTS.md, US-01..US-03).
 */
function cad_edu_register_post_types(): void
{
    register_post_type('cad_extension', [
        'label' => __('CAD Extensions', 'cad-edu-core'),
        'public' => true,
        'show_in_rest' => true,
        'supports' => ['title', 'editor', 'thumbnail', 'excerpt'],
        'has_archive' => true,
        'capability_type' => 'post',
    ]);

    register_post_type('cad_module', [
        'label' => __('Educational Modules', 'cad-edu-core'),
        'public' => true,
        'show_in_rest' => true,
        'supports' => ['title', 'editor', 'thumbnail', 'excerpt'],
        'has_archive' => true,
        'capability_type' => 'post',
    ]);
}
add_action('init', 'cad_edu_register_post_types');

/**
 * RBAC (see PLAN.md Phase 3, USER_STORIES.md US-05/US-07): only administrators,
 * editors, and enrolled students may read gated premium module content. The
 * "cad_student" role is the entitlement point a future commerce integration
 * (WooCommerce order-completed hook) grants to a buyer after purchase.
 */
function cad_edu_register_roles_and_caps(): void
{
    if (get_role('cad_student') === null) {
        add_role('cad_student', __('CAD Student', 'cad-edu-core'), [
            'read' => true,
            'read_premium_cad_module' => true,
        ]);
    } elseif (!get_role('cad_student')->has_cap('read_premium_cad_module')) {
        get_role('cad_student')->add_cap('read_premium_cad_module');
    }

    foreach (['administrator', 'editor'] as $role_name) {
        $role = get_role($role_name);
        if ($role !== null && !$role->has_cap('read_premium_cad_module')) {
            $role->add_cap('read_premium_cad_module');
        }
    }
}
register_activation_hook(__FILE__, 'cad_edu_register_roles_and_caps');

/**
 * Re-syncs role capabilities on every load so a role added before this code
 * existed (or edited via a UI plugin) doesn't silently lose the grant.
 */
add_action('init', 'cad_edu_register_roles_and_caps');

function cad_edu_deactivate(): void
{
    remove_role('cad_student');

    foreach (['administrator', 'editor'] as $role_name) {
        $role = get_role($role_name);
        if ($role !== null) {
            $role->remove_cap('read_premium_cad_module');
        }
    }
}
register_deactivation_hook(__FILE__, 'cad_edu_deactivate');

/**
 * Restricts premium educational module content to logged-in, entitled users
 * (see USER_STORIES.md US-05). Entitlement check is left to the commerce
 * plugin (e.g. WooCommerce) integration; this only enforces the gate point.
 */
function cad_edu_gate_premium_content(string $content): string
{
    if (!is_singular('cad_module')) {
        return $content;
    }

    if (is_user_logged_in() && current_user_can('read_premium_cad_module')) {
        return $content;
    }

    return '<p>' . esc_html__('This educational module is available to enrolled students only.', 'cad-edu-core') . '</p>';
}
add_filter('the_content', 'cad_edu_gate_premium_content');
