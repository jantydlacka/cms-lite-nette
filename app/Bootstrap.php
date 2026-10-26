<?php

declare(strict_types=1);

namespace App;

use InvalidArgumentException;
use Nette\Bootstrap\Configurator;
use Nette\DI\Container;
use Nette\Utils\Finder;
use RuntimeException;

final class Bootstrap
{
	public const TEST_DB_PREFIX = 'cms_test_';

	private const ROOT = __DIR__ . '/..';

	private const DB_ENV = [
		'dbHost' => ['CMS_DB_HOST', '127.0.0.1'],
		'dbPort' => ['CMS_DB_PORT', '3306'],
		'dbName' => ['CMS_DB_NAME', 'cms'],
		'dbUser' => ['CMS_DB_USER', 'root'],
		'dbPassword' => ['CMS_DB_PASSWORD', 'root'],
	];


	public static function bootWebApplication(): Container
	{
		$debug = self::isDebugMode(getenv('NETTE_DEBUG'));
		$configurator = self::createConfigurator(self::ROOT . '/temp', $debug, true, allowDatabaseDefaults: $debug);
		$configurator->enableTracy(self::ROOT . '/log');

		return $configurator->createContainer();
	}


	/** Only the exact value '1' enables debug mode; unset or anything else means production. */
	public static function isDebugMode(string|false $env): bool
	{
		return $env === '1';
	}


	/** CMS_DEMO_DATA '1' enables, any other set value disables; unset follows debug mode. */
	public static function isDemoData(string|false $env, bool $debugMode): bool
	{
		return $env === false ? $debugMode : $env === '1';
	}


	/**
	 * Database connection parameters from CMS_DB_* variables. Unset or empty variables take the
	 * development defaults only when allowed (debug mode, tests); otherwise boot fails.
	 *
	 * @param array<string, string|false> $env
	 * @return array<string, string> dbHost, dbPort, dbName, dbUser, dbPassword
	 */
	public static function databaseParameters(array $env, bool $allowDefaults): array
	{
		$parameters = [];
		$missing = [];
		foreach (self::DB_ENV as $parameter => [$name, $default]) {
			$value = $env[$name] ?? false;
			if ($value === false || $value === '') {
				$missing[] = $name;
				$value = $default;
			}
			$parameters[$parameter] = $value;
		}

		if ($missing !== [] && !$allowDefaults) {
			throw new RuntimeException('Database is not configured; set these environment variables (defaults apply only in debug mode): ' . implode(', ', $missing) . '.');
		}

		return $parameters;
	}


	/** Name of the database for a Nette Tester thread (false = not running under parallel workers). */
	public static function testDatabaseName(string|false $thread): string
	{
		if ($thread !== false && preg_match('~^\d+$~', $thread) !== 1) {
			throw new InvalidArgumentException('Tester thread must be numeric.');
		}

		return self::TEST_DB_PREFIX . ($thread === false ? '0' : $thread);
	}


	public static function bootForTests(): Configurator
	{
		// NETTE_TESTER_THREAD is set by Nette Tester for parallel workers.
		$thread = getenv('NETTE_TESTER_THREAD');
		$database = self::testDatabaseName($thread);
		$configurator = self::createConfigurator(self::ROOT . '/temp/tests/' . ($thread === false ? 'main' : $thread), false, false, allowDatabaseDefaults: true);
		$configurator->addConfig(self::ROOT . '/config/test.neon');
		// Tests run with debug off, so the container is never auto-refreshed; static parameters are part of its cache key.
		$configurator->addStaticParameters(['testContainerFingerprint' => self::testContainerFingerprint(self::ROOT)]);
		// Forced, never read from CMS_DB_NAME: tests must not be able to reach the dev database.
		$configurator->addDynamicParameters(['dbName' => $database]);

		return $configurator;
	}


	/**
	 * Hash of config/lang contents and of path + mtime + size of every app/ PHP and Latte file.
	 * Code is keyed by mtime and size, so an edit that keeps both unchanged within the same second is not detected.
	 */
	public static function testContainerFingerprint(string $rootDir): string
	{
		$parts = [];
		foreach (['config', 'lang'] as $dir) {
			foreach (self::sortedFiles($rootDir . '/' . $dir, '*.neon') as $path) {
				$parts[] = substr($path, strlen($rootDir)) . ':' . hash_file('xxh128', $path);
			}
		}
		foreach ([...self::sortedFiles($rootDir . '/app', '*.php', true), ...self::sortedFiles($rootDir . '/app', '*.latte', true)] as $path) {
			$parts[] = substr($path, strlen($rootDir)) . ':' . filemtime($path) . ':' . filesize($path);
		}

		return hash('xxh128', implode("\n", $parts));
	}


	/** @return list<string> */
	private static function sortedFiles(string $dir, string $mask, bool $recursive = false): array
	{
		if (!is_dir($dir)) {
			return [];
		}
		$finder = Finder::findFiles($mask);
		$files = array_map(
			static fn ($file): string => $file->getPathname(),
			iterator_to_array($recursive ? $finder->from($dir) : $finder->in($dir), false),
		);
		sort($files);

		return $files;
	}


	private static function createConfigurator(string $tempDir, bool $debugMode, bool $withLocalConfig, bool $allowDatabaseDefaults): Configurator
	{
		$configurator = new Configurator();
		$configurator->setDebugMode($debugMode);
		$demoData = self::isDemoData(getenv('CMS_DEMO_DATA'), $debugMode);
		$configurator->addStaticParameters([
			'wwwDir' => self::ROOT . '/www',
			'demoData' => $demoData,
		]);
		// Dynamic, so credentials stay out of the compiled container cache.
		$env = [];
		foreach (self::DB_ENV as [$name]) {
			$env[$name] = getenv($name);
		}
		$configurator->addDynamicParameters(self::databaseParameters($env, $allowDatabaseDefaults));
		$configurator->setTempDirectory($tempDir);
		$configurator->addConfig(self::ROOT . '/config/common.neon');
		$configurator->addConfig(self::ROOT . '/config/services.neon');
		// Demo pages include an English translation, so the demo needs the second language enabled.
		if ($demoData) {
			$configurator->addConfig(self::ROOT . '/config/demo.neon');
		}

		// Tests load config/test.neon only: a local override must not redirect them to another database.
		$local = self::ROOT . '/config/local.neon';
		if ($withLocalConfig && is_file($local)) {
			$configurator->addConfig($local);
		}

		return $configurator;
	}
}
