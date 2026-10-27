<?php

declare(strict_types=1);

namespace App\Model;

final readonly class CmsConfig
{
	/**
	 * @param list<string> $locales
	 */
	public function __construct(
		public string $siteName,
		public string $defaultLocale,
		public array $locales,
		public int $newsPerPage,
		/** The administration warns that accounts with a known password exist. */
		public bool $demoData = false,
		public string $timezone = 'Europe/Prague',
		public bool $newsInMenu = true,
	) {
	}
}
