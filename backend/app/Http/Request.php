<?php
declare(strict_types=1);
namespace App\Http;

final readonly class Request
{
    public ?string $ip;
    public ?string $userAgent;

    public function __construct(public array $body = [], public ?string $token = null, ?string $ip = null, ?string $userAgent = null)
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

    public static function capture(): self
    {
        $body = [];
        if (in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['POST', 'PUT', 'PATCH'], true)) {
            if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') {
                throw new HttpException('UNSUPPORTED_MEDIA_TYPE', 'Use application/json.', 415);
            }
            $raw = file_get_contents('php://input', false, null, 0, 16385);
            if ($raw === false || strlen($raw) > 16384) {
                throw new HttpException('BODY_TOO_LARGE', 'Request body exceeds 16 KB.', 413);
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
        return new self($body, $token, $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? null);
    }
}
