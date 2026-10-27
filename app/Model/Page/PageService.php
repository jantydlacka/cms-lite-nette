<?php

declare(strict_types=1);

namespace App\Model\Page;

use App\Model\CmsConfig;
use App\Model\Redirect\PathRedirectRepository;
use App\Model\Redirect\RedirectTarget;
use App\Model\Transaction;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use UnexpectedValueException;

final class PageService
{
	private const SLUG_PATTERN = '~^[a-z0-9]+(?:-[a-z0-9]+)*$~D';
	private const SLUG_MAX_LENGTH = 100;
	private const TITLE_MAX_LENGTH = 200;
	private const META_DESCRIPTION_MAX_LENGTH = 300;


	public function __construct(
		private readonly PageRepository $pages,
		private readonly PathRedirectRepository $redirects,
		private readonly PagePathStrategy $strategy,
		private readonly ReservedSegments $reserved,
		private readonly Transaction $transaction,
		private readonly CmsConfig $config,
		private readonly ClockInterface $clock,
	) {
	}


	/**
	 * Slug problems are reported in this order: reserved (raw slug), format, reserved (computed path), taken.
	 * Ancestor slugs are read before the transaction, so a future hierarchical strategy should read them under the lock.
	 *
	 * @throws InvalidSlugException nothing is written then
	 * @throws InvalidArgumentException when the page does not exist, the locale is not configured, or the title or description is blank or too long
	 */
	public function saveTranslation(SavePageTranslation $command): PageTranslation
	{
		if (!in_array($command->locale, $this->config->locales, true)) {
			throw new InvalidArgumentException('Locale "' . $command->locale . '" is not configured.');
		}
		$title = trim($command->title);
		if ($title === '' || mb_strlen($title) > self::TITLE_MAX_LENGTH) {
			throw new InvalidArgumentException('Title must be 1-' . self::TITLE_MAX_LENGTH . ' characters.');
		}
		if (mb_strlen($command->metaDescription) > self::META_DESCRIPTION_MAX_LENGTH) {
			throw new InvalidArgumentException('Meta description must be at most ' . self::META_DESCRIPTION_MAX_LENGTH . ' characters.');
		}

		// Reserved is judged on the raw slug first so that "Novinky" is reported as reserved, not as malformed.
		if ($this->reserved->isReserved($command->slug)) {
			throw new InvalidSlugException(InvalidSlugException::RESERVED);
		}
		if (strlen($command->slug) > self::SLUG_MAX_LENGTH || preg_match(self::SLUG_PATTERN, $command->slug) !== 1) {
			throw new InvalidSlugException(InvalidSlugException::FORMAT);
		}

		$page = $this->pages->getPage($command->pageId)
			?? throw new InvalidArgumentException('Page ' . $command->pageId . ' does not exist.');
		$path = $this->strategy->pathFor($command->slug, $this->ancestorSlugs($page, $command->locale));
		if ($this->reserved->isReserved($path)) {
			throw new InvalidSlugException(InvalidSlugException::RESERVED);
		}

		return $this->transaction->run(function () use ($command, $path, $title): PageTranslation {
			$existing = $this->pages->lockTranslation($command->pageId, $command->locale);
			if ($this->pages->isPathTaken($command->locale, $path, $command->pageId)) {
				throw new InvalidSlugException(InvalidSlugException::TAKEN);
			}

			$this->moveRedirects($command->pageId, $command->locale, $existing?->path, $path);
			$this->pages->saveTranslation(new PageTranslation(
				$command->pageId,
				$command->locale,
				$title,
				$command->slug,
				$path,
				$command->content,
				$command->metaDescription,
				$command->status,
				$this->clock->now(),
				1,
			));
			$this->recomputeDescendantPaths($command->pageId, $command->locale, [...$this->ancestorSlugs($this->pageOrFail($command->pageId), $command->locale), $command->slug], []);

			return $this->pages->findTranslation($command->pageId, $command->locale)
				?? throw new UnexpectedValueException('Saved translation vanished.');
		});
	}


	/** Live content wins: a redirect from the new path is dropped; the old path of this page starts redirecting. */
	private function moveRedirects(int $pageId, string $locale, ?string $oldPath, string $newPath): void
	{
		if ($oldPath !== null && $oldPath !== $newPath) {
			$this->redirects->record($locale, $oldPath, new RedirectTarget('page', $pageId));
		}
		$this->redirects->deleteByPath($locale, $newPath);
	}


	/**
	 * Re-derives the path of every descendant translation in $locale from the strategy; with the flat strategy nothing changes.
	 *
	 * @param list<string> $ancestorSlugs slugs from the root down to $pageId, inclusive
	 * @param array<int, true> $seen
	 */
	private function recomputeDescendantPaths(int $pageId, string $locale, array $ancestorSlugs, array $seen): void
	{
		$seen[$pageId] = true;
		foreach ($this->pages->findChildren($pageId) as $child) {
			if (isset($seen[$child->id])) {
				continue;
			}
			$translation = $this->pages->lockTranslation($child->id, $locale);
			$slugs = $ancestorSlugs;
			if ($translation !== null) {
				$path = $this->strategy->pathFor($translation->slug, $ancestorSlugs);
				if ($path !== $translation->path) {
					if ($this->pages->isPathTaken($locale, $path, $child->id)) {
						throw new InvalidSlugException(InvalidSlugException::TAKEN);
					}
					$this->moveRedirects($child->id, $locale, $translation->path, $path);
					$this->pages->saveTranslation(new PageTranslation(
						$child->id,
						$locale,
						$translation->title,
						$translation->slug,
						$path,
						$translation->content,
						$translation->metaDescription,
						$translation->status,
						$translation->updatedAt,
						1,
					));
				}
				$slugs[] = $translation->slug;
			}
			$this->recomputeDescendantPaths($child->id, $locale, $slugs, $seen);
		}
	}


	/**
	 * Slugs of the ancestors that have a translation in $locale, root first.
	 *
	 * @return list<string>
	 */
	private function ancestorSlugs(Page $page, string $locale): array
	{
		$slugs = [];
		$seen = [$page->id => true];
		$parentId = $page->parentId;
		while ($parentId !== null && !isset($seen[$parentId])) {
			$seen[$parentId] = true;
			$translation = $this->pages->findTranslation($parentId, $locale);
			if ($translation !== null) {
				array_unshift($slugs, $translation->slug);
			}
			$parentId = $this->pages->getPage($parentId)?->parentId;
		}

		return $slugs;
	}


	private function pageOrFail(int $pageId): Page
	{
		return $this->pages->getPage($pageId) ?? throw new UnexpectedValueException('Page vanished.');
	}
}
