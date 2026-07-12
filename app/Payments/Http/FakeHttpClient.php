<?php
declare(strict_types=1);

namespace App\Payments\Http;

/** Test double: returns canned responses matched by URL substring; records calls. */
final class FakeHttpClient implements HttpClient
{
    /** @var list<array{match: string, resp: array<string, mixed>}> */
    private array $queue = [];
    /** @var list<array{method: string, url: string, body: array<string, mixed>}> */
    private array $calls = [];

    /**
     * Register a canned response for URLs containing $match.
     *
     * @param array<string, mixed> $resp
     */
    public function queue(string $match, array $resp): void
    {
        $this->queue[] = ['match' => $match, 'resp' => $resp];
    }

    public function postJson(string $url, array $body, array $headers = []): array
    {
        $this->calls[] = ['method' => 'POST', 'url' => $url, 'body' => $body];
        return $this->match($url);
    }

    public function postForm(string $url, array $form, array $headers = []): array
    {
        $this->calls[] = ['method' => 'POST_FORM', 'url' => $url, 'body' => $form];
        return $this->match($url);
    }

    public function delete(string $url, array $headers = []): array
    {
        $this->calls[] = ['method' => 'DELETE', 'url' => $url, 'body' => []];
        return $this->match($url);
    }

    public function get(string $url, array $query = [], array $headers = []): array
    {
        $this->calls[] = ['method' => 'GET', 'url' => $url, 'body' => $query];
        return $this->match($url);
    }

    /** @return list<array{method: string, url: string, body: array<string, mixed>}> */
    public function calls(): array
    {
        return $this->calls;
    }

    /**
     * Most-specific match wins: of all registered patterns contained in $url,
     * return the response for the LONGEST pattern. This disambiguates URLs
     * where one endpoint's path is a prefix of another's, regardless of
     * registration order.
     *
     * @return array<string, mixed>
     */
    private function match(string $url): array
    {
        $best = null;
        $bestLen = -1;
        foreach ($this->queue as $q) {
            if (str_contains($url, $q['match']) && strlen($q['match']) > $bestLen) {
                $best = $q['resp'];
                $bestLen = strlen($q['match']);
            }
        }
        return $best ?? ['_status' => 0];
    }
}
