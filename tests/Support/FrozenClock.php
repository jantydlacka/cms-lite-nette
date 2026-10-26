<?php

declare(strict_types=1);

namespace Tests\Support;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

final class FrozenClock implements ClockInterface
{
	public function __construct(private DateTimeImmutable $now)
	{
	}


	public function now(): DateTimeImmutable
	{
		return $this->now;
	}


	public function set(DateTimeImmutable $now): void
	{
		$this->now = $now;
	}


	// An invalid modifier throws (PHP 8.3+); the time is never left silently unchanged.
	public function modify(string $modifier): void
	{
		$this->now = $this->now->modify($modifier);
	}
}
