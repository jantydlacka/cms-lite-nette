<?php

declare(strict_types=1);

namespace Tests\Model\Page;

use App\Model\Page\Page;
use App\Model\Page\PageRepository;
use App\Model\Page\PageTranslation;
use Closure;

/** Forwards to a real repository; $beforeSave runs ahead of every saveTranslation (it may throw), $mapPage rewrites what getPage returns. */
final class DelegatingPageRepository implements PageRepository
{
	/**
	 * @param Closure(): void $beforeSave
	 * @param (Closure(Page): Page)|null $mapPage
	 */
	public function __construct(
		private readonly PageRepository $inner,
		private readonly Closure $beforeSave,
		private readonly ?Closure $mapPage = null,
	) {
	}


	public function findPublishedByPath(string $locale, string $path): ?PageTranslation
	{
		return $this->inner->findPublishedByPath($locale, $path);
	}


	public function findTranslation(int $pageId, string $locale): ?PageTranslation
	{
		return $this->inner->findTranslation($pageId, $locale);
	}


	public function lockTranslation(int $pageId, string $locale): ?PageTranslation
	{
		return $this->inner->lockTranslation($pageId, $locale);
	}


	public function getPage(int $pageId): ?Page
	{
		$page = $this->inner->getPage($pageId);

		return $page === null || $this->mapPage === null ? $page : ($this->mapPage)($page);
	}


	/** {@inheritDoc} */
	public function findChildren(?int $parentId): array
	{
		return $this->inner->findChildren($parentId);
	}


	/** {@inheritDoc} */
	public function findMenu(string $locale): array
	{
		return $this->inner->findMenu($locale);
	}


	/** {@inheritDoc} */
	public function findBreadcrumbs(int $pageId, string $locale): array
	{
		return $this->inner->findBreadcrumbs($pageId, $locale);
	}


	public function createPage(?int $parentId, int $position, bool $inMenu): int
	{
		return $this->inner->createPage($parentId, $position, $inMenu);
	}


	public function saveTranslation(PageTranslation $translation): void
	{
		($this->beforeSave)();
		$this->inner->saveTranslation($translation);
	}


	public function isPathTaken(string $locale, string $path, ?int $exceptPageId): bool
	{
		return $this->inner->isPathTaken($locale, $path, $exceptPageId);
	}


	public function lockPage(int $pageId): ?Page
	{
		return $this->inner->lockPage($pageId);
	}


	public function updatePage(Page $page): void
	{
		$this->inner->updatePage($page);
	}


	/** {@inheritDoc} */
	public function setPositions(array $positionsById): void
	{
		$this->inner->setPositions($positionsById);
	}


	/** {@inheritDoc} */
	public function findTree(string $locale): array
	{
		return $this->inner->findTree($locale);
	}
}
