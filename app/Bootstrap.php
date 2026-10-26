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
		$configurator = self::createConfigurator(self::ROOT . '/temp');
		$configurator->setDebugMode(self::isDebugMode(getenv('NETTE_DEBUG')));
		$configurator->enableTracy(self::ROOT . '/log');

		return $configurator->createContainer();
	}


	/** Only the exact value '1' enables debug mode; unset or anything else means production. */
	public static function isDebugMode(string|false $env): bool
	{
		return $env === '1';
	}


	public static function bootForTests(): Configurator
	{
		// NETTE_TESTER_THREAD is set by Nette Tester for parallel workers.
		$thread = getenv('NETTE_TESTER_THREAD');
		$configurator = self::createConfigurator(self::ROOT . '/temp/tests/' . ($thread === false ? 'main' : $thread));
		$configurator->setDebugMode(false);
		$configurator->addConfig(self::ROOT . '/config/test.neon');

		return $configurator;
	}


	private static function createConfigurator(string $tempDir): Configurator
	{
		$configurator = new Configurator();
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
