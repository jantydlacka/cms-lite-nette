<?php

declare(strict_types=1);

namespace App\Core\Routing;

/** How the language is told apart in a URL; the router asks only this. */
interface LocaleStrategy
{
	/** @return array<string, string> locale => route mask prefix ('' for the default locale) */
	public function prefixes(): array;
}
