<?php

declare(strict_types=1);

namespace Tests\Support;

use Nette\DI\Container;
use Tester\DomQuery;

final readonly class HttpResult
{
	public function __construct(
		public int $code,
		public ?string $location,
		public string $body,
		/** @var array<string, string> lower-cased name => value */
		public array $headers,
		/** The container that served the request (logger, services). */
		public Container $container,
	) {
	}


	public function header(string $name): ?string
	{
		return $this->headers[strtolower($name)] ?? null;
	}


	public function dom(): DomQuery
	{
		return DomQuery::fromHtml($this->body);
	}


	/** Whitespace-normalised text of the first element with the test id; null when there is none. */
	public function text(string $testId): ?string
	{
		$found = $this->dom()->find('[data-testid="' . $testId . '"]');
		if ($found === []) {
			return null;
		}

		return trim((string) preg_replace('~\s+~u', ' ', dom_import_simplexml($found[0])->textContent));
	}
}
