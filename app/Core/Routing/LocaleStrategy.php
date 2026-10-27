<?php

declare(strict_types=1);

namespace App\Core\Routing;

/** How the language is told apart in a URL; the router asks only this. */
interface LocaleStrategy
{
	/** @return array<string, string> locale => route mask prefix ('' for the default locale) */
	public function prefixes(): array;


	/** Locale named by the first segment of a path (no query), or null when it names none. */
	public function localeOfPath(string $path): ?string;
}
