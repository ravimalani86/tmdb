# Daily Movie Battle deployment

1. Back up the live database, then import `database/daily_movie_battle.sql` into the existing catalog database. This only creates two new InnoDB tables; it does not seed battles or alter existing tables.
2. Upload `api/MovieBattleRepository.php` and `api/index.php` together. Upload the repository before replacing index.php.
3. Open `/tmdb/admin/battles.php` after signing in, search the catalog, and schedule two distinct active movies for today. Future battles can be scheduled ahead.
4. After backend deployment, test Flutter against the live API. If no battle is scheduled, the Home battle card is hidden.

All endpoints use POST JSON and the existing X-API-Key header. Admin routes require ADMIN_API_KEY using the existing project's auth convention. Keep admin keys out of the Flutter app. If using a separate admin key, the current API entry gate must also accept it (implemented in index.php).

Public endpoints:
- `/battles/today`: `{ "voter_id": "installation UUID v4" }`, returns `{ "battle": null }` or the battle.
- `/battles/{id}`: same body; published current/past battles only.
- `/battles/{id}/vote`: voter_id and selected_tmdb_id. Duplicate submissions return the original vote/result without changing the choice.

Admin endpoints:
- `/admin/battles/list`: last 100 scheduled/disabled battles, with vote totals.
- `/admin/battles/movies`: search string; up to ten active catalog movies for the admin picker.
- `/admin/battles/save`: id (omit or 0 to create), battle_date (YYYY-MM-DD), movie_a_tmdb_id, movie_b_tmdb_id, category, status (scheduled/disabled).

Dates are India time (Asia/Kolkata): midnight inclusive to next midnight exclusive. The unique battle_date permits one battle per day, including disabled entries (edit/re-enable that entry rather than create another). Movie pair/date cannot be changed once votes exist. Future battles and active-battle counts are hidden from unvoted public responses; closed battles reveal final results. Counts are calculated from persisted votes, avoiding counter drift. Voting and admin updates lock the battle row in a transaction; the unique battle/voter index prevents duplicate counting.

Identity is per installation, independent of local profiles, and survives normal app restarts/updates. This is anonymous participation, not verified person/device authentication: clearing app data/reinstalling, another device, or forged voter IDs can create another identity. Existing API-key authentication does not prevent that. Login/device attestation is outside this MVP.

Validation: PHP syntax and admin JavaScript syntax checks passed. 19 backend behavior checks passed against an isolated temporary local MySQL instance (the catalog database was not modified). Flutter analysis passed and all 6 automated tests passed, including the existing smoke test. Live end-to-end Flutter verification is performed after deployment. No notifications, streaks, friend challenges, or HTTPS battle sharing links are included in this MVP.
