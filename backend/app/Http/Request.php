<?php
declare(strict_types=1);
namespace App\Http;

final readonly class Request
{
    public ?string $ip;
    public ?string $userAgent;

    public function __construct(public array $body = [], public ?string $token = null, ?string $ip = null, ?string $userAgent = null, public array $query = [])
    {
        $this->ip = $ip !== null && filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null;
        if ($userAgent !== null) {
            if (preg_match('//u', $userAgent) !== 1) {
                $userAgent = preg_replace('/[^\x20-\x7E]/', '?', $userAgent);
            }
            preg_match('/^.{0,500}/us', $userAgent, $matches);
            $userAgent = $matches[0];
        }
        $this->userAgent = $userAgent;
    }


    // Forwarded headers are only authoritative when their immediate peer is trusted.
    // The perimeter proxy MUST replace incoming X-Forwarded-For, never append to it.
    private static function matchesCidr(string $ip, string $cidr): bool
    {
        $parts = explode('/', trim($cidr), 2);
        $network = @inet_pton($parts[0]);
        $address = @inet_pton($ip);
        if ($network === false || $address === false || strlen($network) !== strlen($address)) {
            return false;
        }
        $max = strlen($network) * 8;
        $prefix = isset($parts[1]) && ctype_digit($parts[1]) ? (int)$parts[1] : $max;
        // Reject trust-all CIDRs, which would allow untrusted clients to spoof IPs.
        if ($prefix === 0) {
            return false;
        }
        if ($prefix < 0 || $prefix > $max) {
            return false;
        }
        $bytes = intdiv($prefix, 8);
        if (substr($network, 0, $bytes) !== substr($address, 0, $bytes)) {
            return false;
        }
        $remaining = $prefix % 8;
        return $remaining === 0
            || ((ord($network[$bytes]) ^ ord($address[$bytes])) & (255 << (8 - $remaining))) === 0;
    }

    private static function trustedProxy(string $peer): bool
    {
        $configured = getenv('TRUSTED_PROXY_CIDRS');
        if (!is_string($configured) || trim($configured) === '') {
            return false;
        }
        foreach (explode(',', $configured) as $cidr) {
            if (self::matchesCidr($peer, $cidr)) {
                return true;
            }
        }
        return false;
    }

    private static function clientIp(): ?string
    {
        $peer = $_SERVER['REMOTE_ADDR'] ?? null;
        if (!is_string($peer) || filter_var($peer, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        if (!self::trustedProxy($peer)) {
            return $peer;
        }
        $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if (!is_string($forwarded) || strlen($forwarded) > 1024) {
            return $peer;
        }
        foreach (array_reverse(explode(',', $forwarded)) as $raw) {
            $candidate = trim($raw);
            if (filter_var($candidate, FILTER_VALIDATE_IP) === false) {
                return $peer;
            }
            if (!self::trustedProxy($candidate)) {
                return $candidate;
            }
        }
        return $peer;
    }

    public static function capture(): self
    {
        $body = [];
        if (in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['POST', 'PUT', 'PATCH'], true)) {
            if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') {
                throw new HttpException('UNSUPPORTED_MEDIA_TYPE', 'Use application/json.', 415);
            }
            $upload=($_SERVER['REQUEST_METHOD']??'')==='POST' && parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH)==='/api/v1/documents/upload';
            $limit=$upload?8388608:16384;
            $raw = file_get_contents('php://input', false, null, 0, $limit+1);
            if ($raw === false || strlen($raw) > $limit) {
                throw new HttpException('BODY_TOO_LARGE', $upload?'Document request exceeds 8 MB.':'Request body exceeds 16 KB.', 413);
            }
            try {
                $decoded = json_decode($raw, false, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new HttpException('INVALID_JSON', 'Invalid JSON body.', 400);
            }
            if (!$decoded instanceof \stdClass) {
                throw new HttpException('INVALID_JSON', 'JSON body must be an object.', 400);
            }
            $body = (array) $decoded;
        }
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        $token = preg_match('/^Bearer ([a-f0-9]{64})$/i', $header, $matches) ? $matches[1] : null;
        return new self($body, $token, self::clientIp(), $_SERVER['HTTP_USER_AGENT'] ?? null, $_GET);
    }
}
