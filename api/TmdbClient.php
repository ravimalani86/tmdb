<?php

declare(strict_types=1);

/**
 * TMDB HTTP client with multi-key round-robin (from TMDB_API_KEYS).
 */
final class TmdbClient
{
    /** @var list<string> */
    private array $apiKeys = [];
    private int $keyIndex = 0;
    private string $baseUrl;
    private float $sleepSeconds;

    public function __construct(array $config)
    {
        $this->baseUrl = rtrim((string) ($config['tmdb_base_url'] ?? 'https://api.themoviedb.org/3'), '/');
        $this->sleepSeconds = (float) ($config['tmdb_rate_sleep'] ?? 0.2);
        $keys = $config['tmdb_api_keys'] ?? [];
        if (!is_array($keys) || $keys === []) {
            $single = (string) ($config['tmdb_api_key'] ?? '');
            if ($single !== '') {
                $keys = [$single];
            }
        }
        $this->apiKeys = array_values(array_filter(array_map('strval', $keys)));
        if ($this->apiKeys === []) {
            throw new RuntimeException('No TMDB_API_KEY / TMDB_API_KEYS configured in .env');
        }
    }

    public function keyCount(): int
    {
        return count($this->apiKeys);
    }

    /** @param array<string, scalar|null> $params */
    public function get(string $path, array $params = []): ?array
    {
        $path = ltrim($path, '/');
        $attempts = count($this->apiKeys) + 2;
        $lastError = null;

        for ($i = 0; $i < $attempts; $i++) {
            $key = $this->apiKeys[$this->keyIndex % count($this->apiKeys)];
            $this->keyIndex++;

            $query = array_merge($params, ['api_key' => $key]);
            $url = $this->baseUrl . '/' . $path . '?' . http_build_query($query);

            if ($this->sleepSeconds > 0) {
                usleep((int) ($this->sleepSeconds * 1_000_000));
            }

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 45,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_HTTPHEADER => ['Accept: application/json'],
            ]);
            $body = curl_exec($ch);
            $errno = curl_errno($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($errno !== 0 || $body === false) {
                $lastError = "curl errno {$errno}";
                continue;
            }
            if ($status === 429) {
                usleep(1_500_000);
                continue;
            }
            if ($status < 200 || $status >= 300) {
                $lastError = "HTTP {$status}";
                if ($status === 404) {
                    return null;
                }
                continue;
            }

            $decoded = json_decode($body, true);
            return is_array($decoded) ? $decoded : null;
        }

        throw new RuntimeException('TMDB request failed for /' . $path . ': ' . ($lastError ?? 'unknown'));
    }

    /**
     * Paginate list endpoints (changes / discover). Hard cap 500 pages.
     *
     * @param array<string, scalar|null> $params
     * @return list<array<string, mixed>>
     */
    public function paginateResults(string $path, array $params = [], int $maxPages = 500): array
    {
        $out = [];
        $page = 1;
        while ($page <= $maxPages) {
            $data = $this->get($path, array_merge($params, ['page' => $page]));
            if ($data === null) {
                break;
            }
            $results = $data['results'] ?? [];
            if (!is_array($results) || $results === []) {
                break;
            }
            foreach ($results as $row) {
                if (is_array($row)) {
                    $out[] = $row;
                }
            }
            $totalPages = (int) ($data['total_pages'] ?? $page);
            if ($page >= $totalPages || $page >= $maxPages) {
                break;
            }
            $page++;
        }
        return $out;
    }
}
