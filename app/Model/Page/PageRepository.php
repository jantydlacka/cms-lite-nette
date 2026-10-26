<?php

declare(strict_types=1);

namespace App\Model\Page;

interface PageRepository
{
	public function findPublishedByPath(string $locale, string $path): ?PageTranslation;


	/** Any publication status. */
	public function findTranslation(int $pageId, string $locale): ?PageTranslation;


	public function getPage(int $pageId): ?Page;


	/**
	 * Pages flagged for the menu and published in $locale, ordered by position then id.
	 * A page is shown only if its parent is shown too.
	 *
	 * @return list<MenuItem>
	 */
	public function findMenu(string $locale): array;


	/**
	 * From the root down to the page itself, without children. Empty when the page
	 * or any ancestor has no published translation in $locale.
	 *
	 * @return list<MenuItem>
	 */
	public function findBreadcrumbs(int $pageId, string $locale): array;


	public function createPage(?int $parentId, int $position, bool $inMenu): int;


	/** Inserts or updates by (pageId, locale); updatedAt of the argument is ignored, the clock is used. */
	public function saveTranslation(PageTranslation $translation): void;


	public function isPathTaken(string $locale, string $path, ?int $exceptPageId): bool;
}
