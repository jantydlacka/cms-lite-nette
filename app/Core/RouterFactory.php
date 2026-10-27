<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Routing\LocaleStrategy;
use App\Model\CmsConfig;
use App\Model\News\NewsSegments;
use App\Model\Page\ReservedSegments;
use Nette\Application\Routers\Route;
use Nette\Application\Routers\RouteList;

final class RouterFactory
{
	/** Same as the slug rule; the flat path strategy makes a path one slug. */
	private const PATH_PATTERN = '[a-z0-9]+(?:-[a-z0-9]+)*';

	/** Length of news_translation.slug. */
	private const SLUG_MAX_LENGTH = 100;

	/** Length of page_translation.path; a longer path cannot exist. */
	private const PATH_MAX_LENGTH = 500;


	public static function createRouter(LocaleStrategy $strategy, CmsConfig $config): RouteList
	{
		$prefixes = $strategy->prefixes();
		// A prefixed locale must be tried first: the unprefixed `<path>` would also match `en` and `en/`.
		uasort($prefixes, static fn (string $a, string $b): int => ($a === '') <=> ($b === ''));

		$router = new RouteList();
		// An underscore never occurs in a path, so this cannot shadow a page.
		$router->addRoute('_health', 'Health:default');
		// Before the pages; `admin` is a reserved segment. The locale is fixed, so links carry no `?locale=`.
		$router->addRoute(ReservedSegments::ADMIN, ['presenter' => 'Admin:Dashboard', 'action' => 'default', 'locale' => $config->defaultLocale]);
		$router->addRoute(ReservedSegments::ADMIN . '/sign-in', ['presenter' => 'Admin:Sign', 'action' => 'in', 'locale' => $config->defaultLocale]);
		$router->addRoute(ReservedSegments::ADMIN . '/pages', ['presenter' => 'Admin:Pages', 'action' => 'default', 'locale' => $config->defaultLocale]);
		$router->addRoute(ReservedSegments::ADMIN . '/pages/new', ['presenter' => 'Admin:Pages', 'action' => 'new', 'locale' => $config->defaultLocale]);
		// Before the detail: its optional `<lang>` would take `delete`. The tab language is `lang`; `locale` stays the default.
		$router->addRoute(ReservedSegments::ADMIN . '/pages/<id \d+>/delete', ['presenter' => 'Admin:Pages', 'action' => 'delete', 'locale' => $config->defaultLocale]);
		$router->addRoute(ReservedSegments::ADMIN . '/pages/<id \d+>[/<lang>]', ['presenter' => 'Admin:Pages', 'action' => 'edit', 'locale' => $config->defaultLocale]);
		$router->addRoute(ReservedSegments::ADMIN . '/news ? page=<page \d+>', ['presenter' => 'Admin:News', 'action' => 'default', 'locale' => $config->defaultLocale, 'page' => 1]);
		$router->addRoute(ReservedSegments::ADMIN . '/news/new', ['presenter' => 'Admin:News', 'action' => 'new', 'locale' => $config->defaultLocale]);
		foreach ($prefixes as $locale => $prefix) {
			// Route masks match with an optional trailing slash; Presenter canonicalization turns that into a 301.
			$router->addRoute($prefix, [
				'presenter' => 'Front:Home',
				'action' => 'default',
				'locale' => $locale,
			]);
			// Before `<path>`, which would take the segment for a page path.
			['segment' => $segment, 'pageParameter' => $pageParameter] = NewsSegments::forLocale($locale);
			$router->addRoute($prefix . $segment . ' ? ' . $pageParameter . '=<page \d+>', [
				'presenter' => 'Front:News',
				'action' => 'default',
				'locale' => $locale,
				'page' => 1,
			]);
			$router->addRoute($prefix . $segment . '/<slug>', [
				'presenter' => 'Front:News',
				'action' => 'detail',
				'locale' => $locale,
				'slug' => [
					Route::Pattern => self::PATH_PATTERN,
					Route::FilterIn => static fn (string $slug): ?string => strlen($slug) <= self::SLUG_MAX_LENGTH ? $slug : null,
				],
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
