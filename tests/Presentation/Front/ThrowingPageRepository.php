<?php

declare(strict_types=1);

namespace Tests\Presentation\Front;

use App\Model\Page\Page;
use App\Model\Page\PageRepository;
use App\Model\Page\PageTranslation;
use RuntimeException;

/** Stands in for an unreachable database. */
final class ThrowingPageRepository implements PageRepository
{
	public function findPublishedByPath(string $locale, string $path): ?PageTranslation
	{
		throw self::down();
	}


	public function findTranslation(int $pageId, string $locale): ?PageTranslation
	{
		throw self::down();
	}


	public function lockTranslation(int $pageId, string $locale): ?PageTranslation
	{
		throw self::down();
	}


	public function getPage(int $pageId): ?Page
	{
		throw self::down();
	}


	/** {@inheritDoc} */
	public function findChildren(?int $parentId): array
	{
		throw self::down();
	}


	/** {@inheritDoc} */
	public function findMenu(string $locale): array
	{
		throw self::down();
	}


	/** {@inheritDoc} */
	public function findBreadcrumbs(int $pageId, string $locale): array
	{
		throw self::down();
	}


	public function createPage(?int $parentId, int $position, bool $inMenu): int
	{
		throw self::down();
	}


	public function saveTranslation(PageTranslation $translation): void
	{
		throw self::down();
	}


	public function isPathTaken(string $locale, string $path, ?int $exceptPageId): bool
	{
		throw self::down();
	}


	public function setParent(int $pageId, ?int $parentId, int $position): void
	{
		throw self::down();
	}


	public function lockPage(int $pageId): ?Page
	{
		throw self::down();
	}


	public function updatePage(Page $page): void
	{
		throw self::down();
	}


	/** {@inheritDoc} */
	public function setPositions(array $positionsById): void
	{
		throw self::down();
	}


	public function deletePage(int $pageId): void
	{
		throw self::down();
	}


	/** {@inheritDoc} */
	public function findTree(string $locale): array
	{
		throw self::down();
	}


	private static function down(): RuntimeException
	{
		return new RuntimeException('Database is down.');
	}
}
