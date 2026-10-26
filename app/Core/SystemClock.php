<?php

declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface;

/** Stores and compares in UTC; conversion to the site's timezone belongs to presentation. */
final class SystemClock implements ClockInterface
{
	public function now(): DateTimeImmutable
	{
		return new DateTimeImmutable('now', new DateTimeZone('UTC'));
	}
}
