<?php
declare(strict_types=1);
// Uses connection-scoped TEMPORARY tables only; never modifies catalog data.
$config = require __DIR__ . '/../api/config.php';
if (!in_array($config['db']['host'], ['localhost', '127.0.0.1'], true)) {
    throw new RuntimeException('This test must only run against local MySQL');
}
require __DIR__ . '/../api/Database.php';
require __DIR__ . '/../api/helpers.php';
require __DIR__ . '/../api/MovieBattleRepository.php';
$db = Database::connection($config);
$db->exec('CREATE TEMPORARY TABLE movies (tmdb_id INT PRIMARY KEY, title VARCHAR(100), poster_path VARCHAR(100), is_active INT) ENGINE=InnoDB');
$db->exec("INSERT INTO movies VALUES (1,'Movie A','/a.jpg',1),(2,'Movie B','/b.jpg',1),(3,'Inactive',NULL,0)");
$sql = file_get_contents(__DIR__ . '/../database/daily_movie_battle.sql');
$sql = str_replace('CREATE TABLE IF NOT EXISTS', 'CREATE TEMPORARY TABLE', $sql);
$sql = preg_replace('/,\s*CONSTRAINT fk_battle_vote[^\n]+/', '', $sql);
foreach (explode(';', $sql) as $statement) if (trim($statement) !== '') $db->exec($statement);
$repository = new MovieBattleRepository($db);
$voter = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
$other = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
$today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
$checks = 0;
function check(bool $condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
function rejects(callable $work, string $message): void {
    try { $work(); } catch (InvalidArgumentException | DomainException $e) { check(true, $message); return; }
    throw new RuntimeException($message);
}
check($repository->today($voter) === null, 'No scheduled battle');
rejects(fn() => MovieBattleRepository::validateVoter('bad'), 'Invalid UUID rejected');
rejects(fn() => $repository->save(['battle_date'=>$today,'movie_a_tmdb_id'=>1,'movie_b_tmdb_id'=>1]), 'Same movie rejected');
rejects(fn() => $repository->save(['battle_date'=>$today,'movie_a_tmdb_id'=>1,'movie_b_tmdb_id'=>3]), 'Inactive movie rejected');
$input = ['battle_date'=>$today,'movie_a_tmdb_id'=>1,'movie_b_tmdb_id'=>2,'category'=>'Sci-fi'];
$id = $repository->save($input)['id'];
$battle = $repository->today($voter);
check($battle['results'] === null && $battle['selected_tmdb_id'] === null, 'Active results hidden before voting');
check(str_ends_with($battle['starts_at'], 'T00:00:00+05:30'), 'IST midnight boundary');
rejects(fn() => $repository->save($input), 'Duplicate date rejected');
rejects(fn() => $repository->vote($id,$voter,999), 'Invalid selection rejected');
$battle = $repository->vote($id,$voter,1);
check($battle['results']['total_votes'] === 1 && $battle['results']['movie_a_percent'] === 100.0, 'First vote results');
$battle = $repository->vote($id,$voter,2);
check($battle['selected_tmdb_id'] === 1 && $battle['results']['total_votes'] === 1, 'Retry preserves original vote');
check($repository->detail($id,$other)['results'] === null, 'Results hidden from another unvoted installation');
$battle = $repository->vote($id,$other,2);
check($battle['results']['movie_a_percent'] === 50.0 && $battle['results']['leading_tmdb_id'] === null, 'Tie supported');
rejects(fn() => $repository->save(array_merge($input,['id'=>$id,'movie_a_tmdb_id'=>2,'movie_b_tmdb_id'=>1])), 'Voted pair locked');
$repository->save(array_merge($input,['id'=>$id,'status'=>'disabled']));
check($repository->today($voter) === null, 'Disabled battle hidden');
rejects(fn() => $repository->vote($id,$voter,1), 'Disabled battle rejects votes');
$repository->save(array_merge($input,['id'=>$id]));
$db->exec("UPDATE movie_battles SET battle_date = DATE_SUB('$today', INTERVAL 1 DAY) WHERE id = $id");
check($repository->detail($id,'cccccccc-cccc-4ccc-8ccc-cccccccccccc')['results']['total_votes'] === 2, 'Closed results public');
rejects(fn() => $repository->vote($id,$voter,1), 'Closed battle rejects votes');
$future = (new DateTimeImmutable($today))->modify('+1 day')->format('Y-m-d');
$futureId = $repository->save(array_merge($input,['battle_date'=>$future]))['id'];
check($repository->detail($futureId,$voter) === null, 'Future battle hidden');
rejects(fn() => $repository->vote($futureId,$voter,1), 'Future battle rejects votes');
echo "PASS: $checks Battle checks (temporary tables only)\n";
