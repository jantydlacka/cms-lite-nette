<?php

declare(strict_types=1);

use Nette\Database\Connection;
use Nette\Database\DriverException;
use Nette\DI\Container;
use Tester\Assert;
use Tests\Support\TestDatabase;
use function Tests\container;

require __DIR__ . '/../bootstrap.php';

// A separate session, as each HTTP request in tests has: it sees only committed rows.
function observer(Container $container): Connection
{
	$params = $container->getParameters();
	assert(is_string($params['dbHost']) && is_string($params['dbPort']) && is_string($params['dbName']) && is_string($params['dbUser']) && is_string($params['dbPassword']));
	$connection = new Connection('mysql:host=' . $params['dbHost'] . ';port=' . $params['dbPort'] . ';dbname=' . $params['dbName'], $params['dbUser'], $params['dbPassword']);
	// Rows locked by an unfinished restore must fail the test, not hang it.
	$connection->query('SET SESSION innodb_lock_wait_timeout = 2, information_schema_stats_expiry = 0');

	return $connection;
}


// Every table with its sorted rows and its next AUTO_INCREMENT value.
// $withTimes = false hides timestamps, which differ between two real migration loads.
/** @return array<string, array{next: mixed, rows: list<array<mixed>>}> */
function databaseState(Container $container, bool $withTimes = true): array
{
	$connection = observer($container);
	// A counter that was never used reads NULL instead of 1.
	$tables = $connection->fetchPairs(
		'SELECT t.TABLE_NAME, IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS c WHERE c.TABLE_SCHEMA = t.TABLE_SCHEMA AND c.TABLE_NAME = t.TABLE_NAME AND c.EXTRA LIKE ?), COALESCE(t.AUTO_INCREMENT, 1), NULL)
		FROM information_schema.TABLES t WHERE t.TABLE_SCHEMA = DATABASE() AND t.TABLE_TYPE = ? ORDER BY t.TABLE_NAME',
		'%auto_increment%',
		'BASE TABLE',
	);
	$state = [];
	foreach ($tables as $table => $next) {
		assert(is_string($table));
		$rows = [];
		foreach ($connection->fetchAll('SELECT * FROM ?name', $table) as $row) {
			$rows[] = array_map(static fn (mixed $value): mixed => $value instanceof DateTimeInterface ? ($withTimes ? $value->format('Y-m-d H:i:s.u e') : 'time') : $value, (array) $row);
		}
		// Sorted in PHP so the dump does not depend on the physical row order.
		usort($rows, static fn (array $a, array $b): int => serialize($a) <=> serialize($b));
		$state[$table] = ['next' => $next, 'rows' => $rows];
	}

	return $state;
}


/** @return array{mixed, mixed, mixed} whether foreign_key_checks, sql_mode and time_zone equal the global values */
function sessionGuard(Connection $connection): array
{
	$row = $connection->fetch('SELECT @@session.foreign_key_checks = @@global.foreign_key_checks AS fk, @@session.sql_mode = @@global.sql_mode AS mode, @@session.time_zone = @@global.time_zone AS tz');
	assert($row !== null);

	return [$row->fk, $row->mode, $row->tz];
}


function perturbSession(Connection $connection): void
{
	$connection->query('SET SESSION foreign_key_checks = 0, time_zone = ?, sql_mode = ?', '+05:00', '');
	Assert::same([0, 0, 0], sessionGuard($connection));
}


function newPageId(Container $container): int
{
	$connection = observer($container);
	$connection->query('INSERT INTO page (position, created_at, updated_at) VALUES (99, NOW(), NOW())');
	$id = (int) $connection->getInsertId();
	$connection->query('DELETE FROM page WHERE id = ?', $id);

	return $id;
}


/** Changes every base table, including some AUTO_INCREMENT counters. */
function mutateAll(Connection $connection, int $round): void
{
	$connection->query('INSERT INTO page (position, in_menu, created_at, updated_at) VALUES (7, 1, NOW(), NOW())');
	$page = (int) $connection->getInsertId();
	$connection->query('UPDATE page SET in_menu = 0, version = version + 1 WHERE id <> ?', $page);
	$connection->query('INSERT INTO page_translation (page_id, locale, title, slug, path, content, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())', $page, 'cs', 'Nová', 'nova-' . $round, 'nova-' . $round, 'x');
	$connection->query('UPDATE page_translation SET title = ?, version = version + 1 WHERE slug = ?', 'Změněno', 'o-nas');
	$connection->query('DELETE FROM page_translation WHERE slug = ?', 'rozpracovano');
	$connection->query('INSERT INTO path_redirect (locale, old_path, target_type, target_id, created_at) VALUES (?, ?, ?, ?, NOW())', 'cs', 'stara-' . $round, 'page', $page);
	$connection->query('DELETE FROM path_redirect WHERE old_path = ?', 'o-firme');
	$connection->query('UPDATE setting SET value = ? WHERE `key` = ? AND locale = ?', (string) $page, 'homepage', '');
	$connection->query('INSERT INTO setting ?', ['key' => 'scratch', 'locale' => '', 'value' => 'x']);
	$connection->query('INSERT INTO login_attempt (email, ip, attempted_at) VALUES (?, ?, NOW())', 'x@example.com', '127.0.0.1');
	$connection->query('INSERT INTO `user` (email, password_hash, name, role, created_at) VALUES (?, ?, ?, ?, NOW())', 'new-' . $round . '@example.com', 'x', 'New', 'editor');
	$connection->query('UPDATE `user` SET active = 0');
	$connection->query('DELETE FROM page_tree_lock');
	$connection->query('INSERT INTO news (published_at, created_at, updated_at) VALUES (NOW(), NOW(), NOW())');
	$connection->query('INSERT INTO news_translation (news_id, locale, title, slug, perex, content, created_at, updated_at) VALUES (LAST_INSERT_ID(), ?, ?, ?, ?, ?, NOW(), NOW())', 'cs', 'Nová', 'nova-' . $round, 'P', 'x');
	$connection->query('UPDATE migrations SET executed = executed - INTERVAL 1 DAY');
}


/** Changes only tables without an AUTO_INCREMENT counter, so the restore needs no ALTER (and its implicit commit). */
function mutateWithoutCounters(Connection $connection): void
{
	$connection->query('UPDATE setting SET value = ? WHERE `key` = ? AND locale = ?', '999', 'homepage', '');
	$connection->query('DELETE FROM path_redirect');
	$connection->query('DELETE FROM page_tree_lock');
}


$container = container();
$connection = $container->getByType(Connection::class);
// The first call in a process runs the real migrations and keeps their result as the template.
TestDatabase::resetDummyData($container);
$fresh = databaseState($container);
$freshWithoutTimes = databaseState($container, false);
$freshNextPage = newPageId($container);
Assert::same(1, $connection->fetchField('SELECT COUNT(*) FROM page_tree_lock WHERE id = 1'));
Assert::same(5, $connection->fetchField('SELECT COUNT(*) FROM page'));
// newPageId() moved the page counter; the rounds below need to start with no counter moved.
TestDatabase::resetDummyData($container);


test('resetDummyData() restores the exact state of a fresh migration load, as another session sees it', function () use ($container, $connection, $fresh, $freshNextPage): void {
	$rounds = ['without counters', 'all', 'without counters', 'all'];
	foreach ($rounds as $round => $kind) {
		$label = "round $round ($kind)";
		$kind === 'all' ? mutateAll($connection, $round) : mutateWithoutCounters($connection);
		perturbSession($connection);
		$mutated = databaseState($container);
		if ($kind === 'all') {
			// A restore that skips any table must fail below.
			foreach ($fresh as $table => $state) {
				Assert::notSame($state['rows'], $mutated[$table]['rows'], "$label: $table is not mutated");
			}
		} else {
			Assert::same(array_column($fresh, 'next'), array_column($mutated, 'next'), "$label: a counter moved");
		}

		TestDatabase::resetDummyData($container);

		Assert::same([1, 1, 1], sessionGuard($connection), $label);
		Assert::same($fresh, databaseState($container), $label);
		Assert::same(1, observer($container)->fetchField('SELECT COUNT(*) FROM page_tree_lock WHERE id = 1'), $label);
	}
	// Last, because it moves the page counter again.
	Assert::same($freshNextPage, newPageId($container));
});


test('resetDummyData() reloads through the migrations when a table structure changed', function () use ($container, $connection, $freshWithoutTimes): void {
	$connection->query('ALTER TABLE setting ADD COLUMN scratch INT NULL');

	TestDatabase::resetDummyData($container);

	Assert::same(0, observer($container)->fetchField('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = ?', 'scratch'));
	Assert::same($freshWithoutTimes, databaseState($container, false));
});


test('a failed restore rolls back, keeps the session guard and rebuilds the template next time', function () use ($container, $connection, $freshWithoutTimes): void {
	$database = $connection->fetchField('SELECT DATABASE()');
	assert(is_string($database));
	mutateWithoutCounters($connection);
	$before = $connection->fetchAll('SELECT * FROM setting');
	perturbSession($connection);
	// `setting` sorts after `page*`, so the restore fails with earlier tables already emptied and refilled.
	$connection->query('DROP TABLE ?name', $database . '_demo.setting');

	Assert::exception(static fn () => TestDatabase::resetDummyData($container), DriverException::class);

	Assert::equal($before, $connection->fetchAll('SELECT * FROM setting'), 'the emptied table must be rolled back');
	Assert::same([1, 1, 1], sessionGuard($connection));

	TestDatabase::resetDummyData($container);
	Assert::same($freshWithoutTimes, databaseState($container, false));
});
