<?php
/**
 * Plugin Name: CAD Edu Auth Audit Log
 * Description: Logs authentication attempts and rate-limits repeated
 * failures per account, per PLAN.md Phase 6 ("activate monitoring and
 * intrusion detection systems").
 *
 * This is a local Docker Compose demo where every request originates from
 * 127.0.0.1 — IP-based blocking (the usual fail2ban approach) is meaningless
 * here, since there's no distinct attacker IP to key off of. Blocking by
 * account instead is the correct equivalent control at this layer: it's
 * what actually stops credential-stuffing/brute-force regardless of source.
 *
 * Every attempt is also logged via error_log(), which flows into
 * `docker compose logs wordpress` — scripts/intrusion-watch.sh tails that
 * output and raises an alert when the same threshold is crossed, acting as
 * the detection/alerting companion to the blocking enforced here.
 */

if (!defined('ABSPATH')) {
    exit;
}

const CAD_EDU_AUTH_LOG_PREFIX = 'CAD_EDU_AUTH';

function cad_edu_auth_lockout_threshold(): int
{
    return (int) (getenv('AUTH_LOCKOUT_THRESHOLD') ?: 5);
}

function cad_edu_auth_lockout_window_seconds(): int
{
    return (int) (getenv('AUTH_LOCKOUT_WINDOW_SECONDS') ?: 300);
}

function cad_edu_auth_lockout_duration_seconds(): int
{
    return (int) (getenv('AUTH_LOCKOUT_DURATION_SECONDS') ?: 900);
}

function cad_edu_auth_failure_transient_key(string $username): string
{
    return 'cad_edu_auth_fail_' . md5($username);
}

function cad_edu_auth_lockout_transient_key(string $username): string
{
    return 'cad_edu_auth_lockout_' . md5($username);
}

/**
 * Blocks authentication for a locked-out account before WordPress even
 * checks the password, and records every failed attempt to trigger a
 * lockout once the threshold is crossed within the configured window.
 */
function cad_edu_enforce_auth_lockout($user, string $username, string $password)
{
    if (empty($username)) {
        return $user;
    }

    $lockout_key = cad_edu_auth_lockout_transient_key($username);
    if (get_transient($lockout_key)) {
        error_log(sprintf(
            '%s: BLOCKED login attempt for locked-out account "%s"',
            CAD_EDU_AUTH_LOG_PREFIX,
            $username
        ));

        return new WP_Error(
            'cad_edu_account_locked',
            __('Too many failed login attempts. This account is temporarily locked — please try again later.', 'cad-edu-core')
        );
    }

    return $user;
}
add_filter('authenticate', 'cad_edu_enforce_auth_lockout', 30, 3);

/**
 * Hooked to wp_login_failed, which also fires when our own lockout WP_Error
 * from cad_edu_enforce_auth_lockout() is returned — without this guard,
 * every attempt against an already-locked account would double-log (once
 * here, once in cad_edu_enforce_auth_lockout) and keep resetting/extending
 * the failure window indefinitely.
 */
function cad_edu_log_failed_login(string $username): void
{
    if (get_transient(cad_edu_auth_lockout_transient_key($username))) {
        return;
    }

    error_log(sprintf(
        '%s: FAILURE login attempt for account "%s"',
        CAD_EDU_AUTH_LOG_PREFIX,
        $username
    ));

    $threshold = cad_edu_auth_lockout_threshold();
    $window = cad_edu_auth_lockout_window_seconds();
    $duration = cad_edu_auth_lockout_duration_seconds();

    $failure_key = cad_edu_auth_failure_transient_key($username);
    $failures = (int) get_transient($failure_key);
    $failures++;

    set_transient($failure_key, $failures, $window);

    if ($failures >= $threshold) {
        set_transient(cad_edu_auth_lockout_transient_key($username), true, $duration);
        delete_transient($failure_key);

        error_log(sprintf(
            '%s: LOCKOUT triggered for account "%s" after %d failures within %ds — locked for %ds',
            CAD_EDU_AUTH_LOG_PREFIX,
            $username,
            $failures,
            $window,
            $duration
        ));
    }
}
add_action('wp_login_failed', 'cad_edu_log_failed_login');

function cad_edu_log_successful_login(string $username): void
{
    error_log(sprintf(
        '%s: SUCCESS login for account "%s"',
        CAD_EDU_AUTH_LOG_PREFIX,
        $username
    ));

    delete_transient(cad_edu_auth_failure_transient_key($username));
}
add_action('wp_login', 'cad_edu_log_successful_login');
