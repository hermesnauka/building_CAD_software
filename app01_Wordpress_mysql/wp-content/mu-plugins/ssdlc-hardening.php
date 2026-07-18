<?php
/**
 * Plugin Name: SSDLC Hardening
 * Description: Baseline WordPress hardening required by PLAN.md Phase 4 (disable XML-RPC, hide version, remove fingerprinting headers).
 * Version: 0.1.0
 * Author: hermesnauka
 */

if (!defined('ABSPATH')) {
    exit;
}

// Disable XML-RPC (not needed by this platform; reduces brute-force / DDoS
// surface). The xmlrpc_enabled filter alone does NOT stop the endpoint from
// responding (a common WordPress hardening mistake — it only disables a
// handful of authenticated methods inside wp_xmlrpc_server, while
// system.multicall/pingback.ping still respond) so the endpoint is also
// blocked outright below.
add_filter('xmlrpc_enabled', '__return_false');
remove_action('wp_head', 'rsd_link');
remove_action('wp_head', 'wlwmanifest_link');

add_action('init', function (): void {
    if (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) {
        status_header(403);
        header('Content-Type: text/plain; charset=utf-8');
        die('XML-RPC services are disabled on this site.');
    }
});

// Hide the WordPress version from markup, RSS feeds, and script/style query strings.
remove_action('wp_head', 'wp_generator');
add_filter('the_generator', '__return_empty_string');

function cad_edu_remove_version_from_assets(string $src): string
{
    if (str_contains($src, 'ver=')) {
        $src = remove_query_arg('ver', $src);
    }
    return $src;
}
add_filter('style_loader_src', 'cad_edu_remove_version_from_assets');
add_filter('script_loader_src', 'cad_edu_remove_version_from_assets');

// Disable file editing from wp-admin (defense in depth; also set via WORDPRESS_CONFIG_EXTRA).
if (!defined('DISALLOW_FILE_EDIT')) {
    define('DISALLOW_FILE_EDIT', true);
}

// Remove the X-Pingback header and other fingerprinting response headers.
add_filter('wp_headers', function (array $headers): array {
    unset($headers['X-Pingback']);
    return $headers;
});

/**
 * X-Pingback is a WordPress-added header covered above via wp_headers, but
 * that filter (and the 'send_headers' action) only fires on the main
 * front-end query — wp-admin pages and REST API responses never go through
 * WP::main() and were still leaking X-Powered-By: PHP/<version> (confirmed
 * via curl against /wp-admin/ and the REST API during Phase 5 testing).
 * 'init' fires unconditionally before any output in every context (front
 * end, admin, REST, admin-ajax), so headers set here apply everywhere.
 *
 * Also adds the security headers ZAP's Phase 5 baseline scan flagged as
 * missing: MIME-sniffing protection, clickjacking protection (both the
 * legacy header and the CSP directive, since older browsers only honor the
 * former), a permissions policy disabling unused browser features, and a
 * CSP that's deliberately permissive on script/style ('unsafe-inline' is
 * required — WordPress core, wp-admin, and Gutenberg rely on inline
 * scripts/styles throughout, and there is no nonce/hash infrastructure here
 * to tighten that without breaking the admin) but restricts embeds/objects
 * and explicitly allow-lists the PayPal domains the checkout flow needs.
 *
 * Not covered here: static assets (theme/plugin .css/.js) served directly
 * by Apache never run this PHP at all. Setting headers on those requires
 * mod_headers, which the stock `wordpress:php8.2-apache` image doesn't
 * enable (and AllowOverride is None, so a wp-content/.htaccess wouldn't be
 * honored either) — would need a custom Dockerfile to fix, out of scope
 * for this change.
 */
add_action('init', function (): void {
    header_remove('X-Powered-By');

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), camera=(), microphone=(), payment=(self "https://www.paypal.com" "https://www.sandbox.paypal.com")');

    $paypal = "https://*.paypal.com https://www.paypalobjects.com";
    header(
        "Content-Security-Policy: " .
        "default-src 'self'; " .
        "script-src 'self' 'unsafe-inline' 'unsafe-eval' {$paypal}; " .
        "style-src 'self' 'unsafe-inline' {$paypal}; " .
        "img-src 'self' data: https:; " .
        "font-src 'self' data:; " .
        "connect-src 'self' {$paypal}; " .
        "frame-src 'self' {$paypal}; " .
        "frame-ancestors 'self'; " .
        "base-uri 'self'; " .
        "object-src 'none';"
    );
}, 1);
