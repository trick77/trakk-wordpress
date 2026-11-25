<?php

/**
 * The logic without WordPress's state: test/unit.php runs it alone.
 */

namespace Trakk;

defined('ABSPATH') || exit;

// Trakk refuses a batch over 500 events or 256 KiB; the envelope and its site name fit in the difference.
const BATCH_EVENTS = 500;
const BATCH_BYTES = 240 * 1024;
// Trakk dates an event more than an hour old on receipt, a spike at the wrong time: an older one is dropped instead.
// The five minutes are the sending's.
const MAX_AGE = 55 * 60;
// How long a batch waits after a refusal that a retry cannot fix, so a wrong key is no POST per request.
const REFUSED_PAUSE = 60;
const RETRY_FIRST = 5;
const RETRY_MAX = 15 * 60;
const RETRY_AFTER_MAX = 60 * 60;

// The feed's limits per field in bytes (Trakk's README, "Server feed"). op and err are names.
const LIMITS = [
    'method' => 16,
    'path' => 2048,
    'ref' => 2048,
    'ua' => 512,
    'lang' => 512,
    'ch_ua' => 512,
    'sf_site' => 32,
    'sf_mode' => 32,
    'sf_dest' => 32,
    'sig_agent' => 256,
    'account' => 128,
    'op' => 64,
    'err' => 64,
];

/**
 * The $_SERVER entries an event is built from.
 */
const SERVER_KEYS = [
    'REMOTE_ADDR',
    'HTTP_X_FORWARDED_FOR',
    'REQUEST_METHOD',
    'REQUEST_URI',
    'REQUEST_TIME_FLOAT',
    'HTTP_USER_AGENT',
    'HTTP_REFERER',
    'HTTP_ACCEPT_LANGUAGE',
    'HTTP_SEC_CH_UA',
    'HTTP_SEC_FETCH_SITE',
    'HTTP_SEC_FETCH_MODE',
    'HTTP_SEC_FETCH_DEST',
    'HTTP_SIGNATURE_AGENT',
];

/**
 * An address as text, with or without a port, brackets or a zone; '' when it is none.
 */
function parse_addr(string $s): string
{
    $s = trim($s);
    if (preg_match('/^\[([^\]]+)\](?::\d+)?$/', $s, $m)) {
        $s = $m[1];
    } elseif (preg_match('/^([0-9.]+):\d+$/', $s, $m)) {
        $s = $m[1];
    }
    $s = explode('%', $s, 2)[0];
    return filter_var($s, FILTER_VALIDATE_IP) === false ? '' : $s;
}

/**
 * The client's address: the X-Forwarded-For entry $hops places from the right, each trusted proxy appending the
 * address it saw; with 0 the connection's peer. Fewer entries than hops or an entry that is no address is the peer
 * too: whoever reaches the site around the proxy writes the header, and what it writes must never become an address
 * it chose. Trakk reads its own callers the same way (BACKEND_TRUSTED_HOPS).
 *
 * @param array<string, mixed> $server
 */
function client_addr(array $server, int $hops): string
{
    $text = static fn(string $k): string => is_string($server[$k] ?? null) ? $server[$k] : '';
    $xff = $text('HTTP_X_FORWARDED_FOR');
    if ($hops > 0 && trim($xff) !== '') {
        $parts = explode(',', $xff);
        $i = count($parts) - $hops;
        if ($i >= 0 && ($a = parse_addr($parts[$i])) !== '') {
            return $a;
        }
    }
    return parse_addr($text('REMOTE_ADDR'));
}

function is_loopback(string $addr): bool
{
    $bin = @inet_pton($addr);
    if ($bin === false) {
        return false;
    }
    // ::ffff:127.0.0.1 too.
    if (strlen($bin) === 16 && str_starts_with($bin, str_repeat("\0", 10) . "\xff\xff")) {
        $bin = substr($bin, 12);
    }
    return strlen($bin) === 4 ? ord($bin[0]) === 127 : $bin === str_repeat("\0", 15) . "\x01";
}

/**
 * A private, loopback or otherwise reserved address: as a visitor's, one a proxy stands in front of.
 */
function is_private(string $addr): bool
{
    return filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
}

/**
 * The path of a request line's target: query and fragment off (they can hold tokens), the absolute form's host too.
 */
function path_of(string $target): string
{
    $target = strip_query($target);
    if (preg_match('#^[a-z][a-z0-9+.-]*://[^/]*(.*)$#is', $target, $m)) {
        $target = $m[1];
    }
    return str_starts_with($target, '/') ? $target : '/' . $target;
}

function strip_query(string $s): string
{
    return substr($s, 0, strcspn($s, '?#'));
}

/**
 * When the request arrived, in seconds.
 *
 * @param array<string, mixed> $server
 */
function request_time(array $server): float
{
    $t = $server['REQUEST_TIME_FLOAT'] ?? null;
    return is_numeric($t) ? (float) $t : microtime(true);
}

/**
 * A feed event from the captured request. $extra: status, dur_ms, account, op, err. null without an address.
 *
 * @param array<string, mixed> $server
 * @param array{status?: int, dur_ms?: int, account?: string, op?: string, err?: string} $extra
 * @return array<string, int|string>|null
 */
function build_event(array $server, int $hops, array $extra): ?array
{
    $addr = client_addr($server, $hops);
    if ($addr === '') {
        return null;
    }
    $text = static fn(string $k): string => is_string($server[$k] ?? null) ? $server[$k] : '';
    $e = [
        't' => (int) round(1000 * request_time($server)),
        'ip' => $addr,
        'status' => $extra['status'] ?? 0,
        'dur_ms' => max(0, $extra['dur_ms'] ?? 0),
    ];
    $strings = [
        'method' => $text('REQUEST_METHOD'),
        'path' => path_of($text('REQUEST_URI')),
        'ref' => strip_query($text('HTTP_REFERER')),
        'ua' => $text('HTTP_USER_AGENT'),
        'lang' => $text('HTTP_ACCEPT_LANGUAGE'),
        'ch_ua' => $text('HTTP_SEC_CH_UA'),
        'sf_site' => $text('HTTP_SEC_FETCH_SITE'),
        'sf_mode' => $text('HTTP_SEC_FETCH_MODE'),
        'sf_dest' => $text('HTTP_SEC_FETCH_DEST'),
        'sig_agent' => $text('HTTP_SIGNATURE_AGENT'),
        'account' => $extra['account'] ?? '',
        'op' => $extra['op'] ?? '',
        'err' => $extra['err'] ?? '',
    ];
    foreach ($strings as $k => $v) {
        if ($v !== '') {
            $e[$k] = substr($v, 0, LIMITS[$k]);
        }
    }
    return $e;
}

/**
 * An event as it waits in the queue: ASCII JSON, so the database needs no look at the table's character set. A byte
 * that is no UTF-8 (a cut character too) becomes U+FFFD.
 *
 * @param array<string, int|string> $e
 */
function encode_event(array $e): string
{
    return (string) json_encode($e, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}

/**
 * The batch of the first rows that fit: [ids, body]. A row that is no JSON object would refuse the batch: its id is
 * taken along, its body left out.
 *
 * @param list<array{id: string, body: string}> $rows
 * @return array{list<int>, string}
 */
function batch(string $site, array $rows): array
{
    $ids = [];
    $bodies = [];
    $size = 0;
    foreach ($rows as $row) {
        $body = $row['body'];
        if ($bodies && ($size + strlen($body) + 1 > BATCH_BYTES || count($bodies) >= BATCH_EVENTS)) {
            break;
        }
        $ids[] = (int) $row['id'];
        if (is_array(json_decode($body, true))) {
            $bodies[] = $body;
            $size += strlen($body) + 1;
        }
    }
    return [$ids, '{"v":1,"site":' . json_encode($site) . ',"events":[' . implode(',', $bodies) . ']}'];
}

/**
 * What an answer means for its batch: delete it or keep it, and how long to wait. $status 0 is no answer. $fails are
 * the failures in a row before this one.
 *
 * @return array{ok: bool, delete: bool, pause: int}
 */
function verdict(int $status, string $retry_after, int $fails): array
{
    if ($status >= 200 && $status < 300) {
        return ['ok' => true, 'delete' => true, 'pause' => 0];
    }
    if ($status === 400 || $status === 401 || $status === 413) {
        return ['ok' => false, 'delete' => true, 'pause' => REFUSED_PAUSE];
    }
    $pause = min(RETRY_MAX, RETRY_FIRST * 2 ** min($fails, 10));
    if (preg_match('/^\d{1,9}$/', trim($retry_after))) {
        $pause = max(1, min(RETRY_AFTER_MAX, (int) $retry_after));
    }
    return ['ok' => false, 'delete' => false, 'pause' => $pause];
}

/**
 * Trakk's origin out of what was typed, null when it is none: http or https, a host, nothing after it.
 */
function origin_of(string $s): ?string
{
    $u = wp_parse_url(trim($s));
    if (!is_array($u) || empty($u['host']) || isset($u['user']) || isset($u['query']) || isset($u['fragment'])) {
        return null;
    }
    $scheme = strtolower($u['scheme'] ?? '');
    if (($scheme !== 'https' && $scheme !== 'http') || !in_array($u['path'] ?? '', ['', '/'], true)) {
        return null;
    }
    if (!preg_match('/^(\[[0-9a-f:.]+\]|[a-z0-9.-]+)$/i', $u['host'])) {
        return null;
    }
    return $scheme . '://' . strtolower($u['host']) . (isset($u['port']) ? ':' . $u['port'] : '');
}

/**
 * A hostname as Trakk's sites.json takes it, null when it is none.
 */
function domain_of(string $s): ?string
{
    $s = strtolower(trim($s));
    return preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $s) ? $s : null;
}

/**
 * The plugin's settings, whatever the stored option holds.
 */
final class Settings
{
    public const HOPS_MAX = 10;

    public function __construct(
        public readonly string $url = '',
        public readonly string $site = '',
        public readonly string $key = '',
        public readonly int $hops = 0,
    ) {}

    public static function from(mixed $option): self
    {
        $o = is_array($option) ? $option : [];
        $text = static fn(string $k): string => is_string($o[$k] ?? null) ? $o[$k] : '';
        $hops = $o['hops'] ?? 0;
        return new self(
            $text('url'),
            $text('site'),
            $text('key'),
            max(0, min(self::HOPS_MAX, is_numeric($hops) ? (int) $hops : 0)),
        );
    }

    public static function load(): self
    {
        return self::from(get_option(OPTION));
    }

    /**
     * The server feed needs all three.
     */
    public function feed(): bool
    {
        return $this->url !== '' && $this->site !== '' && $this->key !== '';
    }

    /**
     * @return array{url: string, site: string, key: string, hops: int}
     */
    public function option(): array
    {
        return ['url' => $this->url, 'site' => $this->site, 'key' => $this->key, 'hops' => $this->hops];
    }
}
