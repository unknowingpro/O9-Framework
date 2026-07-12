<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Minimal PSR-7-ish HTTP response returned by HttpClient. Exposes
 * getStatusCode() and getBody()->getContents(), the same shape a Guzzle
 * response has, without the dependency. (Named HttpClientResponse, not
 * HttpResponse, to avoid colliding with Core\HttpResponse — the throwable
 * used for controller/kernel responses.)
 */
final class HttpClientResponse
{
    public function __construct(private int $status, private string $body)
    {
    }

    public function getStatusCode(): int
    {
        return $this->status;
    }

    public function getBody(): HttpStream
    {
        return new HttpStream($this->body);
    }

    /**
     * Convenience: decode the JSON body to an array.
     *
     * @return array<string, mixed>
     */
    public function json(): array
    {
        $d = json_decode($this->body, true);
        return is_array($d) ? $d : [];
    }
}
