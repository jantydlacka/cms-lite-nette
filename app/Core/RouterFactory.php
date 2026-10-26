<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Routing\LocaleStrategy;
use Nette\Application\Routers\Route;
use Nette\Application\Routers\RouteList;

final class RouterFactory
{
	/** Same as the slug rule; the flat path strategy makes a path one slug. */
	private const PATH_PATTERN = '[a-z0-9]+(?:-[a-z0-9]+)*';

	/** Length of page_translation.path; a longer path cannot exist. */
	private const PATH_MAX_LENGTH = 500;


	public static function createRouter(LocaleStrategy $strategy): RouteList
	{
		$prefixes = $strategy->prefixes();
		// A prefixed locale must be tried first: the unprefixed `<path>` would also match `en` and `en/`.
		uasort($prefixes, static fn (string $a, string $b): int => ($a === '') <=> ($b === ''));

		$router = new RouteList();
		// An underscore never occurs in a path, so this cannot shadow a page.
		$router->addRoute('_health', 'Health:default');
		foreach ($prefixes as $locale => $prefix) {
			// Route masks match with an optional trailing slash; Presenter canonicalization turns that into a 301.
			$router->addRoute($prefix, [
				'presenter' => 'Front:Home',
				'action' => 'default',
				'locale' => $locale,
			]);
			$router->addRoute($prefix . '<path>', [
				'presenter' => 'Front:Page',
				'action' => 'default',
				'locale' => $locale,
				'path' => [
					Route::Pattern => self::PATH_PATTERN,
					Route::FilterIn => static fn (string $path): ?string => strlen($path) <= self::PATH_MAX_LENGTH ? $path : null,
				],
			]);
		}

		return $router;
	}
}
