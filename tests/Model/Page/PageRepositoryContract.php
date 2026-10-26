<?php

declare(strict_types=1);

namespace Tests\Model\Page;

use App\Model\Page\MenuItem;
use App\Model\Page\PageRepository;
use App\Model\Page\PageTranslation;
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
	 * @param callable(DateTimeImmutable): void $setNow moves the clock the repository writes updatedAt from
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

		test('updatedAt is the clock instant in UTC, whatever the argument or the default timezone says', function () use ($repo, $reset, $setNow): void {
			$reset();
			$previous = date_default_timezone_get();
			date_default_timezone_set('Europe/Prague');
			try {
				$cases = [
					'DST edge' => new DateTimeImmutable('2026-03-29 01:30:00', new DateTimeZone('UTC')),
					// 02:30 does not exist in Europe/Prague on that day.
					'skipped local hour' => new DateTimeImmutable('2026-03-29 02:30:00', new DateTimeZone('UTC')),
					'non-UTC clock' => new DateTimeImmutable('2026-03-29T03:30:00+02:00'),
				];
				foreach ($cases as $label => $instant) {
					$setNow($instant);
					$id = $repo->createPage(null, 0, false);
					// The argument carries 2000-01-01 and must not win over the clock.
					$repo->saveTranslation(self::translation($id, 'cs', 'time-' . strlen($label), 'Time', PublicationStatus::Draft));

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
	}


	public static function translation(int $pageId, string $locale, string $path, string $title, PublicationStatus $status): PageTranslation
	{
		$slug = substr($path, (int) strrpos('/' . $path, '/'));

		return new PageTranslation($pageId, $locale, $title, $slug, $path, 'content', 'description', $status, new DateTimeImmutable('2000-01-01'));
	}
}
