<?php
declare(strict_types=1);
namespace App\Http;

use Throwable;

final class Kernel
{
    public function handle(callable $dispatch): Response
    {
        $requestId = bin2hex(random_bytes(16));
        try {
            $response = $dispatch();
            // Detect serialization failures before any HTTP headers are written.
            json_encode($response->body, JSON_THROW_ON_ERROR);
        } catch (HttpException $exception) {
            $response = Response::error($exception->errorCode, $exception->getMessage(), $exception->status);
        } catch (Throwable $exception) {
            // Keep diagnostics useful without leaking exception messages, SQL, tokens or student data.
            $diagnostic = [
                'timestamp' => gmdate('c'),
                'event' => 'unhandled_exception',
                'request_id' => $requestId,
                'exception_type' => $exception::class,
                'file' => basename($exception->getFile()),
                'line' => $exception->getLine(),
            ];
            error_log('[iti-erp] ' . json_encode($diagnostic, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR));
            $response = Response::error('INTERNAL_ERROR', 'Unable to process request.', 500);
        }
        return new Response($response->body, $response->status, [...$response->headers, 'X-Request-ID' => $requestId], $response->rawBody);
    }
}
