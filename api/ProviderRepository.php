<?php

declare(strict_types=1);

final class ProviderRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function listProviders(?string $country = null, string $mediaType = 'movie'): array
    {
        $mediaType = $mediaType === 'tv' ? 'tv' : 'movie';
        $catalog = ProvidersConfig::catalog($country);

        if ($catalog === [] && !ProvidersConfig::isActive()) {
            if ($country !== null) {
                $stmt = $this->db->prepare(
                    "SELECT DISTINCT wp.tmdb_id, wp.provider_name, wp.logo_path, mwp.country_code
                     FROM watch_providers wp
                     INNER JOIN media_watch_providers mwp ON mwp.provider_id = wp.id
                     WHERE mwp.media_type = :media_type AND mwp.country_code = :country
                     ORDER BY wp.provider_name"
                );
                $stmt->execute([
                    'media_type' => $mediaType,
                    'country' => strtoupper($country),
                ]);
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
                    'logo_url' => tmdb_image($row['logo_path'], 'w45'),
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
                    'logo_url' => tmdb_image($logoPath, 'w45'),
                    'country_code' => $item['country_code'],
                ];
            }, $catalog),
        ];
    }

    /** @return array<int, string|null> */
    private function loadLogoPaths(): array
    {
        $stmt = $this->db->query('SELECT tmdb_id, logo_path FROM watch_providers');
        $logos = [];
        foreach ($stmt->fetchAll() as $row) {
            $logos[(int) $row['tmdb_id']] = $row['logo_path'];
        }
        return $logos;
    }
}
