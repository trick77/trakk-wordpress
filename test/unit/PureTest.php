<?php

namespace Trakk\Test;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

use function Trakk\batch;

use const Trakk\BATCH_EVENTS;

use function Trakk\build_event;
use function Trakk\client_addr;
use function Trakk\encode_event;
use function Trakk\is_local;
use function Trakk\is_loopback;
use function Trakk\is_private;

use const Trakk\LIMITS;

use function Trakk\origin_of;
use function Trakk\parse_addr;
use function Trakk\path_of;
use function Trakk\release_from;

use Trakk\Settings;

use function Trakk\verdict;

final class PureTest extends TestCase
{
    private const REQUEST = [
        'REMOTE_ADDR' => '203.0.113.7',
        'REQUEST_METHOD' => 'POST',
        'REQUEST_URI' => '/wp-login.php?action=rp&key=secret',
        'REQUEST_TIME_FLOAT' => 1764000000.1234,
        'HTTP_USER_AGENT' => 'Mozilla/5.0',
        'HTTP_REFERER' => 'https://example.org/x?token=1#f',
        'HTTP_SEC_CH_UA' => '"Chromium";v="142"',
    ];

    #[TestWith(['198.51.100.9, 203.0.113.7', 0, '10.0.0.1'])] // no hops is the peer
    #[TestWith(['198.51.100.9, 203.0.113.7', 1, '203.0.113.7'])] // one hop is the last entry
    #[TestWith(['198.51.100.9, 203.0.113.7', 2, '198.51.100.9'])] // two hops is the one before
    #[TestWith(['198.51.100.9, 203.0.113.7', 3, '10.0.0.1'])] // more hops than entries is the peer
    #[TestWith(['unknown', 1, '10.0.0.1'])] // an entry that is no address is the peer
    #[TestWith([' ', 1, '10.0.0.1'])] // an empty header is the peer
    #[TestWith([null, 1, '10.0.0.1'])] // no header is the peer
    public function testClientAddr(?string $xff, int $hops, string $want): void
    {
        $server = ['REMOTE_ADDR' => '10.0.0.1'] + ($xff === null ? [] : ['HTTP_X_FORWARDED_FOR' => $xff]);
        self::assertSame($want, client_addr($server, $hops));
    }

    public function testNoPeerIsNoAddress(): void
    {
        self::assertSame('', client_addr([], 0));
    }

    #[TestWith(['203.0.113.7:4711', '203.0.113.7'])]
    #[TestWith(['[2001:db8::1]:443', '2001:db8::1'])]
    #[TestWith(['fe80::1%eth0', 'fe80::1'])]
    #[TestWith([' 2001:db8::1 ', '2001:db8::1'])]
    #[TestWith(['localhost', ''])]
    public function testParseAddr(string $in, string $want): void
    {
        self::assertSame($want, parse_addr($in));
    }

    #[TestWith(['127.0.0.1', true])]
    #[TestWith(['127.9.9.9', true])]
    #[TestWith(['::1', true])]
    #[TestWith(['::ffff:127.0.0.1', true])]
    #[TestWith(['10.0.0.1', false])]
    #[TestWith(['::2', false])]
    #[TestWith(['', false])]
    public function testIsLoopback(string $addr, bool $want): void
    {
        self::assertSame($want, is_loopback($addr));
    }

    #[TestWith(['127.0.0.1', null, true])]
    #[TestWith(['127.0.0.1', '', true])]
    #[TestWith(['::1', '127.0.0.1, ::1', true])]
    #[TestWith(['127.0.0.1', '203.0.113.7', false])] // a proxy on the same host
    #[TestWith(['127.0.0.1', '127.0.0.1, 203.0.113.7', false])] // and a client that forged an entry
    #[TestWith(['127.0.0.1', 'unknown', false])]
    #[TestWith(['203.0.113.7', '127.0.0.1', false])] // around the proxy
    public function testIsLocal(string $peer, ?string $xff, bool $want): void
    {
        $server = ['REMOTE_ADDR' => $peer] + ($xff === null ? [] : ['HTTP_X_FORWARDED_FOR' => $xff]);
        self::assertSame($want, is_local($server));
    }

    #[TestWith(['10.0.0.1', true])]
    #[TestWith(['192.168.1.1', true])]
    #[TestWith(['fd00::1', true])]
    #[TestWith(['8.8.8.8', false])]
    #[TestWith(['2606:4700::1', false])]
    public function testIsPrivate(string $addr, bool $want): void
    {
        self::assertSame($want, is_private($addr));
    }

    #[TestWith(['/a/b?x=1', '/a/b'])]
    #[TestWith(['/a#f', '/a'])]
    #[TestWith(['', '/'])]
    #[TestWith(['?x', '/'])]
    #[TestWith(['http://h.example/p?q', '/p'])]
    #[TestWith(['https://h.example', '/'])]
    #[TestWith(['*', '/*'])]
    #[TestWith(['/%FF', '/%FF'])]
    #[TestWith(['//x', '//x'])]
    public function testPathOf(string $target, string $want): void
    {
        self::assertSame($want, path_of($target));
    }

    public function testEvent(): void
    {
        $extra = ['status' => 200, 'dur_ms' => 12, 'account' => '', 'op' => '', 'err' => 'login_failed'];
        self::assertSame([
            't' => 1764000000123,
            'ip' => '203.0.113.7',
            'status' => 200,
            'dur_ms' => 12,
            'method' => 'POST',
            'path' => '/wp-login.php',
            'ref' => 'https://example.org/x',
            'ua' => 'Mozilla/5.0',
            'ch_ua' => '"Chromium";v="142"',
            'err' => 'login_failed',
        ], build_event(self::REQUEST, 0, $extra));
    }

    public function testEventNeedsAnAddress(): void
    {
        self::assertNull(build_event([], 0, []));
    }

    public function testEventBounds(): void
    {
        $e = build_event([
            'HTTP_USER_AGENT' => str_repeat('x', 5000),
            'REQUEST_URI' => '/' . str_repeat('y', 9000),
            'HTTP_REFERER' => ['an array'],
        ] + self::REQUEST, 0, ['status' => 0, 'dur_ms' => -5]);
        self::assertNotNull($e);
        self::assertSame(512, strlen((string) $e['ua']));
        self::assertSame(2048, strlen((string) $e['path']));
        self::assertArrayNotHasKey('ref', $e);
        self::assertSame(0, $e['status']);
        self::assertSame(0, $e['dur_ms']);
    }

    /**
     * @return iterable<array{string}>
     */
    public static function anyBytes(): iterable
    {
        yield ["caf\u{e9}"];
        yield ["bad \xff\xfe"];
        yield [substr("\u{20ac}", 0, 2)];
        yield ["quote\" slash\\ nul\0"];
        yield ['%s %d %'];
    }

    #[DataProvider('anyBytes')]
    public function testEncodesToAscii(string $ua): void
    {
        $json = encode_event(['ua' => $ua]);
        self::assertMatchesRegularExpression('/^[\x20-\x7e]+$/', $json);
        self::assertJson($json);
    }

    public function testTheLargestEventFitsATextColumn(): void
    {
        $worst = array_map(static fn(int $n): string => str_repeat("\x01", $n), LIMITS);
        self::assertLessThan(65535, strlen(encode_event($worst)));
    }

    public function testBatch(): void
    {
        self::assertSame([[], '{"v":1,"events":[]}'], batch([]));
        self::assertSame(
            [[1, 2], '{"v":1,"events":[{"a":1},{"a":2}]}'],
            batch([self::row(1, '{"a":1}'), self::row(2, '{"a":2}')]),
        );
    }

    public function testARowThatIsNoJsonIsTakenAlongAndLeftOut(): void
    {
        self::assertSame(
            [[1, 2], '{"v":1,"events":[{"a":2}]}'],
            batch([self::row(1, '{"a":'), self::row(2, '{"a":2}')]),
        );
    }

    public function testBatchBounds(): void
    {
        $many = array_map(static fn(int $id): array => self::row($id, '{"a":1}'), range(1, 600));
        self::assertCount(BATCH_EVENTS, batch($many)[0]);

        $big = array_map(
            static fn(int $id): array => self::row($id, encode_event(['ua' => str_repeat('x', 30000)])),
            range(1, 20),
        );
        [$ids, $body] = batch($big);
        self::assertCount(8, $ids);
        self::assertLessThan(256 * 1024, strlen($body));

        $huge = [self::row(1, encode_event(['ua' => str_repeat('x', 300000)])), self::row(2, '{}')];
        self::assertSame([1], batch($huge)[0], 'at least one row');
    }

    #[TestWith([204, true, true, 0])]
    #[TestWith([400, false, true, 60])]
    #[TestWith([401, false, true, 60])]
    #[TestWith([413, false, true, 60])]
    #[TestWith([0, false, false, 5])]
    #[TestWith([302, false, false, 5])]
    #[TestWith([403, false, false, 5])]
    #[TestWith([404, false, false, 5])]
    #[TestWith([408, false, false, 5])]
    #[TestWith([429, false, false, 5])]
    #[TestWith([503, false, false, 5])]
    public function testVerdict(int $status, bool $ok, bool $delete, int $pause): void
    {
        self::assertSame(['ok' => $ok, 'delete' => $delete, 'pause' => $pause], verdict($status, '', 0));
    }

    #[TestWith(['', 3, 40])] // doubles
    #[TestWith(['', 50, 900])] // to fifteen minutes
    #[TestWith([' 7 ', 5, 7])] // Retry-After
    #[TestWith(['0', 5, 1])] // at least a second
    #[TestWith(['999999', 0, 1800])] // at most half an hour
    #[TestWith(['Wed, 21 Oct 2026 07:28:00 GMT', 1, 10])] // a date is the back-off
    public function testPause(string $retryAfter, int $fails, int $want): void
    {
        self::assertSame($want, verdict(503, $retryAfter, $fails)['pause']);
    }

    #[TestWith(['https://trakk.example.com', 'https://trakk.example.com'])]
    #[TestWith([' https://Trakk.example.com/ ', 'https://trakk.example.com'])]
    #[TestWith(['http://trakk.test:8080', 'http://trakk.test:8080'])]
    #[TestWith(['http://[::1]:8080', 'http://[::1]:8080'])]
    #[TestWith(['trakk.example.com', null])]
    #[TestWith(['https://trakk.example.com/api', null])]
    #[TestWith(['javascript:alert(1)', null])]
    #[TestWith(['https://u:p@trakk.example.com', null])]
    #[TestWith(['https://trakk.example.com?x', null])]
    #[TestWith(['https://a"b.example', null])]
    #[TestWith(['', null])]
    public function testOriginOf(string $in, ?string $want): void
    {
        self::assertSame($want, origin_of($in));
    }

    #[TestWith([false])]
    #[TestWith([null])]
    #[TestWith(['x'])]
    #[TestWith([[]])]
    #[TestWith([['url' => 1, 'key' => [], 'hops' => []]])]
    public function testSettingsFromAnything(mixed $option): void
    {
        self::assertSame(['url' => '', 'key' => '', 'hops' => 0], Settings::from($option)->option());
    }

    public function testTheReleasesOwnDescription(): void
    {
        $release = release_from((string) shell_exec(dirname(__DIR__, 2) . '/release/update-json.sh'));
        self::assertNotNull($release);
        $header = (string) file_get_contents(dirname(__DIR__, 2) . '/trakk/trakk.php');
        self::assertStringContainsString("Requires PHP:      {$release['requires_php']}\n", $header);
        self::assertStringContainsString("Version:           {$release['version']}\n", $header);
    }

    #[TestWith(['{"version":"1.2.3","requires":"6.5","requires_php":"8.1","tested":"7.1"}', true])]
    #[TestWith(['{"version":"1.2.3"}', false])]
    #[TestWith(['{"version":"../x","requires":"6.5","requires_php":"8.1","tested":"7.1"}', false])]
    #[TestWith(['{"version":"1.2.3\\n","requires":"6.5","requires_php":"8.1","tested":"7.1"}', false])]
    #[TestWith(['{"version":1.2,"requires":"6.5","requires_php":"8.1","tested":"7.1"}', false])]
    #[TestWith(['<html>', false])]
    #[TestWith(['', false])]
    public function testReleaseFrom(string $json, bool $taken): void
    {
        self::assertSame($taken, release_from($json) !== null);
    }

    public function testHopsAreBounded(): void
    {
        self::assertSame(10, Settings::from(['hops' => 99])->hops);
        self::assertSame(0, Settings::from(['hops' => -1])->hops);
    }

    public function testTheFeedNeedsUrlAndKey(): void
    {
        self::assertTrue(Settings::from(['url' => 'u', 'key' => 'k'])->feed());
        self::assertFalse(Settings::from(['url' => 'u'])->feed());
        self::assertFalse(Settings::from(['key' => 'k'])->feed());
    }

    public function testTheSiteOf020IsDropped(): void
    {
        $old = ['url' => 'u', 'site' => 's', 'key' => 'k', 'hops' => 1];
        self::assertSame(['url' => 'u', 'key' => 'k', 'hops' => 1], Settings::from($old)->option());
    }

    /**
     * @return array{id: string, body: string}
     */
    private static function row(int $id, string $body): array
    {
        return ['id' => (string) $id, 'body' => $body];
    }
}
