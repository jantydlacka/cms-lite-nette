<?php

declare(strict_types=1);

namespace App\Core\Routing;

use App\Model\CmsConfig;

/** Default locale without a prefix, every other locale under `<locale>/`. */
final class PrefixLocaleStrategy implements LocaleStrategy
{
	public function __construct(
		private readonly CmsConfig $config,
	) {
	}


	/** {@inheritDoc} */
	public function prefixes(): array
	{
		$prefixes = [];
		foreach ($this->config->locales as $locale) {
			$prefixes[$locale] = $locale === $this->config->defaultLocale ? '' : $locale . '/';
		}

		return $prefixes;
	}


	public function localeOfPath(string $path): ?string
	{
		$first = explode('/', ltrim($path, '/'), 2)[0];
		foreach ($this->prefixes() as $locale => $prefix) {
			if ($prefix !== '' && $first === $locale) {
				return $locale;
			}
		}

		return null;
	}
}
