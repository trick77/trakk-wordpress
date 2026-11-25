<?php

/**
 * One request, from the plugin's load to the end of shutdown, as one event of the server feed.
 */

namespace Trakk;

defined('ABSPATH') || exit;

final class Recorder
{
    private Settings $settings;
    private Queue $queue;
    /** @var array<string, mixed> */
    private array $server;
    private int $resolved = 0;
    private string $op = '';
    private string $err = '';

    private function __construct(Settings $settings)
    {
        $this->settings = $settings;
        $this->queue = new Queue();
        $this->server = request_server();
    }

    public static function start(Settings $settings): void
    {
        if (PHP_SAPI === 'cli' || wp_installing() || self::own_cron()) {
            return;
        }
        $r = new self($settings);
        if (is_local($r->server, $settings->hops)) {
            return; // a local health check, a system cron on wp-cron.php
        }
        add_filter('determine_current_user', [$r, 'resolved'], PHP_INT_MAX);
        add_action('wp_login_failed', [$r, 'login_failed']);
        add_action('xmlrpc_call', [$r, 'xmlrpc_call']);
        if (!finishes()) {
            add_action('shutdown', [$r->queue, 'schedule'], PHP_INT_MAX);
        }
        // Registered again from within: last, so what other plugins do after the answer never waits for Trakk.
        register_shutdown_function(static function () use ($r): void {
            register_shutdown_function([$r, 'shutdown']);
        });
    }

    /**
     * WordPress's own call of wp-cron.php, known by the lock it wrote; anybody else's is a request like any other.
     * Compared at load: wp-cron.php deletes the lock before shutdown.
     */
    private static function own_cron(): bool
    {
        // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- compared only
        $lock = $_GET['doing_wp_cron'] ?? '';
        return defined('DOING_CRON') && $lock !== '' && get_transient('doing_cron') === $lock;
    }

    public function resolved(mixed $id): mixed
    {
        $this->resolved = is_numeric($id) ? (int) $id : 0;
        return $id;
    }

    public function login_failed(): void
    {
        $this->err = 'login_failed'; // wp-login.php and XML-RPC answer it 200
    }

    public function xmlrpc_call(mixed $name): void
    {
        if ($this->op === '' && is_string($name)) {
            $this->op = $name;
        }
    }

    public function shutdown(): void
    {
        global $wpdb;
        try {
            ignore_user_abort(true);
            $event = build_event($this->server, $this->settings->hops, [
                'status' => (int) http_response_code(),
                'dur_ms' => (int) (1000 * (microtime(true) - request_time($this->server))),
                'account' => $this->account(),
                'op' => $this->operation(),
                'err' => $this->err,
            ]);
            if ($event === null || !$wpdb instanceof \wpdb) {
                return;
            }
            $later = $this->finish();
            $wpdb->suppress_errors(true);
            $this->queue->add($event);
            if ($later) {
                $this->queue->send($this->settings);
                if ($this->queue->paused_until() > time()) {
                    $this->queue->schedule(); // on a quiet site no request may come to try again
                }
            }
        } catch (\Throwable) {
            return; // the request is answered or on its way: nothing here may show
        }
    }

    /**
     * Ends the response where the SAPI can, the session first: its lock would hold the visitor's next request.
     */
    private function finish(): bool
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
            return true;
        }
        if (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
            return true;
        }
        return false;
    }

    /**
     * The user a good cookie, application password or token resolved to, if the request still acts as them. Not one a
     * plugin merely plays with wp_set_current_user(). XML-RPC signs in by its own password check.
     */
    private function account(): string
    {
        $user = $GLOBALS['current_user'] ?? null;
        $id = is_object($user) ? (int) ($user->ID ?? 0) : 0;
        if ($id > 0 && ($id === $this->resolved || defined('XMLRPC_REQUEST') && XMLRPC_REQUEST)) {
            return 'u_' . $id;
        }
        return '';
    }

    /**
     * An AJAX action somebody registered: the name is the site's then, not the client's.
     */
    private function operation(): string
    {
        if (!wp_doing_ajax()) {
            return $this->op;
        }
        // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- looked up only
        $action = $_REQUEST['action'] ?? null;
        return is_string($action) && (has_action("wp_ajax_$action") || has_action("wp_ajax_nopriv_$action"))
            ? $action : '';
    }
}

/**
 * The request as it came in. Called first at load: WordPress adds slashes to $_SERVER once the plugins are loaded
 * (Sec-CH-UA is full of quotes), and plugins rewrite REQUEST_URI and REMOTE_ADDR.
 *
 * @return array<string, mixed>
 */
function request_server(): array
{
    static $server = null;
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- reported as sent, never put out
    return $server ??= array_intersect_key($_SERVER, array_flip(SERVER_KEYS));
}

/**
 * Whether a request can go on after its answer is out.
 */
function finishes(): bool
{
    return function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request');
}

/**
 * WP-Cron's turn.
 */
function cron_send(): void
{
    $settings = Settings::load();
    if (!$settings->feed()) {
        return;
    }
    $queue = new Queue();
    $queue->send($settings);
    if ($queue->length() > 0) {
        $queue->schedule();
    }
}
