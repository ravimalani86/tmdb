<?php

declare(strict_types=1);

/**
 * Allowed watch providers from providers_config.json (same file as Python sync).
 */
final class ProvidersConfig
{
    private static ?array $parsed = null;

    /** @return array{monetization: string, by_country: array<string, array<int, array{name: string}>>}|null */
    public static function load(): ?array
    {
        if (self::$parsed !== null) {
            return self::$parsed ?: null;
        }

        $paths = [
            dirname(__DIR__) . DIRECTORY_SEPARATOR . 'providers_config.json',
            __DIR__ . DIRECTORY_SEPARATOR . 'providers_config.json',
        ];

        foreach ($paths as $path) {
            if (!is_readable($path)) {
                continue;
            }
            $raw = file_get_contents($path);
            if ($raw === false) {
                continue;
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                continue;
            }
            self::$parsed = self::parse($decoded);
            return self::$parsed;
        }

        self::$parsed = [];
        return null;
    }

    /** @param array<string, mixed> $decoded */
    private static function parse(array $decoded): array
    {
        $byCountry = [];
        foreach ($decoded['providers'] ?? [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $region = strtoupper((string) ($item['region'] ?? ''));
            $tmdbId = (int) ($item['tmdb_id'] ?? 0);
            $name = (string) ($item['name'] ?? '');
            if ($region === '' || $tmdbId <= 0) {
                continue;
            }
            if (!isset($byCountry[$region][$tmdbId])) {
                $byCountry[$region][$tmdbId] = ['name' => $name];
            }
        }

        return [
            'monetization' => (string) ($decoded['monetization'] ?? 'flatrate'),
            'by_country' => $byCountry,
        ];
    }

    public static function monetization(): string
    {
        $cfg = self::load();
        return $cfg['monetization'] ?? 'flatrate';
    }

    public static function isActive(): bool
    {
        return self::load() !== null;
    }

    public static function isAllowed(string $countryCode, int $tmdbId): bool
    {
        $cfg = self::load();
        if ($cfg === null) {
            return true;
        }
        $country = strtoupper($countryCode);
        return isset($cfg['by_country'][$country][$tmdbId]);
    }

    public static function isAllowedInAnyRegion(int $tmdbId): bool
    {
        $cfg = self::load();
        if ($cfg === null) {
            return true;
        }
        foreach ($cfg['by_country'] as $providers) {
            if (isset($providers[$tmdbId])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Catalog entries for GET /providers (from JSON, not full DB dump).
     *
     * @return list<array{country_code: string, tmdb_id: int, name: string}>
     */
    public static function catalog(?string $countryCode = null): array
    {
        $cfg = self::load();
        if ($cfg === null) {
            return [];
        }

        $items = [];
        $filter = $countryCode !== null ? strtoupper($countryCode) : null;
        foreach ($cfg['by_country'] as $country => $providers) {
            if ($filter !== null && $country !== $filter) {
                continue;
            }
            foreach ($providers as $tmdbId => $meta) {
                $items[] = [
                    'country_code' => $country,
                    'tmdb_id' => (int) $tmdbId,
                    'name' => $meta['name'],
                ];
            }
        }

        usort($items, static function (array $a, array $b): int {
            $cmp = strcmp($a['country_code'], $b['country_code']);
            return $cmp !== 0 ? $cmp : strcmp($a['name'], $b['name']);
        });

        return $items;
    }

    /**
     * SQL AND fragment for allowed provider rows, e.g. mwp + wp aliases.
     */
    public static function sqlFilter(string $mwpAlias, string $wpAlias, ?string $countryCode = null): string
    {
        $cfg = self::load();
        if ($cfg === null) {
            return '';
        }

        $parts = [sprintf('%s.provider_type = %s', $mwpAlias, self::quote(self::monetization()))];
        $country = $countryCode !== null ? strtoupper($countryCode) : null;

        if ($country !== null) {
            $ids = array_keys($cfg['by_country'][$country] ?? []);
            if ($ids === []) {
                return ' AND 1 = 0';
            }
            $parts[] = sprintf('%s.country_code = %s', $mwpAlias, self::quote($country));
            $parts[] = sprintf('%s.tmdb_id IN (%s)', $wpAlias, implode(',', array_map('intval', $ids)));
        } else {
            $regionParts = [];
            foreach ($cfg['by_country'] as $region => $providers) {
                $ids = array_keys($providers);
                if ($ids === []) {
                    continue;
                }
                $regionParts[] = sprintf(
                    '(%s.country_code = %s AND %s.tmdb_id IN (%s))',
                    $mwpAlias,
                    self::quote($region),
                    $wpAlias,
                    implode(',', array_map('intval', $ids))
                );
            }
            if ($regionParts === []) {
                return ' AND 1 = 0';
            }
            $parts[] = '(' . implode(' OR ', $regionParts) . ')';
        }

        return ' AND ' . implode(' AND ', $parts);
    }

    /**
     * @param list<array<string, mixed>> $rows DB rows with country_code, tmdb_id, provider_type/type
     * @return list<array<string, mixed>>
     */
    public static function filterRows(array $rows): array
    {
        $cfg = self::load();
        if ($cfg === null) {
            return $rows;
        }

        $monetization = self::monetization();
        return array_values(array_filter($rows, static function (array $row) use ($cfg, $monetization): bool {
            $type = (string) ($row['provider_type'] ?? $row['type'] ?? '');
            if ($type !== '' && $type !== $monetization) {
                return false;
            }
            $country = strtoupper((string) ($row['country_code'] ?? ''));
            $tmdbId = (int) ($row['tmdb_id'] ?? 0);
            return isset($cfg['by_country'][$country][$tmdbId]);
        }));
    }

    private static function quote(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }
}
