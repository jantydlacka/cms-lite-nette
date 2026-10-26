<?php

declare(strict_types=1);

use App\Bootstrap;
use Nette\Bootstrap\Configurator;
use Nette\Database\Connection;
use Tester\Assert;
use Tests\Support\TestDatabase;
use function Tests\container;

require __DIR__ . '/../bootstrap.php';

test('database name is derived from the tester thread', function (): void {
	Assert::same('cms_test_3', TestDatabase::name('3'));
	Assert::same('cms_test_0', TestDatabase::name(null));
});


test('database name rejects non-numeric threads', function (): void {
	Assert::exception(static fn () => TestDatabase::name('1`; DROP DATABASE cms; --'), InvalidArgumentException::class);
});


/**
 * @param array<string, string> $vars
 * @param callable(): void $body
 */
function withEnv(array $vars, callable $body): void
{
	$previous = [];
	foreach ($vars as $name => $value) {
		$previous[$name] = getenv($name);
		putenv($name . '=' . $value);
	}
	try {
		$body();
	} finally {
		foreach ($previous as $name => $old) {
			putenv($old === false ? $name : $name . '=' . $old);
		}
	}
}


test('container is bound to the thread database even when CMS_DB_NAME points at the dev database', function (): void {
	// Thread 99 is outside the -j 4 pool, so no real worker's database is touched.
	withEnv(['NETTE_TESTER_THREAD' => '99', 'CMS_DB_NAME' => 'cms'], function (): void {
		$connection = container()->getByType(Connection::class);

		Assert::same('cms_test_99', $connection->fetchField('SELECT DATABASE()'));
	});
});


test('bootForTests rejects a non-numeric tester thread', function (): void {
	withEnv(['NETTE_TESTER_THREAD' => 'abc'], function (): void {
		Assert::exception(static fn () => Bootstrap::bootForTests(), InvalidArgumentException::class);
	});
});

$fixtures = [__DIR__ . '/fixtures/basic-data'];
$container = container();
$connection = $container->getByType(Connection::class);

test('prepare() empties tables and reloads basic data', function () use ($container, $connection, $fixtures): void {
	TestDatabase::prepare($container, $fixtures);
	Assert::same(1, $connection->fetchField("SELECT COUNT(*) FROM setting WHERE `key` = 'test.marker'"));

	$connection->query("INSERT INTO setting (`key`, `locale`, `value`) VALUES ('scratch', '', 'x')");
	TestDatabase::prepare($container);
	Assert::same(0, $connection->fetchField("SELECT COUNT(*) FROM setting WHERE `key` = 'scratch'"));
	Assert::same(0, $connection->fetchField('SELECT COUNT(*) FROM setting'));

	TestDatabase::prepare($container, $fixtures);
	Assert::same(1, $connection->fetchField('SELECT COUNT(*) FROM setting'));
});


test('prepare() refuses a container bound to a non-test database and truncates nothing', function () use ($connection): void {
	$probe = 'zz_guard_probe';
	$connection->query('DROP DATABASE IF EXISTS ?name', $probe);
	$connection->query('CREATE DATABASE ?name', $probe);
	$connection->query('CREATE TABLE ?name.t (id INT)', $probe);
	$connection->query('INSERT INTO ?name.t VALUES (1)', $probe);

	try {
		$configurator = Bootstrap::bootForTests();
		$configurator->addDynamicParameters(['dbName' => $probe]);
		Assert::exception(
			static fn () => TestDatabase::prepare($configurator->createContainer()),
			LogicException::class,
		);
		Assert::same(1, $connection->fetchField('SELECT COUNT(*) FROM ?name.t', $probe));
	} finally {
		$connection->query('DROP DATABASE ?name', $probe);
	}
});


test('a failing basic-data fixture surfaces as an exception', function () use ($container): void {
	Assert::exception(
		static fn () => TestDatabase::prepare($container, [__DIR__ . '/fixtures/broken-basic-data']),
		Throwable::class,
	);
	TestDatabase::prepare($container);
});


test('bootForTests loads test.neon and never config/local.neon', function (): void {
	$configs = (new ReflectionProperty(Configurator::class, 'configs'))->getValue(Bootstrap::bootForTests());
	assert(is_array($configs));
	$files = array_map(static fn (mixed $c): string => is_string($c) ? basename($c) : '?', $configs);
	Assert::contains('test.neon', $files);
	Assert::false(in_array('local.neon', $files, true));
});


test('prepare() refuses when the actual connection is outside the test prefix, truncating nothing', function () use ($connection): void {
	$probe = 'zz_guard_probe2';
	$connection->query('DROP DATABASE IF EXISTS ?name', $probe);
	$connection->query('CREATE DATABASE ?name', $probe);
	$connection->query('CREATE TABLE ?name.setting (id INT)', $probe);
	$connection->query('INSERT INTO ?name.setting VALUES (1)', $probe);

	try {
		$probeContainer = container();
		$probeConnection = $probeContainer->getByType(Connection::class);
		// The configured name is a valid test name; only the live connection points elsewhere.
		$probeConnection->query('USE ?name', $probe);
		Assert::same($probe, $probeConnection->fetchField('SELECT DATABASE()'));
		Assert::exception(static fn () => TestDatabase::prepare($probeContainer), LogicException::class);
		Assert::same(1, $probeConnection->fetchField('SELECT COUNT(*) FROM setting'));
	} finally {
		$connection->query('DROP DATABASE ?name', $probe);
	}
});


test('prepare() rebuilds a database whose migration checksum no longer matches', function () use ($container, $connection, $fixtures): void {
	TestDatabase::prepare($container);
	$connection->query("UPDATE migrations SET checksum = 'stale' WHERE `group` = 'structures'");

	TestDatabase::prepare($container, $fixtures);

	Assert::same(0, $connection->fetchField("SELECT COUNT(*) FROM migrations WHERE checksum = 'stale'"));
	Assert::same(1, $connection->fetchField("SELECT COUNT(*) FROM setting WHERE `key` = 'test.marker'"));
	$connection->query("INSERT INTO setting (`key`, `locale`, `value`) VALUES ('after-heal', '', 'x')");
	Assert::same(2, $connection->fetchField('SELECT COUNT(*) FROM setting'));
	TestDatabase::prepare($container);
});


test('prepare() resets AUTO_INCREMENT even when the rows were already deleted', function () use ($container, $connection): void {
	TestDatabase::prepare($container);
	$connection->query("INSERT INTO page (created_at, updated_at) VALUES (NOW(), NOW())");
	$connection->query('DELETE FROM page');

	TestDatabase::prepare($container);
	$connection->query("INSERT INTO page (created_at, updated_at) VALUES (NOW(), NOW())");
	Assert::same(1, $connection->fetchField('SELECT MIN(id) FROM page'));
	TestDatabase::prepare($container);
});
