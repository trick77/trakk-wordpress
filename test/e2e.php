<?php

/**
 * The end-to-end test's driver, in a container on the test network (e2e.sh): php test/e2e.php apache|fpm
 */

const STUB = 'http://stub.test:8080';
const TRAKK = 'http://trakk.test:8080';
// The driver's own requests to the site, left out of what is asserted.
const CONTROL = 'e2e-control';
const BROWSER = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36';

$kind = $argv[1];
$host = "$kind.wp.test";
$base = "http://$host";
$key = "e2e-feed-key-$kind-0123456789";
$fpm = $kind === 'fpm';
$jar = tempnam('/tmp', 'jar');
$failed = 0;
$checks = 0;

function check(string $what, bool $ok, $got = null): void
{
    global $failed, $checks;
    $checks++;
    if (!$ok) {
        $failed++;
        echo "FAIL $what\n  got " . substr((string) json_encode($got, JSON_UNESCAPED_SLASHES), 0, 600) . "\n";
    }
}

function handle(string $url, array $o = [])
{
    $h = curl_init($url);
    curl_setopt_array($h, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_USERAGENT => $o['ua'] ?? CONTROL,
        CURLOPT_HTTPHEADER => $o['headers'] ?? [],
        CURLOPT_FOLLOWLOCATION => $o['follow'] ?? false,
    ]);
    if (isset($o['post'])) {
        curl_setopt($h, CURLOPT_POSTFIELDS, is_array($o['post']) ? http_build_query($o['post']) : $o['post']);
    }
    if (isset($o['jar'])) {
        curl_setopt_array($h, [CURLOPT_COOKIEJAR => $o['jar'], CURLOPT_COOKIEFILE => $o['jar']]);
    }
    if (isset($o['auth'])) {
        curl_setopt($h, CURLOPT_USERPWD, $o['auth']);
    }
    return $h;
}

function fetch(string $url, array $o = []): array
{
    $h = handle($url, $o);
    $body = curl_exec($h);
    $r = ['status' => curl_getinfo($h, CURLINFO_RESPONSE_CODE), 'body' => (string) $body,
        'time' => curl_getinfo($h, CURLINFO_TOTAL_TIME)];
    if ($body === false) {
        echo "no answer from $url: " . curl_error($h) . "\n";
    }
    return $r;
}

function control(string $do): string
{
    global $base;
    return fetch("$base/?e2e=$do")['body'];
}

function stub(string $path): array
{
    return json_decode(fetch(STUB . $path)['body'], true) ?? [];
}

function records(): array
{
    return stub('/records');
}

// What Trakk would have stored, the driver's own requests aside.
function events(): array
{
    $out = [];
    foreach (records() as $r) {
        if ($r['status'] === 204) {
            foreach ($r['body']['events'] as $e) {
                if (($e['ua'] ?? '') !== CONTROL) {
                    $out[] = $e;
                }
            }
        }
    }
    return $out;
}

// On mod_php WP-Cron sends: its hook is run by hand.
function flush_queue(): void
{
    global $fpm;
    if (!$fpm) {
        control('send');
    }
}

// The one event a request makes.
function event_of(string $path, array $o = []): array
{
    global $base;
    stub('/reset');
    $r = fetch($base . $path, $o + ['ua' => BROWSER]);
    flush_queue();
    $evs = [];
    for ($i = 0; $i < 50 && !$evs; $i++) {
        usleep(100000);
        $evs = events();
    }
    check("one event for $path", count($evs) === 1, $evs);
    return ($evs[0] ?? []) + ['_status' => $r['status'], '_body' => $r['body']];
}

function nonce(string $html): string
{
    preg_match('/name="_wpnonce" value="([^"]+)"/', $html, $m);
    return $m[1] ?? '';
}

function save(array $values): string
{
    global $base, $jar;
    $page = fetch("$base/wp-admin/options-general.php?page=trakk", ['jar' => $jar])['body'];
    $post = ['option_page' => 'trakk', 'action' => 'update', '_wpnonce' => nonce($page),
        '_wp_http_referer' => '/wp-admin/options-general.php?page=trakk'];
    foreach ($values as $k => $v) {
        $post["traffic_observability[$k]"] = $v;
    }
    return fetch("$base/wp-admin/options.php", ['jar' => $jar, 'post' => $post, 'follow' => true])['body'];
}

// Nothing set: nothing loaded.
$front = fetch("$base/")['body'];
check('the site answers', str_contains($front, '</html>'), substr($front, 0, 200));
check('no tag before the URL is set', !str_contains($front, 'trakk.js'));

// A newer release.
$update = static fn(string $q = ''): array => json_decode(control("update$q"), true) ?: [null, null, null, null];
[$offer, $details, , $agent] = $update();
$repo = 'https://github.com/trick77/trakk-wordpress';
check('an update is offered', ($offer['new_version'] ?? '') === '99.0.0' && $offer['requires_php'] === '8.1'
    && $offer['package'] === "$repo/releases/download/99.0.0/trakk.zip", $offer);
check('with its details', ($details['version'] ?? '') === '99.0.0' && $details['external'] === true
    && $details['download_link'] === $offer['package']
    && str_contains($details['sections']['changelog'], "$repo/releases/tag/99.0.0"), $details);
check('GitHub is not told the site', str_starts_with((string) $agent, 'trakk-wordpress/'), $agent);
[$offer] = $update('&down=1');
check('no answer keeps the offer', ($offer['new_version'] ?? '') === '99.0.0', $offer);
[$offer, $details, $none] = $update('&requires=99.0');
check('not for a WordPress too old, the details neither', $offer === null && $none !== null
    && $details['version'] === $none['new_version'] && $details['requires'] === '6.5', [$offer, $details, $none]);
[$offer, $details] = $update('&down=pulled');
check('a pulled release is no longer offered', $offer === null && !isset($details['version']), [$offer, $details]);

// Sign in.
fetch("$base/wp-login.php", ['jar' => $jar]);
fetch("$base/wp-login.php", ['jar' => $jar, 'post' => ['log' => 'admin', 'pwd' => 'e2e-password', 'testcookie' => '1']]);
$page = fetch("$base/wp-admin/options-general.php?page=trakk", ['jar' => $jar])['body'];
check(
    'the settings page',
    str_contains($page, 'Trakk URL') && str_contains($page, 'Trusted proxies'),
    substr(strip_tags($page), 0, 300)
);
check('it names the host Trakk must list', str_contains($page, "<code>$host</code>"), strip_tags($page));
check('a visitor does not see it', !str_contains(
    fetch("$base/wp-admin/options-general.php?page=trakk")['body'],
    'Trakk URL'
));

// Settings.
$ok = ['url' => STUB . '/', 'key' => $key, 'hops' => '0'];
$html = save(['url' => 'https://trakk.example.com/path'] + $ok);
check('a URL with a path is refused', str_contains($html, 'is an origin, such as'), strip_tags($html));
$html = save(['hops' => '99'] + $ok);
check('too many hops are refused', str_contains($html, 'a number from 0 to 10'), strip_tags($html));
$html = save($ok);
check('the URL is tidied', str_contains($html, 'value="' . STUB . '"'), strip_tags($html));
$html = save(['key' => ''] + $ok);
check('the key is never sent back', !str_contains($html, $key));
check('an empty key keeps it', str_contains($html, 'Set. Leave empty to keep it'));
check('the status shows', str_contains($html, 'Last delivered'));

// The test button: what the form holds, nothing saved. Its own requests are the driver's, the queue is emptied first.
stub('/reset');
control('empty');
usleep(500000);
stub('/reset');
$page = fetch("$base/wp-admin/options-general.php?page=trakk", ['jar' => $jar])['body'];
check('the button', str_contains($page, '>Test connection</button>'));
preg_match('/data-nonce="([^"]+)"/', $page, $m);
$button = static function (array $values, array $o = []) use ($base, $jar, $m): array {
    $post = ['action' => 'traffic_observability_test', 'nonce' => $m[1] ?? ''];
    foreach ($values as $k => $v) {
        $post["traffic_observability[$k]"] = $v;
    }
    return fetch("$base/wp-admin/admin-ajax.php", $o + ['jar' => $jar, 'post' => $post]);
};
stub('/reset');
$r = $button(['key' => ''] + $ok);
check('it tests script and feed', str_contains($r['body'], 'serves the browser script')
    && str_contains($r['body'], 'accepts the server feed') && !str_contains($r['body'], $key), $r);
$r = records();
check('it names the host once', count(array_filter($r, static fn($r) => ($r['body']['site'] ?? '') === $host)) === 1, $r);
$r = array_values(array_filter($r, static fn($r) => $r['body'] === ['v' => 1, 'events' => []]));
check('the test stores nothing', count($r) === 1 && $r[0]['auth'] === "Bearer $key"
    && $r[0]['type'] === 'application/json', $r);
check('no site in the user agent', $r && str_starts_with($r[0]['ua'], 'trakk-wordpress/'), $r);
stub('/control?status=401');
$r = $button(['key' => ''] + $ok);
check('a wrong key is named', str_contains($r['body'], 'Trakk does not know this feed key'), $r);
stub('/control?status=400');
$r = $button(['key' => ''] + $ok);
check('a Trakk too old is named', str_contains($r['body'], 'too old for the plugin')
    && str_contains($r['body'], 'the stub says so'), $r);
stub('/control?other=1');
$r = $button(['key' => ''] + $ok);
check('another site\'s key is named', str_contains($r['body'], "belongs to another site than $host"), $r);
stub('/reset');
$r = $button(['url' => 'http://stub:8080', 'key' => ''] + $ok);
check('the key goes to no other server', str_contains($r['body'], 'Enter the feed key again')
    && !array_filter(records(), static fn($r) => $r['body']['events'] === []), [$r, records()]);
$html = save(['url' => 'http://stub:8080', 'key' => ''] + $ok);
check('nor is that saved', str_contains($html, 'Enter the feed key again')
    && str_contains($html, 'value="' . STUB . '"'), strip_tags($html));
$html = save(['url' => 'http://stub:8080', 'key' => 'too short'] + $ok);
check('nor with a key that is refused', str_contains($html, '16 to 256 characters')
    && str_contains($html, 'value="' . STUB . '"'), strip_tags($html));
$r = $button(['url' => 'http://nothing.wp.test'] + $ok);
check('it tests what was typed', str_contains($r['body'], 'No answer'), $r);
$r = $button(['url' => 'http://nginx'] + $ok);
check('no script there', str_contains($r['body'], 'Browser script:'), $r);
$r = $button(['url' => 'not a url', 'key' => ''] + $ok);
check('it names a fault', str_contains($r['body'], 'is an origin'), $r);
$r = $button(['key' => '', 'key_clear' => '1'] + $ok);
check('without a key only the script', str_contains($r['body'], 'it needs the key'), $r);
$page = fetch("$base/wp-admin/options-general.php?page=trakk", ['jar' => $jar])['body'];
check('and saves nothing', str_contains($page, 'value="' . STUB . '"') && str_contains($page, 'Set. Leave empty'));
$r = $button($ok, ['jar' => "$jar.none"]);
check('not for a visitor', $r['status'] === 400 && !str_contains($r['body'], 'Trakk'), $r);
$r = fetch("$base/wp-admin/admin-ajax.php", ['jar' => $jar, 'post' => ['action' => 'traffic_observability_test']]);
check('not without the nonce', $r['status'] === 403, $r);

// The tag.
$front = fetch("$base/")['body'];
preg_match('#<script [^>]*trakk\.js[^>]*>#', $front, $m);
check('the tag', isset($m[0]) && str_contains($m[0], 'src="' . STUB . '/trakk.js"') && str_contains($m[0], ' defer')
    && str_contains($m[0], 'data-cfasync="false"'), $m);
check('the tag is in head', strpos($front, 'trakk.js') < strpos($front, '</head>'));
check('no tag on the sign-in page', !str_contains(fetch("$base/wp-login.php")['body'], 'trakk.js'));
check('no tag in wp-admin', !str_contains(fetch("$base/wp-admin/", ['jar' => $jar])['body'], 'trakk.js'));
control('empty');
control('next');

// Requests.
$hdr = ['Sec-CH-UA: "Chromium";v="142", "Not_A Brand";v="99"', 'Sec-Fetch-Site: cross-site',
    'Sec-Fetch-Mode: navigate', 'Sec-Fetch-Dest: document', 'Accept-Language: en-US,en;q=0.9',
    'Referer: https://example.org/x?token=1', 'Signature-Agent: "https://chatgpt.com"'];
$before = (int) (microtime(true) * 1000);
$e = event_of('/?p=1&secret=1', ['headers' => $hdr]);
check('the fields', ($e['method'] ?? '') === 'GET' && ($e['path'] ?? '') === '/' && ($e['status'] ?? 0) >= 200
    && ($e['ua'] ?? '') === BROWSER && ($e['lang'] ?? '') === 'en-US,en;q=0.9'
    && ($e['ref'] ?? '') === 'https://example.org/x' && ($e['sf_site'] ?? '') === 'cross-site'
    && ($e['sf_mode'] ?? '') === 'navigate' && ($e['sf_dest'] ?? '') === 'document', $e);
check('no slashes in Sec-CH-UA', ($e['ch_ua'] ?? '') === '"Chromium";v="142", "Not_A Brand";v="99"', $e);
check('Signature-Agent', ($e['sig_agent'] ?? '') === '"https://chatgpt.com"', $e);
check('the time', abs(($e['t'] ?? 0) - $before) < 3000 && $e['dur_ms'] >= 0 && $e['dur_ms'] < 10000, $e);
check('a visitor has no account', !isset($e['account']), $e);
if ($fpm) {
    stub('/reset');
    fetch("$base/nope.php?x", ['ua' => BROWSER]);
    sleep(1);
    check('nginx answers a missing .php file without PHP, as the README says', !events(), events());
} else {
    $e = event_of('/nope.php?x');
    check('a missing file is a 404', ($e['status'] ?? 0) === 404 && $e['path'] === '/nope.php', $e);
}
$e = event_of('/no-such-page');
check('a missing page is a 404', ($e['status'] ?? 0) === 404 && $e['path'] === '/no-such-page', $e);
$e = event_of('/wp-json/wp/v2/posts');
check('REST', ($e['status'] ?? 0) === 200 && $e['path'] === '/wp-json/wp/v2/posts', $e);
$e = event_of('/wp-admin/admin-ajax.php?action=e2e_hello');
check('a registered action is named', ($e['op'] ?? '') === 'e2e_hello', $e);
$e = event_of('/wp-admin/admin-ajax.php?action=not_registered');
check('another is not', !isset($e['op']) && ($e['status'] ?? 0) === 400, $e);
$e = event_of('/wp-login.php', ['post' => ['log' => 'admin', 'pwd' => 'wrong']]);
check('a failed login', ($e['status'] ?? 0) === 200 && ($e['err'] ?? '') === 'login_failed' && $e['method'] === 'POST'
    && !isset($e['account']) && !str_contains(json_encode($e), 'wrong'), $e);
$call = static fn(string $pw): string => '<?xml version="1.0"?><methodCall><methodName>wp.getUsersBlogs</methodName>'
    . "<params><param><value>admin</value></param><param><value>$pw</value></param></params></methodCall>";
$e = event_of('/xmlrpc.php', ['post' => $call('wrong'), 'headers' => ['Content-Type: text/xml']]);
check('a failed XML-RPC login', ($e['err'] ?? '') === 'login_failed' && ($e['status'] ?? 0) === 200, $e);
$e = event_of('/xmlrpc.php', ['post' => $call('e2e-password'), 'headers' => ['Content-Type: text/xml']]);
check('an XML-RPC call', ($e['op'] ?? '') === 'blogger.getUsersBlogs' && ($e['account'] ?? '') === 'u_1'
    && !isset($e['err']), $e);
$password = control('app-password');
$e = event_of('/wp-json/wp/v2/users/me', ['auth' => "admin:$password"]);
check('an application password', ($e['account'] ?? '') === 'u_1' && ($e['status'] ?? 0) === 200, $e);
$e = event_of('/wp-json/wp/v2/users/me', ['auth' => 'admin:wrong']);
check('a wrong application password', !isset($e['account']) && ($e['status'] ?? 0) === 401, $e);
$e = event_of('/wp-json/wp/v2/posts', ['jar' => $jar]);
check('a REST call without a nonce acts as nobody', !isset($e['account']), $e);
$e = event_of('/', ['jar' => $jar]);
check('a signed-in page', ($e['account'] ?? '') === 'u_1', $e);
$e = event_of('/?e2e=impersonate');
check('a played user is no account', !isset($e['account']) && ($e['status'] ?? 0) === 200, $e);
$e = event_of('/?e2e=fatal');
check('a fatal error', ($e['status'] ?? 0) === 500, $e);
$e = event_of('/?e2e=status&code=418');
check('a status', ($e['status'] ?? 0) === 418, $e);
$e = event_of('/wp-cron.php');
check('somebody else\'s wp-cron.php', ($e['path'] ?? '') === '/wp-cron.php', $e);
$e = event_of('/?x', ['ua' => "caf\xe9 \xe2\x82\xac 100% %s '\"\\"]);
check('a user agent of any bytes', str_contains($e['ua'] ?? '', "\u{20ac} 100% %s '\"\\"), $e);
$e = event_of('/', ['ua' => str_repeat('A', 4000)]);
check('a long user agent', strlen($e['ua'] ?? '') === 512, $e);

// WordPress's own cron call.
stub('/reset');
control('spawn');
sleep(2);
flush_queue();
sleep(1);
check('no own cron', !array_filter(events(), static fn($e) => $e['path'] === '/wp-cron.php'), events());

// Addresses. The apache image resolves X-Forwarded-For itself, trusting private proxies.
$xff = ['headers' => ['X-Forwarded-For: 198.51.100.9, 203.0.113.7']];
if ($fpm) {
    $e = event_of('/', $xff);
    check('no hops', !in_array($e['ip'] ?? '', ['198.51.100.9', '203.0.113.7', ''], true), $e);
    save(['key' => '', 'hops' => '1'] + $ok);
    control('empty');
    $e = event_of('/', $xff);
    check('one hop', ($e['ip'] ?? '') === '203.0.113.7', $e);
    $e = event_of('/', ['headers' => ['X-Forwarded-For: 2001:db8::7']]);
    check('IPv6', ($e['ip'] ?? '') === '2001:db8::7', $e);
    save(['key' => '', 'hops' => '2'] + $ok);
    control('empty');
    $e = event_of('/', $xff);
    check('two hops', ($e['ip'] ?? '') === '198.51.100.9', $e);
    save(['key' => ''] + $ok);
    control('empty');
} else {
    $e = event_of('/', $xff);
    check('mod_remoteip', ($e['ip'] ?? '') === '203.0.113.7', $e);
}

// Trakk fails.
$one = static function (string $path) use ($base): void {
    fetch($base . $path, ['ua' => BROWSER]);
    usleep(300000);
};
stub('/reset');
control('empty');
control('next');
stub('/control?status=503');
$one('/kept');
flush_queue();
check('503 keeps the batch', (int) control('length') > 0);
$due = (int) control('scheduled');
check('WP-Cron tries again if nobody comes', $due > 0 && $due <= 300, $due);
$n = count(records());
$one('/kept-too');
flush_queue();
check('and waits', count(records()) === $n, records());
stub('/control?status=204');
control('next');
flush_queue();
$one('/after');
control('next');
flush_queue();
sleep(1);
$paths = array_column(events(), 'path');
foreach (['/kept', '/kept-too', '/after'] as $p) {
    check("$p once", count(array_keys($paths, $p, true)) === 1, $paths);
}
stub('/reset');
stub('/control?status=429&retry=3600');
$one('/limited');
flush_queue();
check('429 keeps the batch', (int) control('length') > 0);
stub('/control?status=401');
control('next');
flush_queue();
usleep(500000);
stub('/reset');
control('next');
$one('/later');
flush_queue();
sleep(1);
$paths = array_column(events(), 'path');
check('401 drops the batch', !in_array('/limited', $paths, true) && in_array('/later', $paths, true), $paths);
$html = fetch("$base/wp-admin/options-general.php?page=trakk", ['jar' => $jar])['body'];
check('the failure shows', str_contains($html, 'HTTP 401: the stub says so'), strip_tags($html));

// New settings: what waits goes with them, never once more with the old ones.
stub('/reset');
stub('/control?status=503');
control('empty');
control('next');
$one('/moved');
flush_queue();
stub('/reset');
save(['key' => 'a-new-feed-key-0123456789'] + $ok);
flush_queue();
sleep(1);
$auth = array_unique(array_column(records(), 'auth'));
check('saved settings send at once, the old key not again', $auth === ['Bearer a-new-feed-key-0123456789']
    && in_array('/moved', array_column(events(), 'path'), true), [$auth, events()]);
save($ok);

// Something removed the table.
control('drop');
$html = fetch("$base/wp-admin/options-general.php?page=trakk", ['jar' => $jar])['body'];
check('a missing table is told', str_contains($html, 'no table to wait in'), strip_tags($html));
$html = save(['key' => ''] + $ok);
check('saving brings it back', !str_contains($html, 'no table to wait in') && control('length') !== '', strip_tags($html));

// Too old to send.
stub('/reset');
stub('/control?status=503');
control('empty');
control('next');
$one('/old');
flush_queue();
control('age');
stub('/control?status=204');
control('next');
$one('/young');
flush_queue();
sleep(1);
$paths = array_column(events(), 'path');
check('an old request is dropped', !in_array('/old', $paths, true) && in_array('/young', $paths, true), $paths);

// Trakk hangs past the timeout.
stub('/reset');
control('empty');
control('next');
stub('/control?sleep=14&status=204');
$slow = fetch("$base/hang", ['ua' => BROWSER]);
check('a hanging Trakk costs the visitor nothing', $slow['time'] < 3, $slow['time']);
$times = [];
for ($i = 0; $i < 5; $i++) {
    $times[] = fetch("$base/hang", ['ua' => BROWSER])['time'];
}
check('nor the next', max($times) < 3, $times);
if ($fpm) {
    $s = fetch("$base/?e2e=session", ['ua' => BROWSER, 'jar' => "$jar.s"]);
    $s = fetch("$base/?e2e=session", ['ua' => BROWSER, 'jar' => "$jar.s"]);
    check('a session is not held', $s['time'] < 3, $s['time']);
}
if ($fpm) {
    $lease = control('lease');
    save(['key' => ''] + $ok);
    check('saving leaves a sender its lease', (int) $lease > 0 && control('lease') === $lease, $lease);
}
sleep($fpm ? 12 : 0);
if (!$fpm) {
    control('send');
}
check('and nothing is lost', (int) control('length') >= 6, control('length'));
stub('/reset');
control('empty');
control('next');

// Full.
stub('/control?status=503');
control('fill');
$n = (int) control('length');
$one('/full');
check('a full queue takes no more', $n >= 5000 && (int) control('length') === $n, [$n, control('length')]);
stub('/reset');
control('empty');
control('next');

// Many at once, each exactly once.
$multi = curl_multi_init();
curl_multi_setopt($multi, CURLMOPT_MAX_TOTAL_CONNECTIONS, 20);
for ($i = 0; $i < 200; $i++) {
    curl_multi_add_handle($multi, handle("$base/many-$i", ['ua' => BROWSER]));
}
do {
    curl_multi_exec($multi, $running);
    curl_multi_select($multi, 1);
} while ($running);
for ($i = 0; $i < 20 && (int) control('length') > ($fpm ? 1 : 0); $i++) {
    flush_queue();
    usleep(500000);
    if (count(events()) >= 200) {
        break;
    }
}
$paths = array_count_values(array_filter(array_column(events(), 'path'), static fn($p) => str_contains($p, 'many')));
check('200 at once', count($paths) === 200, count($paths));
check('each once', $paths && max($paths) === 1, array_filter($paths, static fn($n) => $n > 1));
$sizes = array_map(static fn($r) => [count($r['body']['events']), $r['bytes']], records());
check(
    'in batches within the limits',
    max(array_column($sizes, 0)) <= 500 && max(array_column($sizes, 1)) < 262144,
    $sizes
);
echo "$kind: " . count($sizes) . " batches for 200 requests\n";

// WP-Cron for real, where nothing else sends.
if (!$fpm) {
    stub('/reset');
    control('empty');
    $one('/by-cron');
    $due = (int) control('scheduled');
    check('a cron event waits', $due > 0 && $due <= 60, $due);
    sleep(max(0, $due) + 2);
    fetch("$base/wp-cron.php");
    for ($i = 0; $i < 20 && !in_array('/by-cron', array_column(events(), 'path'), true); $i++) {
        usleep(500000);
    }
    check('WP-Cron sends', in_array('/by-cron', array_column(events(), 'path'), true), events());
}

// Trakk itself.
save(['url' => TRAKK] + $ok);
$r = $button(['url' => TRAKK, 'key' => ''] + $ok);
check('Trakk serves the script and accepts the feed', str_contains($r['body'], 'serves the browser script')
    && str_contains($r['body'], 'accepts the server feed'), $r);
control('empty');
control('next');
fetch("$base/to-trakk", ['ua' => BROWSER, 'headers' => $hdr]);
fetch("$base/wp-login.php", ['ua' => BROWSER, 'post' => ['log' => 'admin', 'pwd' => 'wrong']]);
flush_queue();
$log = '';
for ($i = 0; $i < 30 && !str_contains($log, 'login_failed'); $i++) {
    sleep(1);
    $log = fetch(TRAKK . "/api/dash/log?site=$host&range=24h&aud=all")['body'];
}
check('Trakk stored the page', str_contains($log, '/to-trakk'), substr($log, 0, 500));
check('Trakk stored the failed login', str_contains($log, 'login_failed'), substr($log, 0, 500));
$r = $button(['url' => TRAKK, 'key' => 'another-key-0123456789'] + $ok);
check('Trakk refuses another key', str_contains($r['body'], 'Trakk does not know this feed key'), $r);

// Deactivated in the browser, under which the request's own shutdown still runs.
$link = static function (string $action) use ($base, $jar): string {
    $html = fetch("$base/wp-admin/plugins.php", ['jar' => $jar])['body'];
    preg_match('#href="(plugins\.php\?action=' . $action . '&amp;plugin=trakk[^"]*)"#', $html, $m);
    return html_entity_decode($m[1] ?? 'plugins.php');
};
fetch("$base/wp-admin/" . $link('deactivate'), ['jar' => $jar]);
check('deactivated: no table', control('length') === '', control('length'));
check('deactivated: no cron event', (int) control('scheduled') < 0, control('scheduled'));
fetch("$base/wp-admin/" . $link('activate'), ['jar' => $jar]);
check('activated: the table is back', control('length') !== '', control('length'));

// Off again.
$html = save(['url' => '', 'key' => '', 'key_clear' => '1'] + $ok);
check('the key is removed', !str_contains($html, 'Leave empty to keep it') && !str_contains($html, 'Last delivered'));
check('no tag without the URL', !str_contains(fetch("$base/")['body'], 'trakk.js'));
check('nothing waits', control('length') === '', control('length'));

echo "$kind: $checks checks, $failed failed\n";
exit($failed ? 1 : 0);
