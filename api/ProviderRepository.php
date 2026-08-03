<?php

declare(strict_types=1);

final class ProviderRepository
{
    /** @var array<int, string|null>|null */
    private static ?array $logoCache = null;

    public function __construct(private PDO $db)
    {
    }

    /** @param list<string>|null $countries */
    public function listProviders(?array $countries = null, string $mediaType = 'movie'): array
    {
        $mediaType = $mediaType === 'tv' ? 'tv' : 'movie';
        $catalog = ProvidersConfig::catalog($countries);

        if ($catalog === [] && !ProvidersConfig::isActive()) {
            if ($countries !== null) {
                $placeholders = [];
                $params = ['media_type' => $mediaType];
                foreach ($countries as $i => $code) {
                    $key = 'country_' . $i;
                    $placeholders[] = ':' . $key;
                    $params[$key] = $code;
                }
                $stmt = $this->db->prepare(
                    "SELECT DISTINCT wp.tmdb_id, wp.provider_name, wp.logo_path, mwp.country_code
                     FROM watch_providers wp
                     INNER JOIN media_watch_providers mwp ON mwp.provider_id = wp.id
                     WHERE mwp.media_type = :media_type
                       AND mwp.country_code IN (" . implode(', ', $placeholders) . ")
                     ORDER BY wp.provider_name"
                );
                $stmt->execute($params);
            } else {
                $stmt = $this->db->query(
                    "SELECT tmdb_id, provider_name, logo_path, country_code
                     FROM watch_providers
                     ORDER BY provider_name"
                );
            }

            $rows = $stmt->fetchAll();

            return [
                'data' => array_map(static fn(array $row): array => [
                    'tmdb_id' => (int) $row['tmdb_id'],
                    'name' => $row['provider_name'],
                    'logo_url' => provider_logo_url((int) $row['tmdb_id'], $row['logo_path'] ?? null),
                    'country_code' => $row['country_code'] ?? null,
                ], $rows),
            ];
        }

        $logos = $this->loadLogoPaths();

        return [
            'data' => array_map(function (array $item) use ($logos): array {
                $logoPath = $logos[$item['tmdb_id']] ?? null;
                return [
                    'tmdb_id' => $item['tmdb_id'],
                    'name' => $item['name'],
                    'logo_url' => provider_logo_url((int) $item['tmdb_id'], $logoPath),
                    'country_code' => $item['country_code'],
                ];
            }, $catalog),
        ];
    }

    /** @return array<int, string|null> */
    private function loadLogoPaths(): array
    {
        if (self::$logoCache !== null) {
            return self::$logoCache;
        }
        $stmt = $this->db->query('SELECT tmdb_id, logo_path FROM watch_providers');
        $logos = [];
        foreach ($stmt->fetchAll() as $row) {
            $logos[(int) $row['tmdb_id']] = $row['logo_path'];
        }
        self::$logoCache = $logos;
        return $logos;
    }
}
