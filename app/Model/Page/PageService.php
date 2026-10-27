<?php

declare(strict_types=1);

namespace App\Model\Page;

use App\Model\CmsConfig;
use App\Model\InvalidSlugException;
use App\Model\PublicationStatus;
use App\Model\Redirect\PathRedirectRepository;
use App\Model\Redirect\RedirectTarget;
use App\Model\Setting\SettingRepository;
use App\Model\Slug;
use App\Model\Transaction;
use InvalidArgumentException;
use LogicException;
use Nette\Utils\Strings;
use Psr\Clock\ClockInterface;
use UnexpectedValueException;

final class PageService
{
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
		private readonly Homepage $homepage,
		private readonly SettingRepository $settings,
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
		$this->assertOwnTransaction();
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
	 * Problems are reported in this order: locale, title and description; slug reserved, long, format (all before the
	 * transaction); then under the tree lock: missing page, homepage draft, computed path reserved, stale version, path taken.
	 * An empty slug is derived from the title and then checked the same way.
	 * Descendant paths the strategy changes are written too, which raises those translations' versions by 1.
	 *
	 * @throws InvalidSlugException nothing is written then
	 * @throws StaleVersionException when the stored version differs from $command->expectedVersion; nothing is written then
	 * @throws HomepageProtectedException PROTECTED when the homepage's default-locale translation would become a draft; nothing is written then
	 * @throws InvalidArgumentException when the page does not exist, the locale is not configured, or the title or description is blank or too long
	 */
	public function saveTranslation(SavePageTranslation $command): PageTranslation
	{
		$this->assertOwnTransaction();
		[$title, $slug] = $this->checkInput($command->locale, $command->title, $command->slug, $command->metaDescription);

		return $this->transaction->run(function () use ($command, $title, $slug): PageTranslation {
			$this->treeLock->acquire();
			$page = $this->pages->getPage($command->pageId)
				?? throw new InvalidArgumentException('Page ' . $command->pageId . ' does not exist.');
			if (
				$command->status === PublicationStatus::Draft
				&& $command->locale === $this->config->defaultLocale
				&& $this->homepage->isHomepage($page->id)
			) {
				throw new HomepageProtectedException(HomepageProtectedException::PROTECTED);
			}

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
	 * Parent and menu flag; the position comes from the stored row, or is the last among the new siblings after a parent
	 * change, which moves the page like movePage().
	 * After a parent change a recomputed path is written like any save, so that translation's version goes up by 1
	 * (only when the strategy changes the path; the flat one never does).
	 *
	 * @throws StaleVersionException when the stored version differs from $command->expectedVersion; nothing is written then
	 * @throws InvalidParentException when the parent is the page itself or its descendant; nothing is written then
	 * @throws InvalidSlugException when a recomputed path is taken; nothing is written then
	 * @throws InvalidArgumentException when the page or the parent does not exist
	 * @throws UnexpectedValueException when the stored tree has a cycle; nothing is written then
	 */
	public function updatePage(UpdatePage $command): Page
	{
		$this->assertOwnTransaction();
		return $this->transaction->run(function () use ($command): Page {
			$this->treeLock->acquire();
			$page = $this->pages->lockPage($command->pageId)
				?? throw new InvalidArgumentException('Page ' . $command->pageId . ' does not exist.');
			if ($page->version !== $command->expectedVersion) {
				throw new StaleVersionException('Page ' . $page->id . ' has changed since version ' . $command->expectedVersion . '.');
			}

			$this->pages->updatePage(new Page($page->id, $page->parentId, $page->position, $command->inMenu, $page->version));
			if ($command->parentId !== $page->parentId) {
				$this->moveUnderLock($page, $command->parentId, PHP_INT_MAX);
			}

			return $this->pageOrFail($page->id);
		});
	}


	/**
	 * Swaps the page with its previous sibling; the first one stays. Leaves every version alone.
	 *
	 * @throws InvalidArgumentException when the page does not exist
	 * @throws UnexpectedValueException when the page is missing among the children of its parent; nothing is written then
	 */
	public function moveUp(int $pageId): void
	{
		$this->moveBy($pageId, -1);
	}


	/**
	 * Swaps the page with its next sibling; the last one stays. Leaves every version alone.
	 *
	 * @throws InvalidArgumentException when the page does not exist
	 * @throws UnexpectedValueException when the page is missing among the children of its parent; nothing is written then
	 */
	public function moveDown(int $pageId): void
	{
		$this->moveBy($pageId, 1);
	}


	/**
	 * Puts the page at $position among the children of $parentId (top level for null); the position is clamped to 0..n.
	 * Positions of the old and the new siblings become 0..n-1. Leaves every page version alone; after a parent change
	 * the recomputed paths are written like in updatePage().
	 *
	 * @throws InvalidParentException when the parent is the page itself or its descendant; nothing is written then
	 * @throws InvalidSlugException when a recomputed path is taken; nothing is written then
	 * @throws InvalidArgumentException when the page or the parent does not exist
	 * @throws UnexpectedValueException when the stored tree has a cycle; nothing is written then
	 */
	public function movePage(int $pageId, ?int $parentId, int $position): void
	{
		$this->assertOwnTransaction();
		$this->transaction->run(function () use ($pageId, $parentId, $position): void {
			$this->treeLock->acquire();
			$page = $this->pages->getPage($pageId)
				?? throw new InvalidArgumentException('Page ' . $pageId . ' does not exist.');
			$this->moveUnderLock($page, $parentId, $position);
		});
	}


	/**
	 * @throws HomepageProtectedException NEEDS_PUBLISHED when the page has no published translation in the default locale; nothing is written then
	 * @throws InvalidArgumentException when the page does not exist
	 */
	public function setHomepage(int $pageId): void
	{
		$this->assertOwnTransaction();
		$this->transaction->run(function () use ($pageId): void {
			$this->treeLock->acquire();
			if ($this->pages->getPage($pageId) === null) {
				throw new InvalidArgumentException('Page ' . $pageId . ' does not exist.');
			}
			if ($this->pages->findTranslation($pageId, $this->config->defaultLocale)?->status !== PublicationStatus::Published) {
				throw new HomepageProtectedException(HomepageProtectedException::NEEDS_PUBLISHED);
			}
			$this->settings->set(Homepage::KEY, (string) $pageId);
		});
	}


	/**
	 * Deletes the page with all its translations and the redirects to it.
	 *
	 * @throws HomepageProtectedException PROTECTED when the page is the homepage; nothing is written then
	 * @throws PageHasChildrenException nothing is written then
	 * @throws InvalidArgumentException when the page does not exist
	 */
	public function deletePage(int $pageId): void
	{
		$this->assertOwnTransaction();
		$this->transaction->run(function () use ($pageId): void {
			$this->treeLock->acquire();
			if ($this->pages->getPage($pageId) === null) {
				throw new InvalidArgumentException('Page ' . $pageId . ' does not exist.');
			}
			if ($this->homepage->isHomepage($pageId)) {
				throw new HomepageProtectedException(HomepageProtectedException::PROTECTED);
			}
			if ($this->pages->findChildren($pageId) !== []) {
				throw new PageHasChildrenException('Page ' . $pageId . ' has subpages.');
			}
			$this->redirects->deleteForTarget(new RedirectTarget('page', $pageId));
			$this->pages->deletePage($pageId);
		});
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


	/** A step past the first or the last sibling writes nothing, so the stored positions stay as they are. */
	private function moveBy(int $pageId, int $step): void
	{
		$this->assertOwnTransaction();
		$this->transaction->run(function () use ($pageId, $step): void {
			$this->treeLock->acquire();
			$page = $this->pages->getPage($pageId)
				?? throw new InvalidArgumentException('Page ' . $pageId . ' does not exist.');
			$siblings = $this->pages->findChildren($page->parentId);
			$index = array_search($page->id, array_map(static fn (Page $sibling): int => $sibling->id, $siblings), true);
			if ($index === false) {
				throw new UnexpectedValueException('Page ' . $page->id . ' is missing among the children of its parent.');
			}
			if (isset($siblings[$index + $step])) {
				$this->moveUnderLock($page, $page->parentId, $index + $step);
			}
		});
	}


	/**
	 * The body of movePage(); must run inside the transaction, after the tree lock. $locked is the page as stored
	 * before the move (its parent and position); its other fields are not written.
	 */
	private function moveUnderLock(Page $locked, ?int $parentId, int $position): void
	{
		$reparent = $parentId !== $locked->parentId;
		if ($reparent) {
			if ($parentId !== null && $this->pages->getPage($parentId) === null) {
				throw new InvalidArgumentException('Parent page ' . $parentId . ' does not exist.');
			}
			if ($parentId === $locked->id || ($parentId !== null && in_array($parentId, $this->descendantIds($locked->id), true))) {
				throw new InvalidParentException('Page ' . $locked->id . ' cannot be moved under itself or its descendant ' . $parentId . '.');
			}
		}

		$siblings = array_values(array_filter(
			$this->pages->findChildren($parentId),
			static fn (Page $sibling): bool => $sibling->id !== $locked->id,
		));
		$position = max(0, min($position, count($siblings)));
		if ($reparent) {
			$this->pages->setParent($locked->id, $parentId, $position);
		}
		array_splice($siblings, $position, 0, [new Page($locked->id, $parentId, $reparent ? $position : $locked->position, $locked->inMenu, $locked->version)]);
		$this->renumber($siblings);
		if (!$reparent) {
			return;
		}

		$this->renumber($this->pages->findChildren($locked->parentId));
		$moved = $this->pageOrFail($locked->id);
		foreach ($this->config->locales as $locale) {
			$this->recomputePaths($moved, $locale);
		}
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


	/**
	 * A write must open its transaction itself: joining an outer one would read rows the outer transaction saw
	 * before the tree lock was taken.
	 */
	private function assertOwnTransaction(): void
	{
		if ($this->transaction->inTransaction()) {
			throw new LogicException('Page writes must start their own transaction; one is already open.');
		}
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
			$slug = Slug::fromTitle($title);
		}
		// Reserved is judged on the raw slug first so that "Novinky" is reported as reserved, not as malformed.
		if ($this->reserved->isReserved($slug)) {
			throw new InvalidSlugException(InvalidSlugException::RESERVED);
		}
		Slug::assertValid($slug);

		return [$title, $slug];
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
		// record() before deleteByPath(): after the gap lock of the DELETE, no insert into path_redirect may follow in this transaction, or concurrent writers deadlock.
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
			$this->clock->now(),
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
