<?php
/**
 * Settings → Trakk. The only place a setting comes from.
 */

namespace Trakk;

defined('ABSPATH') || exit;

const PAGE = 'trakk';
const TEST_ACTION = 'traffic_observability_test';

function settings_menu(): void
{
    $page = add_options_page('Trakk', 'Trakk', 'manage_options', PAGE, __NAMESPACE__ . '\settings_page');
    add_action("load-$page", static function (): void {
        wp_enqueue_script(
            'trakk-settings',
            plugins_url('settings.js', dirname(__DIR__) . '/trakk.php'),
            [],
            VERSION,
            ['in_footer' => true]
        );
    });
}

function settings_register(): void
{
    register_setting(PAGE, OPTION, [
        'type' => 'array',
        'sanitize_callback' => __NAMESPACE__ . '\settings_sanitize',
        'show_in_rest' => false,
    ]);
}

/**
 * @param array<string> $links
 * @return array<string>
 */
function settings_link(array $links): array
{
    $url = admin_url('options-general.php?page=' . PAGE);
    return ['<a href="' . esc_url($url) . '">Settings</a>', ...$links];
}

/**
 * The form's values over the old settings: [settings, faults by field]. What is wrong stays as it was. The feed key is
 * never sent back to the browser: empty keeps it.
 *
 * @return array{Settings, array<string, string>}
 */
function settings_read(mixed $form, Settings $old): array
{
    $form = is_array($form) ? $form : [];
    $text = static fn(string $k): string => is_scalar($form[$k] ?? null) ? trim((string) $form[$k]) : '';
    $faults = [];

    $url = $text('url') === '' ? '' : origin_of($text('url'));
    if ($url === null) {
        $faults['url'] = 'The Trakk URL is an origin, such as https://trakk.example.com, without a path.';
    }
    $site = $text('site') === '' ? '' : domain_of($text('site'));
    if ($site === null) {
        $faults['site'] = 'The site is a hostname, such as www.example.com.';
    }
    $key = $text('key');
    if ($key === '') {
        $key = $text('key_clear') === '' ? $old->key : '';
    } elseif (!preg_match('/^[\x21-\x7e]{16,256}$/', $key)) {
        $key = $old->key;
        $faults['key'] = 'The feed key is 16 to 256 characters without spaces.';
    }
    $hops = filter_var(
        $text('hops'),
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 0, 'max_range' => Settings::HOPS_MAX]]
    );
    if ($hops === false) {
        $faults['hops'] = 'Trusted proxies is a number from 0 to ' . Settings::HOPS_MAX . '.';
    }
    return [
        new Settings($url ?? $old->url, $site ?? $old->site, $key, $hops === false ? $old->hops : $hops),
        $faults,
    ];
}

/**
 * @return array{url: string, site: string, key: string, hops: int}
 */
function settings_sanitize(mixed $form): array
{
    [$new, $faults] = settings_read($form, Settings::load());
    foreach ($faults as $field => $message) {
        add_settings_error(OPTION, $field, $message);
    }
    if ($new->feed()) {
        (new Queue())->install();
    } else {
        (new Queue())->close();
    }
    return $new->option();
}

/**
 * Why Trakk does not take the feed, null when it does. An empty batch: Trakk answers for the key and the site without
 * storing anything.
 */
function feed_fault(Settings $s): ?string
{
    [$status, , $error] = post($s, batch($s->site, [])[1]);
    if ($status >= 200 && $status < 300) {
        return null;
    }
    return [
        400 => 'The feed key belongs to another site than ' . $s->site . '.',
        401 => 'Trakk does not know this feed key.',
    ][$status] ?? ($status === 0 ? "No answer ($error)." : "$error.");
}

/**
 * Why the script's address serves none, null when it does.
 */
function script_fault(Settings $s): ?string
{
    $res = wp_remote_get($s->url . '/trakk.js', ['timeout' => TIMEOUT, 'redirection' => 0,
        'user-agent' => 'trakk-wordpress/' . VERSION, 'limit_response_size' => 4096]);
    if (is_wp_error($res)) {
        return 'No answer (' . substr($res->get_error_message(), 0, 200) . ').';
    }
    $status = (int) wp_remote_retrieve_response_code($res);
    if ($status !== 200) {
        return "HTTP $status.";
    }
    $type = wp_remote_retrieve_header($res, 'content-type');
    return is_string($type) && str_contains($type, 'javascript') ? null : 'The answer is no script.';
}

/**
 * @return list<array{ok: bool, text: string}>
 */
function test_lines(Settings $s): array
{
    if ($s->url === '') {
        return [['ok' => false, 'text' => 'Enter the Trakk URL first.']];
    }
    $line = static fn(string $what, ?string $fault, string $good): array
        => ['ok' => $fault === null, 'text' => $fault === null ? $good : "$what: $fault"];
    return [
        $line('Browser script', script_fault($s), 'Trakk serves the browser script.'),
        $s->feed() ? $line('Server feed', feed_fault($s), 'Trakk accepts the server feed.')
            : ['ok' => true, 'text' => 'Server feed: off, it needs the site and the key.'],
    ];
}

/**
 * "Test connection": what the form holds now, nothing saved.
 */
function settings_test(): void
{
    check_ajax_referer(TEST_ACTION, 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(null, 403);
    }
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- settings_read() takes what is valid only
    [$s, $faults] = settings_read(wp_unslash($_POST[OPTION] ?? []), Settings::load());
    $lines = array_map(static fn(string $fault): array => ['ok' => false, 'text' => $fault], array_values($faults));
    if (!$lines) {
        $lines = test_lines($s);
    }
    wp_send_json_success($lines);
}

/**
 * What the settings look wrong about, though each is valid by itself.
 *
 * @return list<string>
 */
function settings_warnings(Settings $s): array
{
    $out = [];
    $home = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
    if ($s->url !== '' && $s->site === '') {
        $out[] = "No site is set: the script reports $home, the host of the page, and Trakk must list it.";
    } elseif ($s->site !== '' && $s->site !== $home) {
        $out[] = "The site is {$s->site}, but the pages are on $home. With and without www are two sites in Trakk.";
    }
    if ($s->key !== '' && !$s->feed()) {
        $out[] = 'The server feed is off: it needs the URL, the site and the key.';
    }
    return $out;
}

function settings_page(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }
    $s = Settings::load();
    $addr = client_addr(array_map('sanitize_text_field', wp_unslash($_SERVER)), $s->hops);
    ?>
    <div class="wrap">
        <h1>Trakk</h1>
        <?php foreach (settings_warnings($s) as $w) : ?>
            <div class="notice notice-warning inline"><p><?php echo esc_html($w); ?></p></div>
        <?php endforeach; ?>
        <form id="trakk-settings" method="post" action="options.php">
            <?php settings_fields(PAGE); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="trakk-url">Trakk URL</label></th>
                    <td>
                        <input id="trakk-url" name="<?php echo esc_attr(OPTION); ?>[url]" type="url" class="regular-text"
                            value="<?php echo esc_attr($s->url); ?>" placeholder="https://trakk.example.com">
                        <p class="description">Turns on the browser script on this site's pages. Empty: nothing is
                            loaded and nothing is sent.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="trakk-site">Site</label></th>
                    <td>
                        <input id="trakk-site" name="<?php echo esc_attr(OPTION); ?>[site]" type="text" class="regular-text"
                            value="<?php echo esc_attr($s->site); ?>" placeholder="www.example.com">
                        <p class="description">The domain as Trakk's <code>sites.json</code> lists it.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="trakk-key">Feed key</label></th>
                    <td>
                        <input id="trakk-key" name="<?php echo esc_attr(OPTION); ?>[key]" type="password" class="regular-text"
                            value="" autocomplete="new-password"
                            placeholder="<?php echo $s->key !== '' ? 'Set. Leave empty to keep it' : ''; ?>">
                        <?php if ($s->key !== '') : ?>
                            <p><label><input type="checkbox" name="<?php echo esc_attr(OPTION); ?>[key_clear]" value="1">
                                Remove the key</label></p>
                        <?php endif; ?>
                        <p class="description">With the URL and the site, also reports every request WordPress
                            answers to Trakk: address, path, status, duration, user agent, referrer, the id of a
                            signed-in user. The key is kept in this site's database.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="trakk-hops">Trusted proxies</label></th>
                    <td>
                        <input id="trakk-hops" name="<?php echo esc_attr(OPTION); ?>[hops]" type="number" min="0" max="10"
                            class="small-text" value="<?php echo esc_attr((string) $s->hops); ?>">
                        <p class="description">Proxies in front of this server that append the visitor's address to
                            <code>X-Forwarded-For</code>. 0 without a proxy, or where the web server already resolves
                            it. Too many lets a visitor write the address itself.</p>
                        <p class="description">Your address as it is read now:
                            <code><?php echo esc_html($addr === '' ? 'none' : $addr); ?></code><?php
                            if ($addr !== '' && is_private($addr)) : ?>. It is private: unless you are on the server's
                            own network, a proxy is not counted.<?php endif; ?></p>
                    </td>
                </tr>
            </table>
            <p class="submit">
                <?php submit_button('', 'primary', 'submit', false); ?>
                <button type="button" class="button" id="trakk-test"
                    data-action="<?php echo esc_attr(TEST_ACTION); ?>" data-nonce="<?php echo esc_attr(wp_create_nonce(TEST_ACTION)); ?>">Test connection</button>
            </p>
            <div id="trakk-test-result" role="status"></div>
        </form>
        <?php if ($s->feed()) {
            feed_status();
        } ?>
    </div>
    <?php
}

function feed_status(): void
{
    global $wpdb;
    $suppressed = $wpdb->suppress_errors(true);
    $queue = new Queue();
    $length = $queue->length();
    $st = $queue->state();
    $wpdb->suppress_errors($suppressed);
    $when = static fn(int $t): string => $t > 0 ? human_time_diff($t) . ' ago' : 'never';
    $rows = [
        'Waiting' => number_format_i18n($length) . ($length >= QUEUE_MAX ? ' (full: new requests are dropped)' : ''),
        'Last delivered' => $when($st['ok']),
        'Last failure' => $st['error_at'] > 0 ? $when($st['error_at']) . ': ' . $st['error'] : 'none',
        'Dropped' => number_format_i18n($st['dropped']) . ' (refused by Trakk, or not delivered within 55 minutes)',
    ];
    echo '<h2>Server feed</h2><table class="form-table" role="presentation">';
    foreach ($rows as $k => $v) {
        echo '<tr><th scope="row">' . esc_html($k) . '</th><td>' . esc_html($v) . '</td></tr>';
    }
    echo '</table>';
}
