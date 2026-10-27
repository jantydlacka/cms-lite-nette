<?php

declare(strict_types=1);

namespace Tests\Model\Page;

use App\Model\Page\Page;
use App\Model\Page\PageRepository;
use App\Model\Page\PageTranslation;
use ArrayObject;

/** Forwards to a real repository and appends every called method name to $log. */
final class RecordingPageRepository implements PageRepository
{
	/** @param ArrayObject<int, string> $log */
	public function __construct(private readonly PageRepository $inner, private readonly ArrayObject $log)
	{
	}


	public function findPublishedByPath(string $locale, string $path): ?PageTranslation
	{
		$this->log[] = __FUNCTION__;

		return $this->inner->findPublishedByPath($locale, $path);
	}


	public function findTranslation(int $pageId, string $locale): ?PageTranslation
	{
		$this->log[] = __FUNCTION__;

		return $this->inner->findTranslation($pageId, $locale);
	}


	public function lockTranslation(int $pageId, string $locale): ?PageTranslation
	{
		$this->log[] = __FUNCTION__;

		return $this->inner->lockTranslation($pageId, $locale);
	}


	public function getPage(int $pageId): ?Page
	{
		$this->log[] = __FUNCTION__;

		return $this->inner->getPage($pageId);
	}


	/** {@inheritDoc} */
	public function findChildren(?int $parentId): array
	{
		$this->log[] = __FUNCTION__;

		return $this->inner->findChildren($parentId);
	}


	/** {@inheritDoc} */
	public function findMenu(string $locale): array
	{
		$this->log[] = __FUNCTION__;

		return $this->inner->findMenu($locale);
	}


	/** {@inheritDoc} */
	public function findBreadcrumbs(int $pageId, string $locale): array
	{
		$this->log[] = __FUNCTION__;

		return $this->inner->findBreadcrumbs($pageId, $locale);
	}


	public function createPage(?int $parentId, int $position, bool $inMenu): int
	{
		$this->log[] = __FUNCTION__;

		return $this->inner->createPage($parentId, $position, $inMenu);
	}


	public function saveTranslation(PageTranslation $translation): void
	{
		$this->log[] = __FUNCTION__;
		$this->inner->saveTranslation($translation);
	}


	public function isPathTaken(string $locale, string $path, ?int $exceptPageId): bool
	{
		$this->log[] = __FUNCTION__;

		return $this->inner->isPathTaken($locale, $path, $exceptPageId);
	}


	public function lockPage(int $pageId): ?Page
	{
		$this->log[] = __FUNCTION__;

		return $this->inner->lockPage($pageId);
	}


	public function updatePage(Page $page): void
	{
		$this->log[] = __FUNCTION__;
		$this->inner->updatePage($page);
	}


	/** {@inheritDoc} */
	public function setPositions(array $positionsById): void
	{
		$this->log[] = __FUNCTION__;
		$this->inner->setPositions($positionsById);
	}


	public function deletePage(int $pageId): void
	{
		$this->log[] = __FUNCTION__;
		$this->inner->deletePage($pageId);
	}


	/** {@inheritDoc} */
	public function findTree(string $locale): array
	{
		$this->log[] = __FUNCTION__;

		return $this->inner->findTree($locale);
	}
}
