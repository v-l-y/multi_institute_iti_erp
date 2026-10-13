<?php
declare(strict_types=1);

// Optional/manual regression: php tests/proxy-security.php
require dirname(__DIR__) . '/bootstrap/autoload.php';

use App\Http\Request;

$originalServer = $_SERVER;
$originalTrusted = getenv('TRUSTED_PROXY_CIDRS');
$checks = 0;
$expect = static function (?string $actual, ?string $expected) use (&$checks): void {
    $checks++;
    if ($actual !== $expected) {
        throw new RuntimeException("Trusted proxy regression failed at check $checks.");
    }
};

try {
    $_SERVER = [
        'REQUEST_METHOD' => 'GET',
        'REMOTE_ADDR' => '192.0.2.15',
        'HTTP_X_FORWARDED_FOR' => '203.0.113.9',
    ];
    putenv('TRUSTED_PROXY_CIDRS');
    $expect(Request::capture()->ip, '192.0.2.15');

    // Untrusted external caller cannot spoof the forwarded header.
    putenv('TRUSTED_PROXY_CIDRS=172.20.0.0/24');
    $expect(Request::capture()->ip, '192.0.2.15');

    // Only a configured reverse-proxy peer can supply a forwarded IP.
    $_SERVER['REMOTE_ADDR'] = '172.20.0.7';
    $expect(Request::capture()->ip, '203.0.113.9');

    // Multi-hop header: first untrusted IP when walking backward from proxy.
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.5, 172.20.0.8';
    $expect(Request::capture()->ip, '198.51.100.5');

    // Malformed forwarded header falls back to connected peer.
    $_SERVER['HTTP_X_FORWARDED_FOR'] = 'not-an-ip';
    $expect(Request::capture()->ip, '172.20.0.7');

    // A trust-all CIDR is unsafe even if an operator accidentally configures it.
    putenv('TRUSTED_PROXY_CIDRS=0.0.0.0/0');
    $_SERVER['REMOTE_ADDR'] = '192.0.2.15';
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.99';
    $expect(Request::capture()->ip, '192.0.2.15');

    putenv('TRUSTED_PROXY_CIDRS=172.20.0.0/24');
    // IPv6 proxy support and independent real client IP.
    putenv('TRUSTED_PROXY_CIDRS=2001:db8:abcd::/48');
    $_SERVER['REMOTE_ADDR'] = '2001:db8:abcd::7';
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '2001:4860:4860::8888';
    $expect(Request::capture()->ip, '2001:4860:4860::8888');

    // A mismatched IPv6 range cannot spoof an IPv4 peer.
    $_SERVER['REMOTE_ADDR'] = '192.0.2.15';
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.99';
    $expect(Request::capture()->ip, '192.0.2.15');

    echo "Proxy security contracts passed ($checks checks).\n";
} finally {
    $_SERVER = $originalServer;
    if ($originalTrusted === false) {
        putenv('TRUSTED_PROXY_CIDRS');
    } else {
        putenv('TRUSTED_PROXY_CIDRS=' . $originalTrusted);
    }
}
