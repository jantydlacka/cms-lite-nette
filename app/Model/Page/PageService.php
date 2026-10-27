<?php

declare(strict_types=1);

namespace App\Model\Page;

use App\Model\CmsConfig;
use App\Model\PublicationStatus;
use App\Model\Redirect\PathRedirectRepository;
use App\Model\Redirect\RedirectTarget;
use App\Model\Transaction;
use InvalidArgumentException;
use Nette\Utils\Strings;
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
		private readonly PageTreeLock $treeLock,
		private readonly CmsConfig $config,
		private readonly ClockInterface $clock,
	) {
	}


	/**
	 * Creates the page as the last of its siblings together with its first translation; a failing translation leaves no page.
	 *
	 * @throws InvalidSlugException nothing is written then
	 * @throws InvalidArgumentException when the parent does not exist, or the input is invalid as in saveTranslation()
	 */
	public function createPage(CreatePage $command): PageTranslation
	{
		[$title, $slug] = $this->checkInput($command->locale, $command->title, $command->slug, $command->metaDescription);

		return $this->transaction->run(function () use ($command, $title, $slug): PageTranslation {
			$this->treeLock->acquire();
			if ($command->parentId !== null && $this->pages->getPage($command->parentId) === null) {
				throw new InvalidArgumentException('Parent page ' . $command->parentId . ' does not exist.');
			}
			$positions = array_map(static fn (Page $sibling): int => $sibling->position, $this->pages->findChildren($command->parentId));
			$pageId = $this->pages->createPage($command->parentId, $positions === [] ? 0 : max($positions) + 1, $command->inMenu);

			return $this->writeTranslation(
				$this->pageOrFail($pageId),
				$command->locale,
				$title,
				$slug,
				$command->content,
				$command->metaDescription,
				$command->status,
				null,
			);
		});
	}


	/**
	 * Slug problems are reported in this order: reserved (slug), long, format, reserved (computed path), taken.
	 * An empty slug is derived from the title and then checked the same way.
	 * Descendant paths the strategy changes are written too, which raises those translations' versions by 1.
	 *
	 * @throws InvalidSlugException nothing is written then
	 * @throws StaleVersionException when the stored version differs from $command->expectedVersion; nothing is written then
	 * @throws InvalidArgumentException when the page does not exist, the locale is not configured, or the title or description is blank or too long
	 */
	public function saveTranslation(SavePageTranslation $command): PageTranslation
	{
		[$title, $slug] = $this->checkInput($command->locale, $command->title, $command->slug, $command->metaDescription);

		return $this->transaction->run(function () use ($command, $title, $slug): PageTranslation {
			$this->treeLock->acquire();
			$page = $this->pages->getPage($command->pageId)
				?? throw new InvalidArgumentException('Page ' . $command->pageId . ' does not exist.');

			return $this->writeTranslation(
				$page,
				$command->locale,
				$title,
				$slug,
				$command->content,
				$command->metaDescription,
				$command->status,
				$command->expectedVersion,
			);
		});
	}


	/**
	 * Parent and menu flag; the position comes from the stored row, or is the last among the new siblings after a parent change.
	 *
	 * @throws StaleVersionException when the stored version differs from $command->expectedVersion; nothing is written then
	 * @throws InvalidParentException when the parent is the page itself or its descendant; nothing is written then
	 * After a parent change a recomputed path is written like any save, so that translation's version goes up by 1
	 * (only when the strategy changes the path; the flat one never does).
	 *
	 * @throws InvalidSlugException when a recomputed path is taken; nothing is written then
	 * @throws InvalidArgumentException when the page or the parent does not exist
	 * @throws UnexpectedValueException when the stored tree has a cycle; nothing is written then
	 */
	public function updatePage(UpdatePage $command): Page
	{
		return $this->transaction->run(function () use ($command): Page {
			$this->treeLock->acquire();
			$page = $this->pages->lockPage($command->pageId)
				?? throw new InvalidArgumentException('Page ' . $command->pageId . ' does not exist.');
			if ($page->version !== $command->expectedVersion) {
				throw new StaleVersionException('Page ' . $page->id . ' has changed since version ' . $command->expectedVersion . '.');
			}

			$parentId = $command->parentId;
			if ($parentId === $page->parentId) {
				$this->pages->updatePage(new Page($page->id, $parentId, $page->position, $command->inMenu, $page->version));

				return $this->pageOrFail($page->id);
			}

			if ($parentId !== null && $this->pages->getPage($parentId) === null) {
				throw new InvalidArgumentException('Parent page ' . $parentId . ' does not exist.');
			}
			if ($parentId === $page->id || ($parentId !== null && in_array($parentId, $this->descendantIds($page->id), true))) {
				throw new InvalidParentException('Page ' . $page->id . ' cannot be moved under itself or its descendant ' . $parentId . '.');
			}

			$positions = array_map(static fn (Page $sibling): int => $sibling->position, $this->pages->findChildren($parentId));
			$this->pages->updatePage(new Page($page->id, $parentId, $positions === [] ? 0 : max($positions) + 1, $command->inMenu, $page->version));
			$this->renumber($this->pages->findChildren($page->parentId));

			$moved = $this->pageOrFail($page->id);
			foreach ($this->config->locales as $locale) {
				$this->recomputePaths($moved, $locale);
			}

			return $moved;
		});
	}


	/**
	 * Swaps the page with its previous sibling; the first one stays. Leaves every version alone.
	 *
	 * @throws InvalidArgumentException when the page does not exist
	 */
	public function moveUp(int $pageId): void
	{
		$this->move($pageId, -1);
	}


	/**
	 * Swaps the page with its next sibling; the last one stays. Leaves every version alone.
	 *
	 * @throws InvalidArgumentException when the page does not exist
	 */
	public function moveDown(int $pageId): void
	{
		$this->move($pageId, 1);
	}


	/**
	 * Takes no lock: a write path must call it after PageTreeLock::acquire().
	 *
	 * @return list<int>
	 * @throws UnexpectedValueException when the stored tree has a cycle
	 */
	public function descendantIds(int $pageId): array
	{
		$ids = [];
		$seen = [$pageId => true];
		$queue = [$pageId];
		while ($queue !== []) {
			foreach ($this->pages->findChildren(array_shift($queue)) as $child) {
				if (isset($seen[$child->id])) {
					throw self::cycleAt($child->id);
				}
				$seen[$child->id] = true;
				$ids[] = $child->id;
				$queue[] = $child->id;
			}
		}

		return $ids;
	}


	private function move(int $pageId, int $step): void
	{
		$this->transaction->run(function () use ($pageId, $step): void {
			$this->treeLock->acquire();
			$page = $this->pages->getPage($pageId)
				?? throw new InvalidArgumentException('Page ' . $pageId . ' does not exist.');
			$siblings = $this->pages->findChildren($page->parentId);
			$index = array_search($page->id, array_map(static fn (Page $sibling): int => $sibling->id, $siblings), true);
			if ($index === false) {
				throw new UnexpectedValueException('Page ' . $page->id . ' is missing among the children of its parent.');
			}
			$target = $index + $step;
			if (!isset($siblings[$target])) {
				return;
			}
			[$siblings[$index], $siblings[$target]] = [$siblings[$target], $siblings[$index]];
			$this->renumber(array_values($siblings));
		});
	}


	/**
	 * Positions 0..n-1 in the given order; only changed rows are written.
	 *
	 * @param list<Page> $siblings
	 */
	private function renumber(array $siblings): void
	{
		$positions = [];
		foreach ($siblings as $position => $sibling) {
			if ($sibling->position !== $position) {
				$positions[$sibling->id] = $position;
			}
		}
		if ($positions !== []) {
			$this->pages->setPositions($positions);
		}
	}


	/** The page's own path and those of its descendants in $locale, from its current ancestors. */
	private function recomputePaths(Page $page, string $locale): void
	{
		$ancestorSlugs = $this->ancestorSlugs($page, $locale);
		$translation = $this->pages->lockTranslation($page->id, $locale);
		if ($translation !== null) {
			$this->repath($translation, $ancestorSlugs);
			$ancestorSlugs[] = $translation->slug;
		}
		$this->recomputeDescendantPaths($page->id, $locale, $ancestorSlugs, []);
	}


	private static function cycleAt(int $pageId): UnexpectedValueException
	{
		return new UnexpectedValueException('Page tree contains a cycle at page ' . $pageId);
	}


	/**
	 * Checks that need no stored data.
	 *
	 * @return array{string, string} trimmed title, slug (derived from the title when empty)
	 */
	private function checkInput(string $locale, string $title, string $slug, string $metaDescription): array
	{
		if (!in_array($locale, $this->config->locales, true)) {
			throw new InvalidArgumentException('Locale "' . $locale . '" is not configured.');
		}
		$title = Strings::trim($title);
		if ($title === '' || mb_strlen($title) > self::TITLE_MAX_LENGTH) {
			throw new InvalidArgumentException('Title must be 1-' . self::TITLE_MAX_LENGTH . ' characters.');
		}
		if (mb_strlen($metaDescription) > self::META_DESCRIPTION_MAX_LENGTH) {
			throw new InvalidArgumentException('Meta description must be at most ' . self::META_DESCRIPTION_MAX_LENGTH . ' characters.');
		}

		if ($slug === '') {
			$slug = self::slugFromTitle($title);
		}
		// Reserved is judged on the raw slug first so that "Novinky" is reported as reserved, not as malformed.
		if ($this->reserved->isReserved($slug)) {
			throw new InvalidSlugException(InvalidSlugException::RESERVED);
		}
		if (strlen($slug) > self::SLUG_MAX_LENGTH) {
			throw new InvalidSlugException(InvalidSlugException::LONG);
		}
		if (preg_match(self::SLUG_PATTERN, $slug) !== 1) {
			throw new InvalidSlugException(InvalidSlugException::FORMAT);
		}

		return [$title, $slug];
	}


	/** Cut at the last hyphen within the limit; a single word longer than the limit is cut hard. */
	private static function slugFromTitle(string $title): string
	{
		$slug = Strings::webalize($title);
		if (strlen($slug) > self::SLUG_MAX_LENGTH) {
			$hyphen = strrpos(substr($slug, 0, self::SLUG_MAX_LENGTH + 1), '-');
			$slug = substr($slug, 0, $hyphen === false ? self::SLUG_MAX_LENGTH : $hyphen);
		}

		return rtrim($slug, '-');
	}


	/** Must run inside the transaction, after the tree lock. */
	private function writeTranslation(
		Page $page,
		string $locale,
		string $title,
		string $slug,
		string $content,
		string $metaDescription,
		PublicationStatus $status,
		?int $expectedVersion,
	): PageTranslation {
		$ancestorSlugs = $this->ancestorSlugs($page, $locale);
		$path = $this->strategy->pathFor($slug, $ancestorSlugs);
		if ($this->reserved->isReserved($path)) {
			throw new InvalidSlugException(InvalidSlugException::RESERVED);
		}

		$existing = $this->pages->lockTranslation($page->id, $locale);
		if ($existing?->version !== $expectedVersion) {
			throw new StaleVersionException('Translation ' . $page->id . '/' . $locale . ' has changed since version ' . ($expectedVersion ?? 'none') . '.');
		}
		if ($this->pages->isPathTaken($locale, $path, $page->id)) {
			throw new InvalidSlugException(InvalidSlugException::TAKEN);
		}

		$this->moveRedirects($page->id, $locale, $existing?->path, $path);
		// The repository sets the version itself (1 on insert, +1 on update); the argument is ignored.
		$this->pages->saveTranslation(new PageTranslation(
			$page->id,
			$locale,
			$title,
			$slug,
			$path,
			$content,
			$metaDescription,
			$status,
			$this->clock->now(),
			1,
		));
		$this->recomputeDescendantPaths($page->id, $locale, [...$ancestorSlugs, $slug], []);

		return $this->pages->findTranslation($page->id, $locale)
			?? throw new UnexpectedValueException('Saved translation vanished.');
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
				throw self::cycleAt($child->id);
			}
			$translation = $this->pages->lockTranslation($child->id, $locale);
			$slugs = $ancestorSlugs;
			if ($translation !== null) {
				$this->repath($translation, $ancestorSlugs);
				$slugs[] = $translation->slug;
			}
			$this->recomputeDescendantPaths($child->id, $locale, $slugs, $seen);
		}
	}


	/**
	 * Re-derives one stored path from the strategy and redirects the old one; an unchanged path writes nothing.
	 *
	 * @param list<string> $ancestorSlugs
	 */
	private function repath(PageTranslation $translation, array $ancestorSlugs): void
	{
		$path = $this->strategy->pathFor($translation->slug, $ancestorSlugs);
		if ($path === $translation->path) {
			return;
		}
		if ($this->pages->isPathTaken($translation->locale, $path, $translation->pageId)) {
			throw new InvalidSlugException(InvalidSlugException::TAKEN);
		}
		$this->moveRedirects($translation->pageId, $translation->locale, $translation->path, $path);
		$this->pages->saveTranslation(new PageTranslation(
			$translation->pageId,
			$translation->locale,
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
		while ($parentId !== null) {
			if (isset($seen[$parentId])) {
				throw self::cycleAt($parentId);
			}
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
