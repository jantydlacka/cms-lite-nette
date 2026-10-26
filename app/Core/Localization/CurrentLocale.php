<?php

declare(strict_types=1);

namespace App\Core\Localization;

use App\Model\CmsConfig;
use InvalidArgumentException;

final class CurrentLocale
{
	private string $locale;


	public function __construct(
		private readonly CmsConfig $config,
	) {
		$this->locale = $config->defaultLocale;
	}


	public function get(): string
	{
		return $this->locale;
	}


	public function set(string $locale): void
	{
		if (!in_array($locale, $this->config->locales, true)) {
			throw new InvalidArgumentException("Locale '$locale' is not configured.");
		}

		$this->locale = $locale;
	}
}
