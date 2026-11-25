<?php

/**
 * What the end-to-end test needs a site to do (a must-use plugin, test only).
 */

add_action('init', static function (): void {
    switch ($_GET['e2e'] ?? '') {
        case 'fatal':
            e2e_undefined_function();
            break;
        case 'impersonate':
            wp_set_current_user(1);
            break;
        case 'session':
            session_start();
            break;
        case 'status':
            status_header((int) $_GET['code']);
            echo 'status';
            exit;
        case 'app-password':
            $user = get_user_by('id', 1);
            [$password] = WP_Application_Passwords::create_new_application_password(1, ['name' => uniqid('e2e')]);
            echo $password;
            exit;
        case 'send':
            do_action('traffic_observability_send');
            echo 'sent';
            exit;
        case 'scheduled':
            echo (int) wp_next_scheduled('traffic_observability_send') - time();
            exit;
        case 'spawn':
            wp_schedule_single_event(time() - 1, 'e2e_nothing');
            spawn_cron();
            echo 'spawned';
            exit;
        case 'length':
            global $wpdb;
            echo $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}traffic_observability_queue");
            exit;
        case 'update':
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
            require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
            delete_site_transient('update_plugins');
            wp_update_plugins();
            echo json_encode([
                get_site_transient('update_plugins')->response['trakk/trakk.php'] ?? null,
                plugins_api('plugin_information', (object) ['slug' => 'trakk']),
                get_site_transient('update_plugins')->no_update['trakk/trakk.php'] ?? null,
                $GLOBALS['e2e_agent'],
            ]);
            exit;
        case 'drop':
            global $wpdb;
            $wpdb->query("DROP TABLE {$wpdb->prefix}traffic_observability_queue");
            echo 'dropped';
            exit;
        case 'lease':
            global $wpdb;
            $name = 'traffic_observability_lease';
            echo $wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name = '$name'");
            exit;
        case 'fill':
            global $wpdb;
            $values = implode(',', array_fill(0, 5000, '(' . time() . ", '{}')"));
            $wpdb->query("INSERT INTO {$wpdb->prefix}traffic_observability_queue (at, body) VALUES $values");
            echo 'filled';
            exit;
        case 'empty':
            global $wpdb;
            $wpdb->query("DELETE FROM {$wpdb->prefix}traffic_observability_queue");
            echo 'emptied';
            exit;
        case 'age':
            global $wpdb;
            $wpdb->query("UPDATE {$wpdb->prefix}traffic_observability_queue SET at = at - 3400");
            echo 'aged';
            exit;
        case 'next':
            global $wpdb;
            $wpdb->query("UPDATE {$wpdb->options} SET option_value = '0' WHERE option_name IN
                ('traffic_observability_lease', 'traffic_observability_pause')");
            echo 'released';
            exit;
    }
});

// wordpress.org without an answer of its own, and a release that is newer.
add_filter('pre_http_request', static function ($pre, $args, $url) {
    $answer = static fn(string $body): array => ['headers' => [], 'body' => $body, 'cookies' => [], 'filename' => null,
        'response' => ['code' => 200, 'message' => 'OK']];
    if (str_contains($url, 'api.wordpress.org/plugins/update-check')) {
        return $answer('{"plugins":[],"translations":[],"no_update":[]}');
    }
    if (str_ends_with($url, '/releases/latest/download/update.json')) {
        $GLOBALS['e2e_agent'] = $args['user-agent'];
        $down = $_GET['down'] ?? '';
        if ($down === 'pulled') {
            return ['response' => ['code' => 404, 'message' => 'Not Found']] + $answer('');
        }
        $requires = $_GET['requires'] ?? '6.5';
        return $down !== '' ? new WP_Error('http_request_failed', 'down') : $answer(
            '{"version":"99.0.0","requires":"' . $requires . '","requires_php":"8.1","tested":"7.1"}'
        );
    }
    return $pre;
}, 10, 3);

add_action('wp_ajax_nopriv_e2e_hello', static function (): void {
    wp_send_json(['hello' => 1]);
});
