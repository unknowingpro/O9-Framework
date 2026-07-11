<?php
declare(strict_types=1);

namespace App\Core;

/**
 * HttpResponse — a throwable that carries a finished HTTP response.
 *
 * Using an exception lets any layer (middleware, service, model)
 * short-circuit the request with a proper envelope; App::run() catches it and
 * emits the status + body.
 */
final class HttpResponse extends \RuntimeException
{
    /**
     * @param array<mixed>|string   $payload array → JSON envelope; string → raw body
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly array|string $payload,
        public readonly array $headers = []
    ) {
        parent::__construct('HTTP ' . $status);
    }

    /** Emit headers + body. */
    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }
            if (is_array($this->payload)) {
                header('Content-Type: application/json; charset=utf-8');
            }
        }
        if (is_array($this->payload)) {
            // INVALID_UTF8_SUBSTITUTE: external data (scraped titles etc.) can
            // carry broken UTF-8; without it json_encode returns false and the
            // client gets a silent empty 200.
            echo json_encode(
                $this->payload,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
            );
        } else {
            echo $this->payload;
        }
    }
}
