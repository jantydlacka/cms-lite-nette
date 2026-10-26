<?php

declare(strict_types=1);

namespace Tests;

use Tracy\ILogger;

final class FakeLogger implements ILogger
{
	/** @var list<array{mixed, string}> */
	public array $records = [];


	public function log(mixed $value, string $level = self::INFO): void
	{
		$this->records[] = [$value, $level];
	}
}
