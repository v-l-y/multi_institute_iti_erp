<?php
declare(strict_types=1);

namespace App\Http;

final readonly class Response
{
    public function __construct(public array $body, public int $status = 200, public array $headers = [], public ?string $rawBody = null) {}

    public static function error(string $code, string $message, int $status): self
    {
        return new self(['success' => false, 'error' => ['code' => $code, 'message' => $message]], $status);
    }

    public function send(): void
    {
        http_response_code($this->status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        header('Referrer-Policy: no-referrer');
        header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
        header('X-Frame-Options: DENY');
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $this->rawBody ?? json_encode($this->body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
