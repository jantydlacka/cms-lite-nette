<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Bootstrap;
use LogicException;
use Nette\Database\Connection;
use Nette\DI\Container;
use Nextras\Migrations\Bridges\NetteDatabase\NetteAdapter;
use Nextras\Migrations\Drivers\MySqlDriver;
use Nextras\Migrations\Engine\Runner;
use Nextras\Migrations\Entities\Group;
use Nextras\Migrations\Extensions\SqlHandler;

/** One MySQL database per Nette Tester thread, so parallel test files never share data. */
final class TestDatabase
{
	private const MIGRATIONS_TABLE = 'migrations';
	private const MIGRATIONS_DIR = __DIR__ . '/../../migrations';


	public static function name(?string $thread): string
	{
		return Bootstrap::testDatabaseName($thread ?? false);
	}


	/**
	 * Leaves the database at its schema plus basic data only.
	 *
	 * Basic data is not re-inserted by replaying SQL by hand: after TRUNCATE the migrations table still
	 * lists it as executed, so its rows are dropped from the table and the groups run again.
	 * Dummy data is never loaded. Structure migrations must not insert data: everything is truncated after them.
	 *
	 * @param list<string> $extraBasicDataDirs additional directories loaded after migrations/basic-data
	 */
	public static function prepare(Container $container, array $extraBasicDataDirs = []): void
	{
		$params = $container->getParameters();
		$database = $params['dbName'];
		assert(is_string($database) && is_string($params['dbHost']) && is_string($params['dbPort']) && is_string($params['dbUser']) && is_string($params['dbPassword']));
		self::assertTestDatabase($database);

		// The container's own connection cannot exist before the database does.
		$admin = new Connection('mysql:host=' . $params['dbHost'] . ';port=' . $params['dbPort'], $params['dbUser'], $params['dbPassword']);
		$admin->query('CREATE DATABASE IF NOT EXISTS ?name CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', $database);

		$connection = $container->getByType(Connection::class);
		self::assertTestDatabase($connection->fetchField('SELECT DATABASE()'));
		$structures = self::group('structures', self::MIGRATIONS_DIR . '/structures', []);

		// Data groups left by an earlier run (possibly with other fixtures) must not be unknown to the runner.
		if ($connection->fetchField('SHOW TABLES LIKE ?', self::MIGRATIONS_TABLE) !== null) {
			$connection->query('DELETE FROM ?name WHERE `group` <> ?', self::MIGRATIONS_TABLE, 'structures');
		}

		self::migrate($connection, [$structures]);

		$connection->query('SET FOREIGN_KEY_CHECKS = 0');
		try {
			foreach ($connection->fetchAll('SHOW FULL TABLES WHERE Table_type = ?', 'BASE TABLE') as $row) {
				$table = array_values((array) $row)[0];
				assert(is_string($table));
				if ($table !== self::MIGRATIONS_TABLE) {
					$connection->query('TRUNCATE TABLE ?name', $table);
				}
			}
		} finally {
			$connection->query('SET FOREIGN_KEY_CHECKS = 1');
		}

		$groups = [$structures, self::group('basic-data', self::MIGRATIONS_DIR . '/basic-data', ['structures'])];
		foreach ($extraBasicDataDirs as $i => $dir) {
			$groups[] = self::group('basic-data-extra-' . $i, $dir, ['basic-data']);
		}
		self::migrate($connection, $groups);
	}


	/** Last line of defence: TRUNCATE must never reach a database outside the test prefix. */
	private static function assertTestDatabase(mixed $database): void
	{
		if (!is_string($database) || !str_starts_with($database, Bootstrap::TEST_DB_PREFIX)) {
			throw new LogicException('Refusing to prepare a database outside the test prefix.');
		}
	}


	/** @param list<Group> $groups */
	private static function migrate(Connection $connection, array $groups): void
	{
		$driver = new MySqlDriver(new NetteAdapter($connection), self::MIGRATIONS_TABLE);
		$runner = new Runner($driver, new ThrowingPrinter());
		$runner->addExtensionHandler('sql', new SqlHandler($driver));
		foreach ($groups as $group) {
			$runner->addGroup($group);
		}
		$runner->run(Runner::MODE_CONTINUE);
	}


	/** @param list<string> $dependencies */
	private static function group(string $name, string $directory, array $dependencies): Group
	{
		$group = new Group();
		$group->name = $name;
		$group->enabled = true;
		$group->directory = $directory;
		$group->dependencies = $dependencies;

		return $group;
	}
}
