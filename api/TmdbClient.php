<?php

declare(strict_types=1);

/**
 * TMDB HTTP client with multi-key support (from TMDB_API_KEYS).
 *
 * Modes:
 * - Round-robin (default): advance key after each request
 * - Sticky pin: stay on one key (e.g. every 10 persons → next key)
 * On HTTP 429 always rotates to the next key and retries.
 */
final class TmdbClient
{
    /** @var list<string> */
    private array $apiKeys = [];
    private int $keyIndex = 0;
    private bool $sticky = false;
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
            throw new RuntimeException('No TMDB_API_KEY / TMDB_API_KEYS configured in api/.env');
        }
    }

    public function keyCount(): int
    {
        return count($this->apiKeys);
    }

    /** Current 0-based key slot (for logs). */
    public function currentKeyIndex(): int
    {
        return $this->keyIndex % max(1, count($this->apiKeys));
    }

    /**
     * Pin all following requests to one key until pinToIndex() is called again.
     * Use for "every N persons → next key" rotation.
     */
    public function pinToIndex(int $index): void
    {
        $n = count($this->apiKeys);
        $this->keyIndex = (($index % $n) + $n) % $n;
        $this->sticky = true;
    }

    /** Back to per-request round-robin. */
    public function clearPin(): void
    {
        $this->sticky = false;
    }

    /** Masked key label for browser logs (never print full secret). */
    public function keyLabel(?int $index = null): string
    {
        $i = $index ?? $this->currentKeyIndex();
        $n = count($this->apiKeys);
        $i = (($i % $n) + $n) % $n;
        $key = $this->apiKeys[$i];
        $tail = strlen($key) > 4 ? substr($key, -4) : $key;
        return 'key#' . ($i + 1) . ' …' . $tail;
    }

    /** @param array<string, scalar|null> $params */
    public function get(string $path, array $params = []): ?array
    {
        $path = ltrim($path, '/');
        $attempts = count($this->apiKeys) + 2;
        $lastError = null;
        $startedSlot = $this->currentKeyIndex();

        for ($i = 0; $i < $attempts; $i++) {
            $slot = $this->currentKeyIndex();
            $key = $this->apiKeys[$slot];

            // Round-robin advances after picking; sticky keeps the same slot
            // unless we hit 429 (handled below).
            if (!$this->sticky) {
                $this->keyIndex = ($slot + 1) % count($this->apiKeys);
            }

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
                $this->keyIndex = ($slot + 1) % count($this->apiKeys);
                continue;
            }
            if ($status === 429) {
                $lastError = 'HTTP 429 rate limit';
                $this->keyIndex = ($slot + 1) % count($this->apiKeys);
                if ($this->currentKeyIndex() === $startedSlot && $i > 0) {
                    usleep(1_500_000);
                } else {
                    usleep(400_000);
                }
                continue;
            }
            if ($status < 200 || $status >= 300) {
                $lastError = "HTTP {$status}";
                if ($status === 404) {
                    return null;
                }
                $this->keyIndex = ($slot + 1) % count($this->apiKeys);
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
