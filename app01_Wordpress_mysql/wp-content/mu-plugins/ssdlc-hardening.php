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

// X-Pingback is a WordPress-added header covered above; X-Powered-By is set
// by PHP itself (the expose_php ini directive) and is not affected by the
// wp_headers filter, so it has to be stripped separately.
add_action('send_headers', function (): void {
    header_remove('X-Powered-By');
});
