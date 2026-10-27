<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Bootstrap;
use Nette\Application\Application;
use Nette\DI\Container;
use Nette\Http\IResponse;
use Nette\Http\Request;
use Nette\Http\Session;
use Nette\Http\UrlScript;

/**
 * Runs the whole Nette\Application\Application (router, presenter, error presenter, response) for a URL.
 * Every request gets a fresh container, as in production; the thread database is prepared once in boot().
 */
final class HttpApplication
{
	private const BASE_URL = 'http://localhost';

	/** @var array<string, string> */
	private array $cookies = [];


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


	/**
	 * @param string $path absolute path with an optional query, e.g. '/o-nas?x=1'
	 * @param array<string, ?string> $headers null removes a default header
	 */
	public function get(string $path, array $headers = []): HttpResult
	{
		return $this->request('GET', $path, [], $headers);
	}


	/**
	 * @param array<string, mixed> $body
	 * @param array<string, ?string> $headers null removes a default header
	 */
	public function post(string $path, array $body, array $headers = []): HttpResult
	{
		return $this->request('POST', $path, $body, $headers);
	}


	/** Puts a cookie into the jar, as if the browser held it (Nette skips Helpers::initCookie in console mode). */
	public function setCookie(string $name, string $value): void
	{
		$this->cookies[$name] = $value;
	}


	/** @return array<string, string> the current jar */
	public function cookies(): array
	{
		return $this->cookies;
	}


	/** Forgets all cookies and the PHP session id: the next request comes from a different browser. */
	public function newBrowser(): void
	{
		$this->cookies = [];
		$this->closeNativeSession();
	}


	/**
	 * @param array<string, mixed> $body
	 * @param array<string, ?string> $headers
	 */
	private function request(string $method, string $path, array $body, array $headers): HttpResult
	{
		// Nette lower-cases header names, so merge on lower-cased keys or the caller's key would lose to the default.
		$headers = array_change_key_case($headers) + ['sec-fetch-site' => 'same-origin'];
		$headers = array_filter($headers, static fn (?string $value): bool => $value !== null);

		$container = self::createContainer($this->configs);
		$container->removeService('http.request');
		$container->addService('http.request', new Request(
			new UrlScript(self::BASE_URL . $path, '/'),
			post: $body,
			cookies: $this->cookies,
			headers: $headers,
			method: $method,
		));

		$this->closeNativeSession();

		ob_start();
		try {
			$container->getByType(Application::class)->run();
		} finally {
			$body = (string) ob_get_clean();
			$response = $container->getByType(IResponse::class);
			assert($response instanceof RecordingHttpResponse);
			$sessionId = $this->collect($container, $response);
		}

		$location = $response->getHeader('Location');

		return new HttpResult(
			$response->getCode(),
			$location !== null && str_starts_with($location, self::BASE_URL) ? substr($location, strlen(self::BASE_URL)) : $location,
			$body,
			$response->getHeaders(),
			$container,
			$sessionId,
		);
	}


	/** Updates the jar from the finished request and closes the session; returns the session id, if any. */
	private function collect(Container $container, RecordingHttpResponse $response): ?string
	{
		foreach ($response->getCookies() as $cookie) {
			if ($cookie['value'] === '') {
				unset($this->cookies[$cookie['name']]);
			} else {
				$this->cookies[$cookie['name']] = $cookie['value'];
			}
		}

		// PHP sends the session cookie itself (session_start, session_regenerate_id), bypassing IResponse.
		// The session is not read_and_close (not configured), but it is closed below, so the id is read first.
		$sessionId = session_id();
		$sessionId = $sessionId === false || $sessionId === '' ? null : $sessionId;
		if ($sessionId !== null) {
			$this->cookies[(string) session_name()] = $sessionId;
		}

		$session = $container->getByType(Session::class);
		if ($session->isStarted()) {
			$session->close();
		}

		$this->closeNativeSession();

		return $sessionId;
	}


	private function closeNativeSession(): void
	{
		if (session_status() === PHP_SESSION_ACTIVE) {
			session_write_close();
		}

		// Drop the id and data so the next session starts from the cookie only.
		session_id('');
		$_SESSION = [];
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
