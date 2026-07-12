<?php
declare(strict_types=1);

namespace App\Payments\Http;

/** curl-based HttpClient (zero-dependency). Transport failure -> ['_status' => 0]. */
final class CurlHttpClient implements HttpClient
{
    public function __construct(private readonly int $timeout = 15)
    {
    }

    public function postJson(string $url, array $body, array $headers = []): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => (string) json_encode($body),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers),
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        return $this->exec($ch);
    }

    public function postForm(string $url, array $form, array $headers = []): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($form),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'], $headers),
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        return $this->exec($ch);
    }

    public function get(string $url, array $query = [], array $headers = []): array
    {
        $full = $url . ($query !== [] ? ('?' . http_build_query($query)) : '');
        $ch = curl_init($full);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers),
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        return $this->exec($ch);
    }

    public function delete(string $url, array $headers = []): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers),
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        return $this->exec($ch);
    }

    /** @return array<string, mixed> */
    private function exec(\CurlHandle $ch): array
    {
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($resp === false) {
            return ['_status' => 0];
        }
        $resp = (string) $resp;
        $decoded = json_decode($resp, true);
        return ['_status' => $code, '_raw' => $resp] + (is_array($decoded) ? $decoded : []);
    }
}
