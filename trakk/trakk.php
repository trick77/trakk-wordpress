<?php

/**
 * Plugin Name:       Trakk
 * Plugin URI:        https://github.com/trick77/trakk-wordpress
 * Description:       Reports this site's traffic to your own Trakk server: the browser script on every page and, with a feed key, every request WordPress answers.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            trick77
 * Author URI:        https://github.com/trick77
 * License:           MIT
 * License URI:       https://opensource.org/license/mit
 */

namespace Trakk;

defined('ABSPATH') || exit;

const VERSION = '0.1.0';

require __DIR__ . '/includes/pure.php';
require __DIR__ . '/includes/queue.php';
require __DIR__ . '/includes/record.php';
require __DIR__ . '/includes/script.php';

// A function of its own: plugin files run in WordPress's global scope.
(static function (): void {
    request_server();
    $settings = Settings::load();
    if ($settings->feed()) {
        Recorder::start($settings);
    }
})();

add_action(CRON_HOOK, __NAMESPACE__ . '\cron_send');
add_action('wp_enqueue_scripts', __NAMESPACE__ . '\enqueue_script');
add_filter('script_loader_tag', __NAMESPACE__ . '\script_tag', 10, 2);

if (is_admin()) {
    require __DIR__ . '/includes/settings.php';
    add_action('admin_menu', __NAMESPACE__ . '\settings_menu');
    add_action('admin_init', __NAMESPACE__ . '\settings_register');
    add_action('wp_ajax_' . TEST_ACTION, __NAMESPACE__ . '\settings_test');
    add_filter('plugin_action_links_' . plugin_basename(__FILE__), __NAMESPACE__ . '\settings_link');
}

register_activation_hook(__FILE__, static function (mixed $network): void {
    each_site((bool) $network, static function (): void {
        if (Settings::load()->feed()) {
            (new Queue())->install();
        }
    });
});
register_deactivation_hook(__FILE__, static function (mixed $network): void {
    each_site((bool) $network, static fn() => (new Queue())->close());
});
