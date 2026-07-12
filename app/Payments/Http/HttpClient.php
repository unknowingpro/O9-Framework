<?php
declare(strict_types=1);

namespace App\Payments\Http;

/**
 * Minimal HTTP client for payment-gateway REST calls. Implementations return
 * the decoded JSON body as an array, plus '_status' (int HTTP code) and
 * '_raw' (string).
 */
interface HttpClient
{
    /**
     * @param array<string, mixed> $body
     * @param list<string> $headers
     * @return array<string, mixed>
     */
    public function postJson(string $url, array $body, array $headers = []): array;

    /**
     * POST application/x-www-form-urlencoded.
     *
     * @param array<string, mixed> $form
     * @param list<string> $headers
     * @return array<string, mixed>
     */
    public function postForm(string $url, array $form, array $headers = []): array;

    /**
     * @param array<string, mixed> $query
     * @param list<string> $headers
     * @return array<string, mixed>
     */
    public function get(string $url, array $query = [], array $headers = []): array;

    /**
     * HTTP DELETE.
     *
     * @param list<string> $headers
     * @return array<string, mixed>
     */
    public function delete(string $url, array $headers = []): array;
}
