<?php

declare(strict_types=1);

namespace App\Model\Page;

interface PageRepository
{
	public function findPublishedByPath(string $locale, string $path): ?PageTranslation;


	/** Any publication status. */
	public function findTranslation(int $pageId, string $locale): ?PageTranslation;


	/** Must run inside a transaction: takes a row lock (SELECT ... FOR UPDATE) on (pageId, locale). Any publication status. */
	public function lockTranslation(int $pageId, string $locale): ?PageTranslation;


	public function getPage(int $pageId): ?Page;


	/**
	 * Direct children ordered by position then id; null gives the top-level pages.
	 *
	 * @return list<Page>
	 */
	public function findChildren(?int $parentId): array;


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


	/**
	 * Inserts or updates by (pageId, locale) and stores updatedAt of the argument; the version argument is ignored (the store counts).
	 *
	 * @throws InvalidSlugException when the (locale, path) pair belongs to another page
	 */
	public function saveTranslation(PageTranslation $translation): void;


	public function isPathTaken(string $locale, string $path, ?int $exceptPageId): bool;


	/** Must run inside a transaction: takes a row lock (SELECT ... FOR UPDATE) on the page. */
	public function lockPage(int $pageId): ?Page;


	/** Writes parentId, position and inMenu; the version argument is ignored, the stored one goes up by 1. */
	public function updatePage(Page $page): void;


	/**
	 * Leaves the version alone.
	 *
	 * @param array<int, int> $positionsById
	 */
	public function setPositions(array $positionsById): void;


	/** Writes parentId and position; leaves the menu flag and the version alone. */
	public function setParent(int $pageId, ?int $parentId, int $position): void;


	/**
	 * All pages in tree order: a parent before its children, siblings by position then id.
	 * A cycle in parent_id is an UnexpectedValueException.
	 *
	 * @return list<PageTreeItem>
	 */
	public function findTree(string $locale): array;


	/** Translations go with the page (ON DELETE CASCADE); a page with children is refused by the database (ON DELETE RESTRICT). */
	public function deletePage(int $pageId): void;
}
