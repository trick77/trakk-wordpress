<?php

/**
 * The queue between a request and Trakk, and its sender. PHP has no background process: a row waits here until a
 * request that is already answered, or WP-Cron, sends it.
 *
 * Runs after WordPress closed the object cache: everything here is SQL of its own, never get_option.
 */

namespace Trakk;

defined('ABSPATH') || exit;

// Stored names never carry the product's name.
const OPTION = 'traffic_observability';
const OPTION_LEASE = 'traffic_observability_lease';
const OPTION_PAUSE = 'traffic_observability_pause';
const OPTION_STATE = 'traffic_observability_state';
const TABLE = 'traffic_observability_queue';
const CRON_HOOK = 'traffic_observability_send';

const QUEUE_MAX = 5000;
const LEASE = 60;
const TIMEOUT = 10;
const MAX_BATCHES = 10;
const CRON_DELAY = 60;
const CRON_NET = 5 * 60;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- a table of its own, and rows no cache may hold
final class Queue
{
    // The feed was turned off in this request: it must not add itself.
    private static bool $closed = false;
    // The settings were saved in this request: it loaded the old ones, the next one sends.
    private static bool $hold = false;
    private string $table;
    private string $options;

    public function __construct()
    {
        global $wpdb;
        // Taken at load: a plugin that left switch_to_blog() open at the end must not move them.
        $this->table = $wpdb->prefix . TABLE;
        $this->options = $wpdb->options;
    }

    /**
     * Whether it is there afterwards: the database user may not be allowed to create a table.
     */
    public function install(): bool
    {
        global $wpdb;
        self::$hold = true;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE {$this->table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  at bigint(20) unsigned NOT NULL,
  body text NOT NULL,
  PRIMARY KEY  (id),
  KEY at (at)
) {$wpdb->get_charset_collate()};");
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO %i (option_name, option_value, autoload)
            VALUES (%s, '0', 'no'), (%s, '0', 'no'), (%s, '{}', 'no')",
            $this->options,
            OPTION_LEASE,
            OPTION_PAUSE,
            OPTION_STATE
        ));
        // The pause after a failure may be the old settings' fault. Never the lease: a sender may hold it.
        $this->set(OPTION_PAUSE, '0');
        $this->note(['fails' => 0]);
        return $this->installed();
    }

    public function installed(): bool
    {
        global $wpdb;
        $table = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($this->table)));
        $rows = $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM %i WHERE option_name IN (%s, %s, %s)',
            $this->options,
            OPTION_LEASE,
            OPTION_PAUSE,
            OPTION_STATE
        ));
        return $table !== null && (int) $rows === 3;
    }

    public function uninstall(): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $this->table));
        $wpdb->query($wpdb->prepare(
            'DELETE FROM %i WHERE option_name IN (%s, %s, %s)',
            $this->options,
            OPTION_LEASE,
            OPTION_PAUSE,
            OPTION_STATE
        ));
    }

    /**
     * The feed is off: what waits holds addresses and goes.
     */
    public function close(): void
    {
        self::$closed = true;
        self::$hold = true;
        wp_clear_scheduled_hook(CRON_HOOK);
        $this->uninstall();
    }

    /**
     * Counted up to the bound only: cheap at any size, and exact where auto-increment skips ids (a Galera cluster).
     */
    public function length(): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM (SELECT id FROM %i LIMIT %d) q',
            $this->table,
            QUEUE_MAX
        ));
    }

    /**
     * @param array<string, int|string> $event
     */
    public function add(array $event): void
    {
        global $wpdb;
        if (self::$closed || $this->length() >= QUEUE_MAX) {
            return;
        }
        // Not $wpdb->insert(): it reads the table's columns once per request for the character set. ASCII needs none.
        $wpdb->query($wpdb->prepare(
            'INSERT INTO %i (at, body) VALUES (%d, %s)',
            $this->table,
            time(),
            encode_event($event)
        ));
    }

    /**
     * @return array{fails: int, ok: int, error: string, error_at: int, dropped: int}
     */
    public function state(): array
    {
        $s = json_decode($this->option(OPTION_STATE), true);
        $s = is_array($s) ? $s : [];
        return [
            'fails' => (int) ($s['fails'] ?? 0),
            'ok' => (int) ($s['ok'] ?? 0),
            'error' => (string) ($s['error'] ?? ''),
            'error_at' => (int) ($s['error_at'] ?? 0),
            'dropped' => (int) ($s['dropped'] ?? 0),
        ];
    }

    /**
     * Read again right before it is written: a sender that outlived its lease must not put back an old state.
     *
     * @param array{fails?: int, ok?: int, error?: string, error_at?: int} $change
     */
    private function note(array $change, int $dropped = 0): void
    {
        $state = array_merge($this->state(), $change);
        $state['dropped'] += $dropped;
        $this->set(OPTION_STATE, (string) json_encode($state, JSON_INVALID_UTF8_SUBSTITUTE));
    }

    /**
     * The time before which nobody sends, after a failure.
     */
    private function paused_until(): int
    {
        return (int) $this->option(OPTION_PAUSE);
    }

    /**
     * WP-Cron sends where no request can outlive its answer, and elsewhere what one left behind: a pause, a sender
     * that was killed. One event at a time.
     */
    public function schedule(): void
    {
        if (!self::$closed && !wp_next_scheduled(CRON_HOOK)) {
            $delay = finishes() ? CRON_NET : CRON_DELAY;
            wp_schedule_single_event(max(time() + $delay, $this->paused_until()), CRON_HOOK);
        }
    }

    /**
     * Sends what waits, one sender at a time. A row added while one sends goes with its next batch; one added as it
     * stops is looked for once more.
     */
    public function send(Settings $settings): void
    {
        for ($round = 0; $round < 2 && !self::$hold && $this->length() > 0; $round++) {
            if (!$this->drain($settings)) {
                return;
            }
        }
    }

    /**
     * True when it stopped for want of rows.
     */
    private function drain(Settings $settings): bool
    {
        $lease = $this->acquire();
        if ($lease === 0) {
            return false;
        }
        for ($i = 0; $i < MAX_BATCHES; $i++) {
            $expired = $this->expire();
            if ($expired > 0) {
                $this->note([], $expired);
            }
            $rows = $this->rows();
            if (!$rows) {
                $this->swap($lease, 0);
                return true;
            }
            [$ids, $body] = batch($rows);
            [$status, $retry_after, $error] = post($settings, $body);
            $fails = $this->state()['fails'];
            $v = verdict($status, $retry_after, $fails);
            if ($v['delete']) {
                $this->delete($ids);
            }
            if (!$v['ok']) {
                $this->note(
                    ['fails' => $fails + 1, 'error' => $error, 'error_at' => time()],
                    $v['delete'] ? count($ids) : 0
                );
                if ($v['delete']) {
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions -- a wrong key loses requests until somebody sees it
                    error_log('Trakk: batch of ' . count($ids) . " requests refused and dropped: $error");
                }
                $this->set(OPTION_PAUSE, (string) (time() + $v['pause']));
                $this->swap($lease, 0);
                return false;
            }
            $this->note(['fails' => 0, 'ok' => time()]);
            $next = max($lease + 1, time() + LEASE);
            if (!$this->swap($lease, $next)) {
                return false; // it took longer than its lease: another sender has the queue
            }
            $lease = $next;
        }
        $this->swap($lease, 0);
        return false;
    }

    /**
     * A lease is the value it wrote, so a sender that lost it cannot release another's. 0: not now.
     */
    private function acquire(): int
    {
        global $wpdb;
        $now = time();
        if ($this->paused_until() > $now) {
            return 0;
        }
        $lease = $now + LEASE;
        $got = $wpdb->query($wpdb->prepare(
            'UPDATE %i SET option_value = %s
            WHERE option_name = %s AND CAST(option_value AS UNSIGNED) <= %d',
            $this->options,
            (string) $lease,
            OPTION_LEASE,
            $now
        ));
        return $got === 1 ? $lease : 0;
    }

    private function swap(int $from, int $to): bool
    {
        global $wpdb;
        return $wpdb->query($wpdb->prepare(
            'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s',
            $this->options,
            (string) $to,
            OPTION_LEASE,
            (string) $from
        )) === 1;
    }

    private function set(string $name, string $value): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'UPDATE %i SET option_value = %s WHERE option_name = %s',
            $this->options,
            $value,
            $name
        ));
    }

    private function option(string $name): string
    {
        global $wpdb;
        return (string) $wpdb->get_var($wpdb->prepare(
            'SELECT option_value FROM %i WHERE option_name = %s',
            $this->options,
            $name
        ));
    }

    private function expire(): int
    {
        global $wpdb;
        return (int) $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE at < %d', $this->table, time() - MAX_AGE));
    }

    /**
     * @return list<array{id: string, body: string}>
     */
    private function rows(): array
    {
        global $wpdb;
        /** @var list<array{id: string, body: string}>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT id, body FROM %i ORDER BY id LIMIT %d',
            $this->table,
            BATCH_EVENTS,
        ), ARRAY_A);
        return $rows ?? [];
    }

    /**
     * By id, never "up to": a row with a smaller id can become visible after a larger one was sent.
     *
     * @param list<int> $ids
     */
    private function delete(array $ids): void
    {
        global $wpdb;
        $in = implode(', ', array_fill(0, count($ids), '%d'));
        // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders -- placeholders only
        $wpdb->query($wpdb->prepare("DELETE FROM %i WHERE id IN ($in)", $this->table, ...$ids));
    }
}
// phpcs:enable WordPress.DB.DirectDatabaseQuery

/**
 * [status, Retry-After, error]; status 0 without an answer.
 *
 * @return array{int, string, string}
 */
function post(Settings $settings, string $body): array
{
    $res = wp_remote_post($settings->url . '/api/feed', [
        'timeout' => TIMEOUT,
        'redirection' => 0,
        // WordPress's own names the site and its version to whoever is asked.
        'user-agent' => 'trakk-wordpress/' . VERSION,
        'headers' => [
            'Authorization' => 'Bearer ' . $settings->key,
            'Content-Type' => 'application/json',
        ],
        'body' => $body,
        'limit_response_size' => 4096,
    ]);
    if (is_wp_error($res)) {
        return [0, '', substr($res->get_error_message(), 0, 200)];
    }
    $status = (int) wp_remote_retrieve_response_code($res);
    $ra = wp_remote_retrieve_header($res, 'retry-after');
    $why = json_decode(wp_remote_retrieve_body($res), true);
    $why = is_array($why) && is_string($why['detail'] ?? null) ? ': ' . substr($why['detail'], 0, 150) : '';
    return [$status, is_string($ra) ? $ra : '', "HTTP $status$why"];
}

/**
 * On this site, or on every one of the network.
 */
function each_site(bool $network, callable $do): void
{
    if (!$network) {
        $do();
        return;
    }
    /** @var list<int> $ids */
    $ids = get_sites(['fields' => 'ids', 'number' => 0]);
    foreach ($ids as $id) {
        switch_to_blog($id);
        $do();
        restore_current_blog();
    }
}
