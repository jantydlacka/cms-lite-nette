<?php

declare(strict_types=1);

namespace Tests\Model\Page;

use App\Model\Page\MenuItem;
use App\Model\Page\Page;
use App\Model\Page\PageRepository;
use App\Model\Page\PageTranslation;
use App\Model\Page\PageTreeItem;
use App\Model\PublicationStatus;
use DateTimeImmutable;
use DateTimeZone;
use Tester\Assert;

use function test;

/** Behaviour every PageRepository implementation must share. */
final class PageRepositoryContract
{
	/**
	 * @param callable(): void $reset empties the store between scenarios
	 * @param callable(DateTimeImmutable): void $setNow moves the clock of the repository (if it has one)
	 */
	public static function run(PageRepository $repo, callable $reset, callable $setNow): void
	{
		test('published page is found by path, a draft is not', function () use ($repo, $reset): void {
			$reset();
			$id = $repo->createPage(null, 0, true);
			$repo->saveTranslation(self::translation($id, 'cs', 'o-nas', 'O nas', PublicationStatus::Published));
			$draft = $repo->createPage(null, 1, true);
			$repo->saveTranslation(self::translation($draft, 'cs', 'koncept', 'Koncept', PublicationStatus::Draft));

			$found = $repo->findPublishedByPath('cs', 'o-nas');
			Assert::notNull($found);
			assert($found !== null);
			Assert::same($id, $found->pageId);
			Assert::same('O nas', $found->title);
			Assert::same(PublicationStatus::Published, $found->status);
			Assert::null($repo->findPublishedByPath('cs', 'koncept'));
			Assert::same(PublicationStatus::Draft, $repo->findTranslation($draft, 'cs')?->status);
			Assert::null($repo->findPublishedByPath('en', 'o-nas'));
			Assert::null($repo->findPublishedByPath('cs', 'O-NAS'));
			Assert::null($repo->findTranslation($id, 'en'));
		});

		test('getPage returns the stored page or null', function () use ($repo, $reset): void {
			$reset();
			$parent = $repo->createPage(null, 3, false);
			$child = $repo->createPage($parent, 7, true);

			$page = $repo->getPage($child);
			Assert::notNull($page);
			assert($page !== null);
			Assert::same($parent, $page->parentId);
			Assert::same(7, $page->position);
			Assert::true($page->inMenu);
			Assert::false($repo->getPage($parent)?->inMenu);
			Assert::null($repo->getPage($parent)?->parentId);
			Assert::null($repo->getPage($child + 1000));
		});

		test('saveTranslation updates an existing (page, locale) pair', function () use ($repo, $reset): void {
			$reset();
			$id = $repo->createPage(null, 0, true);
			$repo->saveTranslation(self::translation($id, 'cs', 'stary', 'Stary', PublicationStatus::Draft));
			$repo->saveTranslation(self::translation($id, 'cs', 'novy', 'Novy', PublicationStatus::Published));

			Assert::null($repo->findPublishedByPath('cs', 'stary'));
			Assert::same('Novy', $repo->findPublishedByPath('cs', 'novy')?->title);
		});

		test('rows carry a version counter: pages start at 1, a translation goes 1, 2 and ignores the argument', function () use ($repo, $reset): void {
			$reset();
			$id = $repo->createPage(null, 0, true);
			Assert::same(1, $repo->getPage($id)?->version);
			Assert::null($repo->findTranslation($id, 'cs'));

			$repo->saveTranslation(self::translation($id, 'cs', 'a', 'A', PublicationStatus::Draft));
			Assert::same(1, $repo->findTranslation($id, 'cs')?->version);
			Assert::same(1, $repo->lockTranslation($id, 'cs')?->version);
			$repo->saveTranslation(self::translation($id, 'cs', 'a', 'A2', PublicationStatus::Draft));
			Assert::same(2, $repo->findTranslation($id, 'cs')?->version);
			$repo->saveTranslation(self::translation($id, 'en', 'a', 'A', PublicationStatus::Draft));
			Assert::same(1, $repo->findTranslation($id, 'en')?->version);
			Assert::same(1, $repo->getPage($id)?->version);
		});

		test('menu is ordered by position then id and nests children', function () use ($repo, $reset): void {
			$reset();
			$b = $repo->createPage(null, 2, true);
			$a = $repo->createPage(null, 1, true);
			$a2 = $repo->createPage(null, 1, true);
			$child2 = $repo->createPage($a, 2, true);
			$child1 = $repo->createPage($a, 1, true);
			foreach ([$b => 'b', $a => 'a', $a2 => 'a2', $child2 => 'child2', $child1 => 'child1'] as $pageId => $slug) {
				$repo->saveTranslation(self::translation($pageId, 'cs', $slug, strtoupper($slug), PublicationStatus::Published));
			}

			$menu = $repo->findMenu('cs');
			Assert::same([$a, $a2, $b], array_map(static fn (MenuItem $i): int => $i->pageId, $menu));
			Assert::same([$child1, $child2], array_map(static fn (MenuItem $i): int => $i->pageId, $menu[0]->children));
			Assert::same('A', $menu[0]->title);
			Assert::same('child1', $menu[0]->children[0]->path);
			Assert::same([], $menu[2]->children);
			Assert::same([], $repo->findMenu('en'));
		});

		test('menu skips drafts, pages outside the menu and the children of an ancestor that is unpublished or outside the menu', function () use ($repo, $reset): void {
			$reset();
			$visible = $repo->createPage(null, 0, true);
			$hidden = $repo->createPage(null, 1, false);
			$draft = $repo->createPage(null, 2, true);
			$orphan = $repo->createPage($draft, 0, true);
			$hiddenChild = $repo->createPage($hidden, 0, true);
			$repo->saveTranslation(self::translation($visible, 'cs', 'visible', 'Visible', PublicationStatus::Published));
			$repo->saveTranslation(self::translation($hidden, 'cs', 'hidden', 'Hidden', PublicationStatus::Published));
			$repo->saveTranslation(self::translation($draft, 'cs', 'draft', 'Draft', PublicationStatus::Draft));
			$repo->saveTranslation(self::translation($orphan, 'cs', 'orphan', 'Orphan', PublicationStatus::Published));
			$repo->saveTranslation(self::translation($hiddenChild, 'cs', 'hidden-child', 'Hidden child', PublicationStatus::Published));

			$menu = $repo->findMenu('cs');
			Assert::same([$visible], array_map(static fn (MenuItem $i): int => $i->pageId, $menu));
		});

		test('breadcrumbs run from the root to the page and need every ancestor published in the locale', function () use ($repo, $reset): void {
			$reset();
			$root = $repo->createPage(null, 0, true);
			$mid = $repo->createPage($root, 0, true);
			$leaf = $repo->createPage($mid, 0, true);
			$repo->saveTranslation(self::translation($root, 'cs', 'root', 'Root', PublicationStatus::Published));
			$repo->saveTranslation(self::translation($mid, 'cs', 'root/mid', 'Mid', PublicationStatus::Published));
			$repo->saveTranslation(self::translation($leaf, 'cs', 'root/mid/leaf', 'Leaf', PublicationStatus::Published));

			$trail = $repo->findBreadcrumbs($leaf, 'cs');
			Assert::same([$root, $mid, $leaf], array_map(static fn (MenuItem $i): int => $i->pageId, $trail));
			Assert::same(['root', 'root/mid', 'root/mid/leaf'], array_map(static fn (MenuItem $i): string => $i->path, $trail));
			Assert::same([], $trail[0]->children);

			Assert::same([], $repo->findBreadcrumbs($leaf, 'en'));
			$repo->saveTranslation(self::translation($root, 'en', 'root', 'Root', PublicationStatus::Published));
			$repo->saveTranslation(self::translation($leaf, 'en', 'root/mid/leaf', 'Leaf', PublicationStatus::Published));
			Assert::same([], $repo->findBreadcrumbs($leaf, 'en'));
			Assert::same([], $repo->findBreadcrumbs($leaf + 1000, 'cs'));
			$repo->saveTranslation(self::translation($mid, 'cs', 'root/mid', 'Mid', PublicationStatus::Draft));
			Assert::same([], $repo->findBreadcrumbs($leaf, 'cs'));
			Assert::same([$root], array_map(static fn (MenuItem $i): int => $i->pageId, $repo->findBreadcrumbs($root, 'cs')));
		});

		test('updatedAt is the instant of the argument in UTC, whatever the clock or the default timezone says', function () use ($repo, $reset, $setNow): void {
			$reset();
			$previous = date_default_timezone_get();
			date_default_timezone_set('Europe/Prague');
			try {
				$cases = [
					'DST edge' => new DateTimeImmutable('2026-03-29 01:30:00', new DateTimeZone('UTC')),
					// 02:30 does not exist in Europe/Prague on that day.
					'skipped local hour' => new DateTimeImmutable('2026-03-29 02:30:00', new DateTimeZone('UTC')),
					'non-UTC argument' => new DateTimeImmutable('2026-03-29T03:30:00+02:00'),
				];
				// The clock must not win over the argument.
				$setNow(new DateTimeImmutable('2000-01-01 00:00:00', new DateTimeZone('UTC')));
				foreach ($cases as $label => $instant) {
					$id = $repo->createPage(null, 0, false);
					$repo->saveTranslation(self::translation($id, 'cs', 'time-' . strlen($label), 'Time', PublicationStatus::Draft, $instant));

					$read = $repo->findTranslation($id, 'cs')?->updatedAt;
					assert($read !== null);
					Assert::same($instant->getTimestamp(), $read->getTimestamp(), $label);
					Assert::same('UTC', $read->getTimezone()->getName(), $label);
				}
			} finally {
				date_default_timezone_set($previous);
			}
		});

		test('isPathTaken honours the locale and the excepted page', function () use ($repo, $reset): void {
			$reset();
			$id = $repo->createPage(null, 0, true);
			$other = $repo->createPage(null, 1, true);
			$repo->saveTranslation(self::translation($id, 'cs', 'o-nas', 'O nas', PublicationStatus::Draft));

			Assert::true($repo->isPathTaken('cs', 'o-nas', null));
			Assert::true($repo->isPathTaken('cs', 'o-nas', $other));
			Assert::false($repo->isPathTaken('cs', 'o-nas', $id));
			Assert::false($repo->isPathTaken('en', 'o-nas', null));
			Assert::false($repo->isPathTaken('cs', 'jine', null));
			Assert::false($repo->isPathTaken('cs', 'O-NAS', null));
		});

		test('findChildren returns direct children ordered by position then id', function () use ($repo, $reset): void {
			$reset();
			$root = $repo->createPage(null, 0, true);
			$late = $repo->createPage($root, 5, true);
			$early = $repo->createPage($root, 1, true);
			$tie = $repo->createPage($root, 1, false);
			$grandchild = $repo->createPage($early, 0, true);

			Assert::same([$early, $tie, $late], array_map(static fn (Page $p): int => $p->id, $repo->findChildren($root)));
			Assert::same([$grandchild], array_map(static fn (Page $p): int => $p->id, $repo->findChildren($early)));
			Assert::same([], $repo->findChildren($grandchild));
			Assert::same([], $repo->findChildren($root + 1000));
		});

		test('findChildren with null returns the top-level pages ordered by position then id', function () use ($repo, $reset): void {
			$reset();
			$late = $repo->createPage(null, 3, true);
			$early = $repo->createPage(null, 0, true);
			$repo->createPage($early, 0, true);

			Assert::same([$early, $late], array_map(static fn (Page $p): int => $p->id, $repo->findChildren(null)));
		});

		test('lockTranslation returns the row of any status or null', function () use ($repo, $reset): void {
			$reset();
			$id = $repo->createPage(null, 0, true);
			$repo->saveTranslation(self::translation($id, 'cs', 'koncept', 'Koncept', PublicationStatus::Draft));

			Assert::same('koncept', $repo->lockTranslation($id, 'cs')?->path);
			Assert::same(PublicationStatus::Draft, $repo->lockTranslation($id, 'cs')?->status);
			Assert::null($repo->lockTranslation($id, 'en'));
			Assert::null($repo->lockTranslation($id + 1000, 'cs'));
		});

		test('lockPage returns the page or null', function () use ($repo, $reset): void {
			$reset();
			$parent = $repo->createPage(null, 2, true);
			$id = $repo->createPage($parent, 4, false);

			$page = $repo->lockPage($id);
			Assert::same([$id, $parent, 4, false, 1], [$page?->id, $page?->parentId, $page?->position, $page?->inMenu, $page?->version]);
			Assert::null($repo->lockPage($id + 1000));
		});

		test('updatePage writes parent, position and menu flag and raises the stored version by one', function () use ($repo, $reset): void {
			$reset();
			$parent = $repo->createPage(null, 0, true);
			$id = $repo->createPage(null, 1, true);

			$repo->updatePage(new Page($id, $parent, 7, false, 99));
			$page = $repo->getPage($id);
			Assert::same([$parent, 7, false, 2], [$page?->parentId, $page?->position, $page?->inMenu, $page?->version]);

			$repo->updatePage(new Page($id, null, 3, true, 1));
			$page = $repo->getPage($id);
			Assert::same([null, 3, true, 3], [$page?->parentId, $page?->position, $page?->inMenu, $page?->version]);
			Assert::same(1, $repo->getPage($parent)?->version);
		});

		test('setPositions changes only the given positions and no version', function () use ($repo, $reset): void {
			$reset();
			$a = $repo->createPage(null, 5, true);
			$b = $repo->createPage(null, 9, true);
			$c = $repo->createPage(null, 2, true);

			$repo->setPositions([$a => 1, $b => 0]);
			Assert::same([1, 0, 2], [$repo->getPage($a)?->position, $repo->getPage($b)?->position, $repo->getPage($c)?->position]);
			Assert::same([1, 1, 1], [$repo->getPage($a)?->version, $repo->getPage($b)?->version, $repo->getPage($c)?->version]);
			$repo->setPositions([]);
		});

		test('setParent writes the parent and the position and no version', function () use ($repo, $reset): void {
			$reset();
			$parent = $repo->createPage(null, 0, true);
			$page = $repo->createPage(null, 1, false);

			$repo->setParent($page, $parent, 3);
			$stored = $repo->getPage($page);
			Assert::same([$parent, 3, false, 1], [$stored?->parentId, $stored?->position, $stored?->inMenu, $stored?->version]);
			$repo->setParent($page, null, 0);
			Assert::same([null, 0, 1], [$repo->getPage($page)?->parentId, $repo->getPage($page)?->position, $repo->getPage($page)?->version]);
		});

		test('findTree lists parents before children, siblings by position then id, with depth, title, status, menu flag and child count', function () use ($repo, $reset): void {
			$reset();
			$second = $repo->createPage(null, 1, false);
			$first = $repo->createPage(null, 0, true);
			$childB = $repo->createPage($first, 3, true);
			$childA = $repo->createPage($first, 1, true);
			$grandchild = $repo->createPage($childA, 0, true);
			$tie = $repo->createPage($first, 1, false);
			$repo->saveTranslation(self::translation($first, 'cs', 'prvni', 'Prvni', PublicationStatus::Published));
			$repo->saveTranslation(self::translation($childA, 'cs', 'dite', 'Dite', PublicationStatus::Draft));
			$repo->saveTranslation(self::translation($grandchild, 'cs', 'vnuk', 'Vnuk', PublicationStatus::Published));
			$repo->saveTranslation(self::translation($second, 'en', 'second', 'Second', PublicationStatus::Published));

			$tree = $repo->findTree('cs');
			Assert::same(
				[[$first, null, 0], [$childA, $first, 1], [$grandchild, $childA, 2], [$tie, $first, 1], [$childB, $first, 1], [$second, null, 0]],
				array_map(static fn (PageTreeItem $i): array => [$i->pageId, $i->parentId, $i->depth], $tree),
			);
			Assert::same(['Prvni', 'Dite', 'Vnuk', null, null, null], array_map(static fn (PageTreeItem $i): ?string => $i->title, $tree));
			Assert::same(
				[PublicationStatus::Published, PublicationStatus::Draft, PublicationStatus::Published, null, null, null],
				array_map(static fn (PageTreeItem $i): ?PublicationStatus => $i->status, $tree),
			);
			Assert::same([true, true, true, false, true, false], array_map(static fn (PageTreeItem $i): bool => $i->inMenu, $tree));
			Assert::same([3, 1, 0, 0, 0, 0], array_map(static fn (PageTreeItem $i): int => $i->childCount, $tree));
			Assert::same('Second', $repo->findTree('en')[5]->title);
		});

		test('deletePage removes the page and its translations and leaves other pages', function () use ($repo, $reset): void {
			$reset();
			$keep = $repo->createPage(null, 0, true);
			$gone = $repo->createPage(null, 1, true);
			$repo->saveTranslation(self::translation($keep, 'cs', 'zustava', 'Keep', PublicationStatus::Published));
			$repo->saveTranslation(self::translation($gone, 'cs', 'pryc', 'Gone', PublicationStatus::Published));
			$repo->saveTranslation(self::translation($gone, 'en', 'gone', 'Gone', PublicationStatus::Draft));

			$repo->deletePage($gone);

			Assert::null($repo->getPage($gone));
			Assert::null($repo->findTranslation($gone, 'cs'));
			Assert::null($repo->findTranslation($gone, 'en'));
			Assert::false($repo->isPathTaken('cs', 'pryc', null));
			Assert::notNull($repo->getPage($keep));
			Assert::same('zustava', $repo->findTranslation($keep, 'cs')?->path);
		});

		test('findTree of an empty store is empty', function () use ($repo, $reset): void {
			$reset();
			Assert::same([], $repo->findTree('cs'));
		});
	}


	public static function translation(
		int $pageId,
		string $locale,
		string $path,
		string $title,
		PublicationStatus $status,
		?DateTimeImmutable $updatedAt = null,
	): PageTranslation {
		$slug = substr($path, (int) strrpos('/' . $path, '/'));

		return new PageTranslation($pageId, $locale, $title, $slug, $path, 'content', 'description', $status, $updatedAt ?? new DateTimeImmutable('2000-01-01'), 99);
	}
}
