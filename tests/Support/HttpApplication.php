<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Bootstrap;
use Nette\Application\Application;
use Nette\DI\Container;
use Nette\Http\IResponse;
use Nette\Http\Request;
use Nette\Http\UrlScript;

/**
 * Runs the whole Nette\Application\Application (router, presenter, error presenter, response) for a URL.
 * Every request gets a fresh container, as in production; the thread database is prepared once in boot().
 */
final class HttpApplication
{
	private const BASE_URL = 'http://localhost';


	/** @param list<string|array<string, mixed>> $configs */
	private function __construct(
		private readonly array $configs,
		public readonly Container $container,
	) {
	}


	/**
	 * Prepares the thread database; seed data through $container.
	 *
	 * @param list<string|array<string, mixed>> $configs NEON files or config arrays loaded after config/test.neon
	 */
	public static function boot(array $configs = []): self
	{
		$container = self::createContainer($configs);
		TestDatabase::prepare($container);

		return new self($configs, $container);
	}


	/** @param string $path absolute path with an optional query, e.g. '/o-nas?x=1' */
	public function get(string $path): HttpResult
	{
		$container = self::createContainer($this->configs);
		$container->removeService('http.request');
		$container->addService('http.request', new Request(new UrlScript(self::BASE_URL . $path, '/'), method: 'GET'));

		ob_start();
		try {
			$container->getByType(Application::class)->run();
		} finally {
			$body = (string) ob_get_clean();
		}

		$response = $container->getByType(IResponse::class);
		assert($response instanceof RecordingHttpResponse);
		$location = $response->getHeader('Location');

		return new HttpResult(
			$response->getCode(),
			$location !== null && str_starts_with($location, self::BASE_URL) ? substr($location, strlen(self::BASE_URL)) : $location,
			$body,
			$response->getHeaders(),
			$container,
		);
	}


	/** @param list<string|array<string, mixed>> $configs */
	private static function createContainer(array $configs): Container
	{
		$configurator = Bootstrap::bootForTests();
		// header() is not observable under the CLI, so status and headers are recorded in memory.
		$configurator->addConfig(['services' => ['http.response' => ['create' => RecordingHttpResponse::class]]]);
		foreach ($configs as $config) {
			$configurator->addConfig($config);
		}

		return $configurator->createContainer();
	}
}
