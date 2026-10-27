<?php

declare(strict_types=1);

namespace App\Core\Localization;

use App\Model\CmsConfig;
use DateTimeImmutable;
use DateTimeZone;

/** The only place that converts between stored UTC and the site's time zone. */
final class SiteTime
{
	private readonly DateTimeZone $zone;


	public function __construct(CmsConfig $config)
	{
		$this->zone = new DateTimeZone($config->timezone);
	}


	public function zone(): DateTimeZone
	{
		return $this->zone;
	}


	public function toSite(DateTimeImmutable $moment): DateTimeImmutable
	{
		return $moment->setTimezone($this->zone);
	}


	/**
	 * Wall time of the form value read in the site zone, returned in UTC.
	 * Only `Y-m-d H:i:s` of the argument is used: the form control labels the typed time as UTC.
	 * PHP resolves a skipped local time forward and an ambiguous one to the second occurrence.
	 */
	public function fromInput(DateTimeImmutable $wallTime): DateTimeImmutable
	{
		return (new DateTimeImmutable($wallTime->format('Y-m-d H:i:s'), $this->zone))
			->setTimezone(new DateTimeZone('UTC'));
	}
}
