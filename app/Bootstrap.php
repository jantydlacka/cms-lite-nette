<?php

declare(strict_types=1);

namespace App;

use App\Core\DI\AutoRefreshConfigurator;
use InvalidArgumentException;
use Nette\Bootstrap\Configurator;
use Nette\DI\Container;

final class Bootstrap
{
	public const TEST_DB_PREFIX = 'cms_test_';

	private const ROOT = __DIR__ . '/..';


	public static function bootWebApplication(): Container
	{
		$debug = self::isDebugMode(getenv('NETTE_DEBUG'));
		$configurator = self::createConfigurator(self::ROOT . '/temp', $debug, true);
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


	private static function env(string $name, string $default): string
	{
		$value = getenv($name);

		return $value === false || $value === '' ? $default : $value;
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
		$configurator = self::createConfigurator(self::ROOT . '/temp/tests/' . ($thread === false ? 'main' : $thread), false, false, true);
		$configurator->addConfig(self::ROOT . '/config/test.neon');
		// Forced, never read from CMS_DB_NAME: tests must not be able to reach the dev database.
		$configurator->addDynamicParameters(['dbName' => $database]);

		return $configurator;
	}


	private static function createConfigurator(string $tempDir, bool $debugMode, bool $withLocalConfig, bool $autoRefresh = false): Configurator
	{
		$configurator = $autoRefresh ? new AutoRefreshConfigurator() : new Configurator();
		$configurator->setDebugMode($debugMode);
		$configurator->addStaticParameters([
			'wwwDir' => self::ROOT . '/www',
			'demoData' => self::isDemoData(getenv('CMS_DEMO_DATA'), $debugMode),
		]);
		// Dynamic, so credentials stay out of the compiled container cache.
		$configurator->addDynamicParameters([
			'dbHost' => self::env('CMS_DB_HOST', '127.0.0.1'),
			'dbPort' => self::env('CMS_DB_PORT', '3306'),
			'dbName' => self::env('CMS_DB_NAME', 'cms'),
			'dbUser' => self::env('CMS_DB_USER', 'root'),
			'dbPassword' => self::env('CMS_DB_PASSWORD', 'root'),
		]);
		$configurator->setTempDirectory($tempDir);
		$configurator->addConfig(self::ROOT . '/config/common.neon');
		$configurator->addConfig(self::ROOT . '/config/services.neon');

		// Tests load config/test.neon only: a local override must not redirect them to another database.
		$local = self::ROOT . '/config/local.neon';
		if ($withLocalConfig && is_file($local)) {
			$configurator->addConfig($local);
		}

		return $configurator;
	}
}
