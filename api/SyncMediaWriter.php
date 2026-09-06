<?php

declare(strict_types=1);

/**
 * Upsert TMDB payloads into local MySQL tables (PHP-only sync).
 */
final class SyncMediaWriter
{
    public function __construct(private PDO $db)
    {
    }

    public function syncMovie(TmdbClient $tmdb, int $tmdbId): string
    {
        $detail = $tmdb->get("movie/{$tmdbId}", [
            'append_to_response' => 'credits,videos,images,keywords,recommendations,similar,watch/providers,external_ids,release_dates',
        ]);
        if ($detail === null) {
            throw new RuntimeException("Movie {$tmdbId} not found on TMDB");
        }

        $action = $this->upsertMovieRow($detail);
        $mediaId = $this->movieLocalId($tmdbId);
        if ($mediaId === null) {
            throw new RuntimeException("Movie {$tmdbId} missing after upsert");
        }

        $this->syncGenres('movie', $mediaId, $detail['genres'] ?? []);
        $this->syncSpokenLanguages('movie', $mediaId, $detail['spoken_languages'] ?? []);
        $this->syncMovieCredits($mediaId, $detail['credits'] ?? []);
        $this->syncVideos('movie', $mediaId, $detail['videos']['results'] ?? []);
        $this->syncImages('movie', $mediaId, $detail['images'] ?? []);
        $this->syncKeywords('movie', $mediaId, $detail['keywords']['keywords'] ?? []);
        $this->syncRecommendations('movie', $mediaId, $detail['recommendations']['results'] ?? []);
        $this->syncSimilar('movie', $mediaId, $detail['similar']['results'] ?? []);
        $this->syncWatchProviders('movie', $mediaId, $detail['watch/providers']['results'] ?? []);

        return $action;
    }

    public function syncTv(TmdbClient $tmdb, int $tmdbId): string
    {
        $detail = $tmdb->get("tv/{$tmdbId}", [
            'append_to_response' => 'aggregate_credits,videos,images,keywords,recommendations,similar,watch/providers,external_ids',
        ]);
        if ($detail === null) {
            throw new RuntimeException("TV {$tmdbId} not found on TMDB");
        }

        $action = $this->upsertTvRow($detail);
        $mediaId = $this->tvLocalId($tmdbId);
        if ($mediaId === null) {
            throw new RuntimeException("TV {$tmdbId} missing after upsert");
        }

        $this->syncGenres('tv', $mediaId, $detail['genres'] ?? []);
        $this->syncSpokenLanguages('tv', $mediaId, $detail['spoken_languages'] ?? []);
        $this->syncTvAggregateCredits($mediaId, $detail['aggregate_credits'] ?? []);
        $this->syncVideos('tv', $mediaId, $detail['videos']['results'] ?? []);
        $this->syncImages('tv', $mediaId, $detail['images'] ?? []);
        $this->syncKeywords('tv', $mediaId, $detail['keywords']['results'] ?? $detail['keywords']['keywords'] ?? []);
        $this->syncRecommendations('tv', $mediaId, $detail['recommendations']['results'] ?? []);
        $this->syncSimilar('tv', $mediaId, $detail['similar']['results'] ?? []);
        $this->syncWatchProviders('tv', $mediaId, $detail['watch/providers']['results'] ?? []);

        foreach ($detail['seasons'] ?? [] as $seasonRef) {
            if (!is_array($seasonRef)) {
                continue;
            }
            $sn = (int) ($seasonRef['season_number'] ?? -1);
            if ($sn < 0) {
                continue;
            }
            $seasonData = $tmdb->get("tv/{$tmdbId}/season/{$sn}");
            if ($seasonData === null) {
                continue;
            }
            $this->upsertTvSeason($mediaId, $seasonData);
        }

        return $action;
    }

    public function syncPerson(TmdbClient $tmdb, int $tmdbId): string
    {
        $detail = $tmdb->get("person/{$tmdbId}", [
            'append_to_response' => 'images,external_ids',
        ]);
        if ($detail === null) {
            throw new RuntimeException("Person {$tmdbId} not found on TMDB");
        }

        // Prefer external_ids.imdb_id when present (detail.imdb_id can be null).
        $ext = $detail['external_ids'] ?? null;
        if (is_array($ext) && !empty($ext['imdb_id']) && empty($detail['imdb_id'])) {
            $detail['imdb_id'] = $ext['imdb_id'];
        }

        $action = $this->upsertPersonRow($detail);
        $personId = $this->personLocalId($tmdbId);
        if ($personId === null) {
            throw new RuntimeException("Person {$tmdbId} missing after upsert");
        }

        $this->syncPersonImages($personId, $detail['images'] ?? []);
        $this->syncPersonExternalIds($personId, is_array($ext) ? $ext : []);

        return $action;
    }

    public function personLocalId(int $tmdbId): ?int
    {
        $stmt = $this->db->prepare('SELECT id FROM people WHERE tmdb_id = :id LIMIT 1');
        $stmt->execute(['id' => $tmdbId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /** @param array<string, mixed> $images */
    private function syncPersonImages(int $personId, array $images): void
    {
        try {
            $this->db->prepare('DELETE FROM images WHERE media_type = ? AND media_id = ?')
                ->execute(['person', $personId]);
        } catch (Throwable $e) {
            return;
        }
        $ins = $this->db->prepare(
            'INSERT INTO images (media_type, media_id, file_path, width, height, aspect_ratio, vote_average, vote_count, image_type, iso_639_1, last_synced_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               width = VALUES(width),
               height = VALUES(height),
               aspect_ratio = VALUES(aspect_ratio),
               vote_average = VALUES(vote_average),
               vote_count = VALUES(vote_count),
               iso_639_1 = VALUES(iso_639_1),
               last_synced_at = VALUES(last_synced_at)'
        );
        $now = gmdate('Y-m-d H:i:s');
        $n = 0;
        $seen = [];
        foreach ($images['profiles'] ?? [] as $img) {
            if (!is_array($img) || empty($img['file_path']) || $n >= 20) {
                continue;
            }
            $fp = (string) $img['file_path'];
            if (isset($seen[$fp])) {
                continue;
            }
            $seen[$fp] = true;
            try {
                $ins->execute([
                    'person',
                    $personId,
                    $fp,
                    $img['width'] ?? null,
                    $img['height'] ?? null,
                    $img['aspect_ratio'] ?? null,
                    $img['vote_average'] ?? null,
                    $img['vote_count'] ?? null,
                    'profile',
                    $img['iso_639_1'] ?? null,
                    $now,
                ]);
            } catch (Throwable $e) {
                return;
            }
            $n++;
        }
    }

    private ?bool $hasExternalIdsTable = null;

    private function hasExternalIdsTable(): bool
    {
        if ($this->hasExternalIdsTable !== null) {
            return $this->hasExternalIdsTable;
        }
        try {
            $this->db->query('SELECT 1 FROM external_ids LIMIT 1');
            $this->hasExternalIdsTable = true;
        } catch (Throwable $e) {
            $this->hasExternalIdsTable = false;
        }
        return $this->hasExternalIdsTable;
    }

    /** @param array<string, mixed> $ext */
    private function syncPersonExternalIds(int $personId, array $ext): void
    {
        if ($ext === [] || !$this->hasExternalIdsTable()) {
            return;
        }
        $now = gmdate('Y-m-d H:i:s');
        $fields = [
            'imdb_id' => $ext['imdb_id'] ?? null,
            'facebook_id' => isset($ext['facebook_id']) && $ext['facebook_id'] !== '' && $ext['facebook_id'] !== null
                ? (string) $ext['facebook_id'] : null,
            'instagram_id' => isset($ext['instagram_id']) && $ext['instagram_id'] !== '' && $ext['instagram_id'] !== null
                ? (string) $ext['instagram_id'] : null,
            'twitter_id' => isset($ext['twitter_id']) && $ext['twitter_id'] !== '' && $ext['twitter_id'] !== null
                ? (string) $ext['twitter_id'] : null,
            'wikidata_id' => $ext['wikidata_id'] ?? null,
            'youtube_id' => null,
            'last_synced_at' => $now,
        ];

        try {
            $stmt = $this->db->prepare(
                'SELECT id FROM external_ids WHERE media_type = ? AND media_id = ? LIMIT 1'
            );
            $stmt->execute(['person', $personId]);
            $existingId = $stmt->fetchColumn();

            if ($existingId === false) {
                $this->db->prepare(
                    'INSERT INTO external_ids (media_type, media_id, imdb_id, facebook_id, instagram_id, twitter_id, wikidata_id, youtube_id, last_synced_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    'person',
                    $personId,
                    $fields['imdb_id'],
                    $fields['facebook_id'],
                    $fields['instagram_id'],
                    $fields['twitter_id'],
                    $fields['wikidata_id'],
                    $fields['youtube_id'],
                    $fields['last_synced_at'],
                ]);
                return;
            }

            $this->db->prepare(
                'UPDATE external_ids
                 SET imdb_id = ?, facebook_id = ?, instagram_id = ?, twitter_id = ?,
                     wikidata_id = ?, youtube_id = ?, last_synced_at = ?
                 WHERE id = ?'
            )->execute([
                $fields['imdb_id'],
                $fields['facebook_id'],
                $fields['instagram_id'],
                $fields['twitter_id'],
                $fields['wikidata_id'],
                $fields['youtube_id'],
                $fields['last_synced_at'],
                (int) $existingId,
            ]);
        } catch (Throwable $e) {
            $this->hasExternalIdsTable = false;
        }
    }

    /** @return list<int> person tmdb ids to enqueue (top cast + directors) */
    public function topCreditPersonTmdbIds(string $mediaType, int $mediaId): array
    {
        $stmt = $this->db->prepare(
            "SELECT DISTINCT p.tmdb_id
             FROM credits c
             INNER JOIN people p ON p.id = c.person_id
             WHERE c.media_type = :mt AND c.media_id = :mid
               AND (
                 (c.credit_type = 'cast' AND c.order_index IS NOT NULL AND c.order_index < 20)
                 OR (c.credit_type = 'crew' AND c.job = 'Director')
               )"
        );
        $stmt->execute(['mt' => $mediaType, 'mid' => $mediaId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function movieLocalId(int $tmdbId): ?int
    {
        $stmt = $this->db->prepare('SELECT id FROM movies WHERE tmdb_id = :id LIMIT 1');
        $stmt->execute(['id' => $tmdbId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    public function tvLocalId(int $tmdbId): ?int
    {
        $stmt = $this->db->prepare('SELECT id FROM tv_shows WHERE tmdb_id = :id LIMIT 1');
        $stmt->execute(['id' => $tmdbId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    public function personExists(int $tmdbId): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM people WHERE tmdb_id = :id LIMIT 1');
        $stmt->execute(['id' => $tmdbId]);
        return (bool) $stmt->fetchColumn();
    }

    // --- private upserts ---

    private function upsertMovieRow(array $d): string
    {
        $tmdbId = (int) $d['id'];
        $hash = md5(json_encode($d));
        $existing = $this->movieLocalId($tmdbId);
        $collection = $d['belongs_to_collection'] ?? null;
        $collectionId = is_array($collection) ? ($collection['id'] ?? null) : null;

        $fields = [
            'title' => $d['title'] ?? null,
            'original_title' => $d['original_title'] ?? null,
            'overview' => $d['overview'] ?? null,
            'tagline' => $d['tagline'] ?? null,
            'status' => $d['status'] ?? null,
            'release_date' => $this->dateOrNull($d['release_date'] ?? null),
            'runtime' => $d['runtime'] ?? null,
            'budget' => $d['budget'] ?? null,
            'revenue' => $d['revenue'] ?? null,
            'homepage' => $d['homepage'] ?? null,
            'imdb_id' => $d['imdb_id'] ?? null,
            'popularity' => $d['popularity'] ?? null,
            'vote_average' => $d['vote_average'] ?? null,
            'vote_count' => $d['vote_count'] ?? null,
            'adult' => !empty($d['adult']) ? 1 : 0,
            'video' => !empty($d['video']) ? 1 : 0,
            'original_language' => $d['original_language'] ?? null,
            'poster_path' => $d['poster_path'] ?? null,
            'backdrop_path' => $d['backdrop_path'] ?? null,
            'collection_id' => $collectionId,
            'data_hash' => $hash,
            'last_synced_at' => gmdate('Y-m-d H:i:s'),
            'is_active' => 1,
            'deleted_at' => null,
        ];

        if ($existing === null) {
            $now = gmdate('Y-m-d H:i:s');
            $fields['created_at'] = $now;
            $fields['updated_at'] = $now;
            $cols = array_merge(['tmdb_id'], array_keys($fields));
            $vals = array_merge([$tmdbId], array_values($fields));
            $ph = implode(',', array_fill(0, count($cols), '?'));
            $sql = 'INSERT INTO movies (' . implode(',', $cols) . ') VALUES (' . $ph . ')';
            $this->db->prepare($sql)->execute($vals);
            return 'inserted';
        }

        $sets = [];
        $vals = [];
        foreach ($fields as $k => $v) {
            $sets[] = "{$k} = ?";
            $vals[] = $v;
        }
        $sets[] = 'updated_at = ?';
        $vals[] = gmdate('Y-m-d H:i:s');
        $vals[] = $tmdbId;
        $sql = 'UPDATE movies SET ' . implode(', ', $sets) . ' WHERE tmdb_id = ?';
        $this->db->prepare($sql)->execute($vals);
        return 'updated';
    }

    private function upsertTvRow(array $d): string
    {
        $tmdbId = (int) $d['id'];
        $hash = md5(json_encode($d));
        $existing = $this->tvLocalId($tmdbId);

        $fields = [
            'name' => $d['name'] ?? null,
            'original_name' => $d['original_name'] ?? null,
            'overview' => $d['overview'] ?? null,
            'status' => $d['status'] ?? null,
            'show_type' => $d['type'] ?? null,
            'first_air_date' => $this->dateOrNull($d['first_air_date'] ?? null),
            'last_air_date' => $this->dateOrNull($d['last_air_date'] ?? null),
            'number_of_seasons' => $d['number_of_seasons'] ?? null,
            'number_of_episodes' => $d['number_of_episodes'] ?? null,
            'in_production' => !empty($d['in_production']) ? 1 : 0,
            'homepage' => $d['homepage'] ?? null,
            'popularity' => $d['popularity'] ?? null,
            'vote_average' => $d['vote_average'] ?? null,
            'vote_count' => $d['vote_count'] ?? null,
            'adult' => !empty($d['adult']) ? 1 : 0,
            'original_language' => $d['original_language'] ?? null,
            'poster_path' => $d['poster_path'] ?? null,
            'backdrop_path' => $d['backdrop_path'] ?? null,
            'data_hash' => $hash,
            'last_synced_at' => gmdate('Y-m-d H:i:s'),
            'is_active' => 1,
            'deleted_at' => null,
        ];

        if ($existing === null) {
            $now = gmdate('Y-m-d H:i:s');
            $fields['created_at'] = $now;
            $fields['updated_at'] = $now;
            $cols = array_merge(['tmdb_id'], array_keys($fields));
            $vals = array_merge([$tmdbId], array_values($fields));
            $ph = implode(',', array_fill(0, count($cols), '?'));
            $this->db->prepare('INSERT INTO tv_shows (' . implode(',', $cols) . ') VALUES (' . $ph . ')')->execute($vals);
            return 'inserted';
        }

        $sets = [];
        $vals = [];
        foreach ($fields as $k => $v) {
            $sets[] = "{$k} = ?";
            $vals[] = $v;
        }
        $sets[] = 'updated_at = ?';
        $vals[] = gmdate('Y-m-d H:i:s');
        $vals[] = $tmdbId;
        $this->db->prepare('UPDATE tv_shows SET ' . implode(', ', $sets) . ' WHERE tmdb_id = ?')->execute($vals);
        return 'updated';
    }

    private function upsertPersonRow(array $d): string
    {
        $tmdbId = (int) $d['id'];
        $hash = md5(json_encode($d));
        $stmt = $this->db->prepare('SELECT id, data_hash FROM people WHERE tmdb_id = :id LIMIT 1');
        $stmt->execute(['id' => $tmdbId]);
        $existing = $stmt->fetch();

        $fields = [
            'name' => $d['name'] ?? null,
            'biography' => $d['biography'] ?? '',
            'birthday' => $this->dateOrNull($d['birthday'] ?? null),
            'deathday' => $this->dateOrNull($d['deathday'] ?? null),
            'place_of_birth' => $d['place_of_birth'] ?? null,
            'gender' => $d['gender'] ?? null,
            'known_for_department' => $d['known_for_department'] ?? null,
            'popularity' => $d['popularity'] ?? null,
            'imdb_id' => $d['imdb_id'] ?? null,
            'homepage' => $d['homepage'] ?? null,
            'profile_path' => $d['profile_path'] ?? null,
            'adult' => !empty($d['adult']) ? 1 : 0,
            'data_hash' => $hash,
            'last_synced_at' => gmdate('Y-m-d H:i:s'),
            'is_active' => 1,
            'deleted_at' => null,
        ];

        if ($existing === false) {
            $now = gmdate('Y-m-d H:i:s');
            $fields['created_at'] = $now;
            $fields['updated_at'] = $now;
            $cols = array_merge(['tmdb_id'], array_keys($fields));
            $vals = array_merge([$tmdbId], array_values($fields));
            $ph = implode(',', array_fill(0, count($cols), '?'));
            $this->db->prepare('INSERT INTO people (' . implode(',', $cols) . ') VALUES (' . $ph . ')')->execute($vals);
            return 'inserted';
        }

        $sets = [];
        $vals = [];
        foreach ($fields as $k => $v) {
            $sets[] = "{$k} = ?";
            $vals[] = $v;
        }
        $vals[] = $tmdbId;
        $this->db->prepare('UPDATE people SET ' . implode(', ', $sets) . ' WHERE tmdb_id = ?')->execute($vals);
        return 'updated';
    }

    /** Ensure stub person from credit payload; returns local people.id */
    private function ensurePersonStub(array $personData): ?int
    {
        $tmdbId = (int) ($personData['id'] ?? 0);
        if ($tmdbId <= 0) {
            return null;
        }
        $stmt = $this->db->prepare('SELECT id FROM people WHERE tmdb_id = :id LIMIT 1');
        $stmt->execute(['id' => $tmdbId]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }
        $this->db->prepare(
            'INSERT INTO people (tmdb_id, name, profile_path, popularity, gender, known_for_department, adult, is_active, last_synced_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?)'
        )->execute([
            $tmdbId,
            $personData['name'] ?? null,
            $personData['profile_path'] ?? null,
            $personData['popularity'] ?? null,
            $personData['gender'] ?? null,
            $personData['known_for_department'] ?? null,
            !empty($personData['adult']) ? 1 : 0,
            gmdate('Y-m-d H:i:s'),
            gmdate('Y-m-d H:i:s'),
            gmdate('Y-m-d H:i:s'),
        ]);
        return (int) $this->db->lastInsertId();
    }

    private function syncGenres(string $mediaType, int $mediaId, array $genres): void
    {
        $this->db->prepare('DELETE FROM media_genres WHERE media_type = ? AND media_id = ?')
            ->execute([$mediaType, $mediaId]);
        $find = $this->db->prepare('SELECT id FROM genres WHERE tmdb_id = ? AND media_type = ? LIMIT 1');
        $ins = $this->db->prepare(
            'INSERT INTO media_genres (media_type, media_id, genre_id) VALUES (?, ?, ?)'
        );
        foreach ($genres as $g) {
            $gid = is_array($g) ? (int) ($g['id'] ?? 0) : (int) $g;
            if ($gid <= 0) {
                continue;
            }
            $find->execute([$gid, $mediaType]);
            $local = $find->fetchColumn();
            if ($local === false) {
                continue;
            }
            $ins->execute([$mediaType, $mediaId, (int) $local]);
        }
    }

    private function syncSpokenLanguages(string $mediaType, int $mediaId, array $langs): void
    {
        $this->db->prepare('DELETE FROM media_spoken_languages WHERE media_type = ? AND media_id = ?')
            ->execute([$mediaType, $mediaId]);
        if ($langs === []) {
            return;
        }
        $find = $this->db->prepare('SELECT id FROM spoken_languages WHERE iso_code = ? LIMIT 1');
        $insLang = $this->db->prepare(
            'INSERT INTO spoken_languages (iso_code, language_name, english_name) VALUES (?, ?, ?)'
        );
        $link = $this->db->prepare(
            'INSERT INTO media_spoken_languages (media_type, media_id, language_id) VALUES (?, ?, ?)'
        );
        foreach ($langs as $lang) {
            if (!is_array($lang)) {
                continue;
            }
            $iso = strtolower((string) ($lang['iso_639_1'] ?? ''));
            if ($iso === '') {
                continue;
            }
            $find->execute([$iso]);
            $lid = $find->fetchColumn();
            if ($lid === false) {
                $insLang->execute([
                    $iso,
                    $lang['name'] ?? $iso,
                    $lang['english_name'] ?? ($lang['name'] ?? $iso),
                ]);
                $lid = (int) $this->db->lastInsertId();
            }
            $link->execute([$mediaType, $mediaId, (int) $lid]);
        }
    }

    private function syncMovieCredits(int $mediaId, array $credits): void
    {
        $this->db->prepare('DELETE FROM credits WHERE media_type = ? AND media_id = ?')
            ->execute(['movie', $mediaId]);
        $ins = $this->db->prepare(
            'INSERT INTO credits (media_type, media_id, person_id, credit_type, `character`, job, department, order_index, last_synced_at)
             VALUES (\'movie\', ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $now = gmdate('Y-m-d H:i:s');
        foreach ($credits['cast'] ?? [] as $idx => $cast) {
            if (!is_array($cast)) {
                continue;
            }
            $pid = $this->ensurePersonStub($cast);
            if ($pid === null) {
                continue;
            }
            $ins->execute([
                $mediaId,
                $pid,
                'cast',
                $cast['character'] ?? null,
                null,
                null,
                $cast['order'] ?? $idx,
                $now,
            ]);
        }
        foreach ($credits['crew'] ?? [] as $crew) {
            if (!is_array($crew)) {
                continue;
            }
            $pid = $this->ensurePersonStub($crew);
            if ($pid === null) {
                continue;
            }
            $ins->execute([
                $mediaId,
                $pid,
                'crew',
                null,
                $crew['job'] ?? null,
                $crew['department'] ?? null,
                null,
                $now,
            ]);
        }
    }

    private function syncTvAggregateCredits(int $mediaId, array $credits): void
    {
        $this->db->prepare('DELETE FROM credits WHERE media_type = ? AND media_id = ?')
            ->execute(['tv', $mediaId]);
        $ins = $this->db->prepare(
            'INSERT INTO credits (media_type, media_id, person_id, credit_type, `character`, job, department, episode_count, order_index, last_synced_at)
             VALUES (\'tv\', ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $now = gmdate('Y-m-d H:i:s');
        foreach ($credits['cast'] ?? [] as $idx => $cast) {
            if (!is_array($cast)) {
                continue;
            }
            $pid = $this->ensurePersonStub($cast);
            if ($pid === null) {
                continue;
            }
            $roles = $cast['roles'] ?? [];
            if ($roles === []) {
                $ins->execute([
                    $mediaId, $pid, 'cast', null, null, null,
                    $cast['total_episode_count'] ?? null, $cast['order'] ?? $idx, $now,
                ]);
                continue;
            }
            foreach ($roles as $role) {
                if (!is_array($role)) {
                    continue;
                }
                $ins->execute([
                    $mediaId, $pid, 'cast', $role['character'] ?? null, null, null,
                    $role['episode_count'] ?? null, $cast['order'] ?? $idx, $now,
                ]);
            }
        }
        foreach ($credits['crew'] ?? [] as $crew) {
            if (!is_array($crew)) {
                continue;
            }
            $pid = $this->ensurePersonStub($crew);
            if ($pid === null) {
                continue;
            }
            $jobs = $crew['jobs'] ?? [];
            if ($jobs === []) {
                $ins->execute([
                    $mediaId, $pid, 'crew', null, null, $crew['department'] ?? null,
                    $crew['total_episode_count'] ?? null, null, $now,
                ]);
                continue;
            }
            foreach ($jobs as $job) {
                if (!is_array($job)) {
                    continue;
                }
                $ins->execute([
                    $mediaId, $pid, 'crew', null, $job['job'] ?? null, $crew['department'] ?? null,
                    $job['episode_count'] ?? null, null, $now,
                ]);
            }
        }
    }

    private function syncVideos(string $mediaType, int $mediaId, array $videos): void
    {
        $this->db->prepare('DELETE FROM videos WHERE media_type = ? AND media_id = ?')
            ->execute([$mediaType, $mediaId]);
        $ins = $this->db->prepare(
            'INSERT INTO videos (media_type, media_id, tmdb_video_id, name, `key`, site, size, video_type, official, published_at, last_synced_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $now = gmdate('Y-m-d H:i:s');
        foreach ($videos as $v) {
            if (!is_array($v) || empty($v['id'])) {
                continue;
            }
            $ins->execute([
                $mediaType,
                $mediaId,
                (string) $v['id'],
                $v['name'] ?? null,
                $v['key'] ?? null,
                $v['site'] ?? null,
                $v['size'] ?? null,
                $v['type'] ?? null,
                !empty($v['official']) ? 1 : 0,
                $this->dateTimeOrNull($v['published_at'] ?? null),
                $now,
            ]);
        }
    }

    private function syncImages(string $mediaType, int $mediaId, array $images): void
    {
        $this->db->prepare('DELETE FROM images WHERE media_type = ? AND media_id = ?')
            ->execute([$mediaType, $mediaId]);
        // uq_image = (media_type, media_id, file_path, image_type)
        // TMDB sometimes returns the same file_path twice (different iso_639_1).
        $ins = $this->db->prepare(
            'INSERT INTO images (media_type, media_id, file_path, width, height, aspect_ratio, vote_average, vote_count, image_type, iso_639_1, last_synced_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               width = VALUES(width),
               height = VALUES(height),
               aspect_ratio = VALUES(aspect_ratio),
               vote_average = VALUES(vote_average),
               vote_count = VALUES(vote_count),
               iso_639_1 = VALUES(iso_639_1),
               last_synced_at = VALUES(last_synced_at)'
        );
        $now = gmdate('Y-m-d H:i:s');
        foreach (['posters' => 'poster', 'backdrops' => 'backdrop'] as $key => $type) {
            $n = 0;
            $seen = [];
            foreach ($images[$key] ?? [] as $img) {
                if (!is_array($img) || empty($img['file_path']) || $n >= 20) {
                    continue;
                }
                $fp = (string) $img['file_path'];
                if (isset($seen[$fp])) {
                    continue;
                }
                $seen[$fp] = true;
                $ins->execute([
                    $mediaType,
                    $mediaId,
                    $fp,
                    $img['width'] ?? null,
                    $img['height'] ?? null,
                    $img['aspect_ratio'] ?? null,
                    $img['vote_average'] ?? null,
                    $img['vote_count'] ?? null,
                    $type,
                    $img['iso_639_1'] ?? null,
                    $now,
                ]);
                $n++;
            }
        }
    }

    private function syncKeywords(string $mediaType, int $mediaId, array $keywords): void
    {
        $this->db->prepare('DELETE FROM media_keywords WHERE media_type = ? AND media_id = ?')
            ->execute([$mediaType, $mediaId]);
        $find = $this->db->prepare('SELECT id FROM keywords WHERE tmdb_id = ? LIMIT 1');
        $insKw = $this->db->prepare('INSERT INTO keywords (tmdb_id, name) VALUES (?, ?)');
        $link = $this->db->prepare(
            'INSERT INTO media_keywords (media_type, media_id, keyword_id) VALUES (?, ?, ?)'
        );
        foreach ($keywords as $kw) {
            if (!is_array($kw)) {
                continue;
            }
            $kid = (int) ($kw['id'] ?? 0);
            if ($kid <= 0) {
                continue;
            }
            $find->execute([$kid]);
            $local = $find->fetchColumn();
            if ($local === false) {
                $insKw->execute([$kid, $kw['name'] ?? (string) $kid]);
                $local = (int) $this->db->lastInsertId();
            }
            $link->execute([$mediaType, $mediaId, (int) $local]);
        }
    }

    private function syncRecommendations(string $mediaType, int $mediaId, array $rows): void
    {
        $this->db->prepare('DELETE FROM recommendations WHERE media_type = ? AND media_id = ?')
            ->execute([$mediaType, $mediaId]);
        $ins = $this->db->prepare(
            'INSERT INTO recommendations (media_type, media_id, recommended_media_id, last_synced_at)
             VALUES (?, ?, ?, ?)'
        );
        $now = gmdate('Y-m-d H:i:s');
        foreach (array_slice($rows, 0, 20) as $row) {
            if (!is_array($row) || empty($row['id'])) {
                continue;
            }
            $ins->execute([$mediaType, $mediaId, (int) $row['id'], $now]);
        }
    }

    private function syncSimilar(string $mediaType, int $mediaId, array $rows): void
    {
        $this->db->prepare('DELETE FROM similar_media WHERE media_type = ? AND media_id = ?')
            ->execute([$mediaType, $mediaId]);
        $ins = $this->db->prepare(
            'INSERT INTO similar_media (media_type, media_id, similar_media_id, last_synced_at)
             VALUES (?, ?, ?, ?)'
        );
        $now = gmdate('Y-m-d H:i:s');
        foreach (array_slice($rows, 0, 20) as $row) {
            if (!is_array($row) || empty($row['id'])) {
                continue;
            }
            $ins->execute([$mediaType, $mediaId, (int) $row['id'], $now]);
        }
    }

    private function syncWatchProviders(string $mediaType, int $mediaId, array $byCountry): void
    {
        $this->db->prepare('DELETE FROM media_watch_providers WHERE media_type = ? AND media_id = ?')
            ->execute([$mediaType, $mediaId]);
        $find = $this->db->prepare('SELECT id FROM watch_providers WHERE tmdb_id = ? LIMIT 1');
        $insWp = $this->db->prepare(
            'INSERT INTO watch_providers (tmdb_id, provider_name, logo_path) VALUES (?, ?, ?)'
        );
        $link = $this->db->prepare(
            'INSERT IGNORE INTO media_watch_providers (media_type, media_id, provider_id, country_code, provider_type)
             VALUES (?, ?, ?, ?, ?)'
        );

        foreach ($byCountry as $country => $block) {
            if (!is_array($block)) {
                continue;
            }
            $country = strtoupper((string) $country);
            foreach (['flatrate', 'ads', 'free', 'rent', 'buy'] as $ptype) {
                foreach ($block[$ptype] ?? [] as $p) {
                    if (!is_array($p)) {
                        continue;
                    }
                    $pid = (int) ($p['provider_id'] ?? 0);
                    if ($pid <= 0) {
                        continue;
                    }
                    $find->execute([$pid]);
                    $local = $find->fetchColumn();
                    if ($local === false) {
                        $insWp->execute([$pid, $p['provider_name'] ?? (string) $pid, $p['logo_path'] ?? null]);
                        $local = (int) $this->db->lastInsertId();
                    }
                    $link->execute([$mediaType, $mediaId, (int) $local, $country, $ptype]);
                }
            }
        }
    }

    private function upsertTvSeason(int $showId, array $seasonData): void
    {
        $seasonTmdbId = (int) ($seasonData['id'] ?? 0);
        $sn = (int) ($seasonData['season_number'] ?? 0);
        $stmt = $this->db->prepare('SELECT id FROM tv_seasons WHERE tmdb_id = ? LIMIT 1');
        $stmt->execute([$seasonTmdbId]);
        $seasonId = $stmt->fetchColumn();

        $fields = [
            'tv_show_id' => $showId,
            'tmdb_id' => $seasonTmdbId,
            'season_number' => $sn,
            'name' => $seasonData['name'] ?? null,
            'overview' => $seasonData['overview'] ?? null,
            'air_date' => $this->dateOrNull($seasonData['air_date'] ?? null),
            'episode_count' => isset($seasonData['episodes']) ? count($seasonData['episodes']) : ($seasonData['episode_count'] ?? null),
            'poster_path' => $seasonData['poster_path'] ?? null,
            'vote_average' => $seasonData['vote_average'] ?? null,
        ];

        if ($seasonId === false) {
            $now = gmdate('Y-m-d H:i:s');
            $fields['created_at'] = $now;
            $fields['updated_at'] = $now;
            $cols = array_keys($fields);
            $vals = array_values($fields);
            $ph = implode(',', array_fill(0, count($cols), '?'));
            $this->db->prepare('INSERT INTO tv_seasons (' . implode(',', $cols) . ') VALUES (' . $ph . ')')->execute($vals);
            $seasonId = (int) $this->db->lastInsertId();
        } else {
            $sets = [];
            $vals = [];
            foreach ($fields as $k => $v) {
                if ($k === 'tmdb_id') {
                    continue;
                }
                $sets[] = "{$k} = ?";
                $vals[] = $v;
            }
            $sets[] = 'updated_at = ?';
            $vals[] = gmdate('Y-m-d H:i:s');
            $vals[] = $seasonTmdbId;
            $this->db->prepare('UPDATE tv_seasons SET ' . implode(', ', $sets) . ' WHERE tmdb_id = ?')->execute($vals);
            $seasonId = (int) $seasonId;
        }

        $this->db->prepare('DELETE FROM tv_episodes WHERE season_id = ?')->execute([$seasonId]);
        $ins = $this->db->prepare(
            'INSERT INTO tv_episodes (tv_show_id, season_id, tmdb_id, episode_number, name, overview, air_date, runtime, still_path, vote_average, vote_count, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $now = gmdate('Y-m-d H:i:s');
        foreach ($seasonData['episodes'] ?? [] as $ep) {
            if (!is_array($ep)) {
                continue;
            }
            $ins->execute([
                $showId,
                $seasonId,
                (int) ($ep['id'] ?? 0),
                (int) ($ep['episode_number'] ?? 0),
                $ep['name'] ?? null,
                $ep['overview'] ?? null,
                $this->dateOrNull($ep['air_date'] ?? null),
                $ep['runtime'] ?? null,
                $ep['still_path'] ?? null,
                $ep['vote_average'] ?? null,
                $ep['vote_count'] ?? null,
                $now,
                $now,
            ]);
        }
    }

    private function dateOrNull(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        $s = (string) $v;
        return preg_match('/^\d{4}-\d{2}-\d{2}/', $s) ? substr($s, 0, 10) : null;
    }

    private function dateTimeOrNull(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        $ts = strtotime((string) $v);
        return $ts === false ? null : gmdate('Y-m-d H:i:s', $ts);
    }
}
