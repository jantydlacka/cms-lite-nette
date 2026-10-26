<?php

declare(strict_types=1);

namespace App;

use Nette\Bootstrap\Configurator;
use Nette\DI\Container;

final class Bootstrap
{
	private const ROOT = __DIR__ . '/..';


	public static function bootWebApplication(): Container
	{
		$debug = self::isDebugMode(getenv('NETTE_DEBUG'));
		$configurator = self::createConfigurator(self::ROOT . '/temp', $debug);
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


	public static function bootForTests(): Configurator
	{
		// NETTE_TESTER_THREAD is set by Nette Tester for parallel workers.
		$thread = getenv('NETTE_TESTER_THREAD');
		$configurator = self::createConfigurator(self::ROOT . '/temp/tests/' . ($thread === false ? 'main' : $thread), false);
		$configurator->addConfig(self::ROOT . '/config/test.neon');

		return $configurator;
	}


	private static function createConfigurator(string $tempDir, bool $debugMode): Configurator
	{
		$configurator = new Configurator();
		$configurator->setDebugMode($debugMode);
		$configurator->addStaticParameters([
			'demoData' => self::isDemoData(getenv('CMS_DEMO_DATA'), $debugMode),
		]);
		// Dynamic, so credentials stay out of the compiled container cache.
		$configurator->addDynamicParameters([
			'dbHost' => self::env('CMS_DB_HOST', '127.0.0.1'),
			'dbName' => self::env('CMS_DB_NAME', 'cms'),
			'dbUser' => self::env('CMS_DB_USER', 'root'),
			'dbPassword' => self::env('CMS_DB_PASSWORD', 'root'),
		]);
		$configurator->setTempDirectory($tempDir);
		$configurator->addConfig(self::ROOT . '/config/common.neon');
		$configurator->addConfig(self::ROOT . '/config/services.neon');

		$local = self::ROOT . '/config/local.neon';
		if (is_file($local)) {
			$configurator->addConfig($local);
		}

		return $configurator;
	}
}
