<?php

declare(strict_types=1);

namespace Tests\Model\Page;

use App\Model\Page\Page;
use App\Model\Page\PageRepository;
use App\Model\Page\PageTranslation;
use Closure;

/** Forwards to a real repository; $beforeSave runs ahead of every saveTranslation (it may throw). */
final class DelegatingPageRepository implements PageRepository
{
	/** @param Closure(): void $beforeSave */
	public function __construct(private readonly PageRepository $inner, private readonly Closure $beforeSave)
	{
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
		return $this->inner->getPage($pageId);
	}


	/** {@inheritDoc} */
	public function findChildren(int $pageId): array
	{
		return $this->inner->findChildren($pageId);
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
}
