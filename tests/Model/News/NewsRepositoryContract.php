<?php

declare(strict_types=1);

namespace Tests\Model\News;

use App\Model\InvalidSlugException;
use App\Model\News\News;
use App\Model\News\NewsListItem;
use App\Model\News\NewsRepository;
use App\Model\News\NewsState;
use App\Model\News\NewsTranslation;
use App\Model\PublicationStatus;
use DateTimeImmutable;
use DateTimeZone;
use Tester\Assert;

use function test;

/** Behaviour every NewsRepository implementation must share. */
final class NewsRepositoryContract
{
	/**
	 * @param callable(): void $reset empties the store between scenarios
	 * @param callable(DateTimeImmutable): void $setNow moves the clock of the repository
	 */
	public static function run(NewsRepository $repo, callable $reset, callable $setNow): void
	{
		$now = new DateTimeImmutable('2026-01-15 12:00:00', new DateTimeZone('UTC'));

		test('visibility matrix: only a published translation dated now or earlier is listed, and NewsState::of() agrees', function () use ($repo, $reset, $setNow, $now): void {
			$reset();
			$setNow($now);
			$dates = ['none' => null, 'yesterday' => $now->modify('-1 day'), 'tomorrow' => $now->modify('+1 day'), 'now' => $now];
			$ids = [];
			foreach ([PublicationStatus::Draft, PublicationStatus::Published] as $status) {
				foreach ($dates as $label => $date) {
					$key = $status->value . '-' . $label;
					$id = $repo->createNews($date, null);
					$repo->saveTranslation(self::translation($id, 'cs', $key, $status));
					$ids[$key] = $id;
				}
			}
			$missing = $repo->createNews($dates['yesterday'], null);
			$repo->saveTranslation(self::translation($missing, 'en', 'only-english', PublicationStatus::Published));
			$ids['missing'] = $missing;

			$expected = [
				'draft-none' => NewsState::Draft,
				'draft-yesterday' => NewsState::Draft,
				'draft-tomorrow' => NewsState::Draft,
				'draft-now' => NewsState::Draft,
				'published-none' => NewsState::Draft,
				'published-yesterday' => NewsState::Visible,
				'published-tomorrow' => NewsState::Scheduled,
				'published-now' => NewsState::Visible,
				'missing' => NewsState::Missing,
			];

			$listed = array_map(static fn (NewsListItem $i): int => $i->news->id, $repo->findVisible('cs', 100, 0));
			Assert::same([$ids['published-now'], $ids['published-yesterday']], $listed);
			Assert::same(2, $repo->countVisible('cs'));

			foreach ($ids as $key => $id) {
				$news = $repo->getNews($id);
				assert($news !== null);
				$state = NewsState::of($news, $repo->findTranslation($id, 'cs'), $now);
				Assert::same($expected[$key], $state, $key);
				Assert::same(in_array($id, $listed, true), $state === NewsState::Visible, $key);
				Assert::same($state === NewsState::Visible, $repo->findVisibleBySlug('cs', $key) !== null, $key);
			}
		});

		test('a scheduled item appears once the clock passes its date', function () use ($repo, $reset, $setNow, $now): void {
			$reset();
			$setNow($now);
			$id = $repo->createNews($now->modify('+1 hour'), null);
			$repo->saveTranslation(self::translation($id, 'cs', 'pozdeji', PublicationStatus::Published));
			Assert::same(0, $repo->countVisible('cs'));
			Assert::null($repo->findVisibleBySlug('cs', 'pozdeji'));

			$setNow($now->modify('+1 hour'));
			Assert::same(1, $repo->countVisible('cs'));
			Assert::same($id, $repo->findVisibleBySlug('cs', 'pozdeji')?->news->id);
		});

		test('visible items are ordered by date then id, both descending, and paged by limit and offset', function () use ($repo, $reset, $setNow, $now): void {
			$reset();
			$setNow($now);
			$old = $repo->createNews($now->modify('-3 days'), null);
			$sameA = $repo->createNews($now->modify('-1 day'), null);
			$sameB = $repo->createNews($now->modify('-1 day'), null);
			$newest = $repo->createNews($now->modify('-1 hour'), null);
			foreach ([$old => 'old', $sameA => 'same-a', $sameB => 'same-b', $newest => 'newest'] as $id => $slug) {
				$repo->saveTranslation(self::translation($id, 'cs', $slug, PublicationStatus::Published));
			}

			$ids = self::listedIds(...);
			Assert::same([$newest, $sameB, $sameA, $old], $ids($repo->findVisible('cs', 10, 0)));
			Assert::same([$newest, $sameB], $ids($repo->findVisible('cs', 2, 0)));
			Assert::same([$sameA, $old], $ids($repo->findVisible('cs', 2, 2)));
			Assert::same([], $ids($repo->findVisible('cs', 2, 4)));
			Assert::same(4, $repo->countVisible('cs'));
		});

		test('locales do not mix: listing, count and slug lookup stay in their locale', function () use ($repo, $reset, $setNow, $now): void {
			$reset();
			$setNow($now);
			$both = $repo->createNews($now->modify('-1 day'), null);
			$repo->saveTranslation(self::translation($both, 'cs', 'spolecna', PublicationStatus::Published, 'Česky'));
			$repo->saveTranslation(self::translation($both, 'en', 'shared', PublicationStatus::Published, 'English'));
			$csOnly = $repo->createNews($now->modify('-1 day'), null);
			$repo->saveTranslation(self::translation($csOnly, 'cs', 'jen-cesky', PublicationStatus::Published));

			Assert::same(2, $repo->countVisible('cs'));
			Assert::same(1, $repo->countVisible('en'));
			$en = $repo->findVisible('en', 10, 0);
			Assert::same('English', $en[0]->translation->title);
			Assert::same('en', $en[0]->translation->locale);
			Assert::null($repo->findVisibleBySlug('en', 'spolecna'));
			Assert::null($repo->findVisibleBySlug('cs', 'shared'));
			Assert::same('Česky', $repo->findVisibleBySlug('cs', 'spolecna')?->translation->title);
			Assert::same(0, $repo->countVisible('de'));
		});

		test('stored values round-trip, times in UTC', function () use ($repo, $reset, $setNow, $now): void {
			$reset();
			$setNow($now);
			$published = new DateTimeImmutable('2026-01-01 00:30:00', new DateTimeZone('Europe/Prague'));
			$id = $repo->createNews($published, null);
			$repo->saveTranslation(new NewsTranslation($id, 'cs', 'Titulek', 'titulek', 'Perex', 'Obsah', 'Meta', PublicationStatus::Published, $now, 7));

			$news = $repo->getNews($id);
			assert($news !== null);
			Assert::same('2025-12-31 23:30:00 UTC', $news->publishedAt?->format('Y-m-d H:i:s e'));
			Assert::null($news->authorId);
			Assert::same(1, $news->version);
			Assert::same('2026-01-15 12:00:00 UTC', $news->createdAt->format('Y-m-d H:i:s e'));

			$t = $repo->findTranslation($id, 'cs');
			assert($t !== null);
			Assert::same([$id, 'cs', 'Titulek', 'titulek', 'Perex', 'Obsah', 'Meta', PublicationStatus::Published, 1], [$t->newsId, $t->locale, $t->title, $t->slug, $t->perex, $t->content, $t->metaDescription, $t->status, $t->version]);
			Assert::same('2026-01-15 12:00:00 UTC', $t->updatedAt->format('Y-m-d H:i:s e'));
			Assert::null($repo->getNews($id + 1000));
			Assert::null($repo->findTranslation($id, 'en'));
			Assert::null($repo->lockNews($id + 1000));
			Assert::same($id, $repo->lockNews($id)?->id);
		});

		test('translation version goes 1, 2 and ignores the argument; findTranslations is keyed by locale', function () use ($repo, $reset): void {
			$reset();
			$id = $repo->createNews(null, null);
			$repo->saveTranslation(self::translation($id, 'cs', 'a', PublicationStatus::Draft));
			Assert::same(1, $repo->findTranslation($id, 'cs')?->version);
			$repo->saveTranslation(self::translation($id, 'cs', 'a', PublicationStatus::Draft, 'A2'));
			Assert::same(2, $repo->lockTranslation($id, 'cs')?->version);
			Assert::same('A2', $repo->findTranslation($id, 'cs')?->title);
			$repo->saveTranslation(self::translation($id, 'en', 'a', PublicationStatus::Draft));

			$all = $repo->findTranslations($id);
			Assert::same(['cs', 'en'], array_keys($all));
			Assert::same(1, $all['en']->version);
			Assert::same([], $repo->findTranslations($id + 1000));
			Assert::null($repo->lockTranslation($id, 'de'));
			Assert::same(1, $repo->getNews($id)?->version);
		});

		test('updateNews writes the date and bumps the version, setPublishedAt leaves the version alone', function () use ($repo, $reset, $now): void {
			$reset();
			$id = $repo->createNews(null, null);
			$news = $repo->getNews($id);
			assert($news !== null);

			$repo->updateNews(new News($id, $now, $news->authorId, 99, $news->createdAt));
			$updated = $repo->getNews($id);
			Assert::same(2, $updated?->version);
			Assert::same($now->getTimestamp(), $updated?->publishedAt?->getTimestamp());

			$repo->updateNews(new News($id, null, $news->authorId, 2, $news->createdAt));
			Assert::null($repo->getNews($id)?->publishedAt);
			Assert::same(3, $repo->getNews($id)?->version);

			$repo->setPublishedAt($id, $now->modify('+1 day'));
			Assert::same($now->modify('+1 day')->getTimestamp(), $repo->getNews($id)?->publishedAt?->getTimestamp());
			Assert::same(3, $repo->getNews($id)?->version);
		});

		test('a slug taken in the locale is InvalidSlugException taken and writes nothing; isSlugTaken agrees', function () use ($repo, $reset): void {
			$reset();
			$first = $repo->createNews(null, null);
			$second = $repo->createNews(null, null);
			$repo->saveTranslation(self::translation($first, 'cs', 'stejny', PublicationStatus::Draft));

			$e = Assert::exception(fn () => $repo->saveTranslation(self::translation($second, 'cs', 'stejny', PublicationStatus::Draft)), InvalidSlugException::class);
			assert($e instanceof InvalidSlugException);
			Assert::same(InvalidSlugException::TAKEN, $e->reason);
			Assert::null($repo->findTranslation($second, 'cs'));

			$repo->saveTranslation(self::translation($second, 'cs', 'jiny', PublicationStatus::Draft));
			$e = Assert::exception(fn () => $repo->saveTranslation(self::translation($second, 'cs', 'stejny', PublicationStatus::Draft, 'X')), InvalidSlugException::class);
			assert($e instanceof InvalidSlugException);
			Assert::same(InvalidSlugException::TAKEN, $e->reason);
			Assert::same('jiny', $repo->findTranslation($second, 'cs')?->slug);

			$repo->saveTranslation(self::translation($second, 'en', 'stejny', PublicationStatus::Draft));
			Assert::true($repo->isSlugTaken('cs', 'stejny', null));
			Assert::true($repo->isSlugTaken('cs', 'stejny', $second));
			Assert::false($repo->isSlugTaken('cs', 'stejny', $first));
			Assert::false($repo->isSlugTaken('de', 'stejny', null));
			Assert::false($repo->isSlugTaken('cs', 'STEJNY', null));
		});

		test('deleteNews removes the item with its translations', function () use ($repo, $reset): void {
			$reset();
			$id = $repo->createNews(null, null);
			$kept = $repo->createNews(null, null);
			$repo->saveTranslation(self::translation($id, 'cs', 'pryc', PublicationStatus::Draft));
			$repo->saveTranslation(self::translation($kept, 'cs', 'zustava', PublicationStatus::Draft));
			$repo->deleteNews($id);

			Assert::null($repo->getNews($id));
			Assert::same([], $repo->findTranslations($id));
			Assert::false($repo->isSlugTaken('cs', 'pryc', null));
			Assert::notNull($repo->findTranslation($kept, 'cs'));
		});

		test('admin list puts undated items first, then date and id descending, and is paged', function () use ($repo, $reset, $now): void {
			$reset();
			$old = $repo->createNews($now->modify('-2 days'), null);
			$undatedA = $repo->createNews(null, null);
			$future = $repo->createNews($now->modify('+2 days'), null);
			$undatedB = $repo->createNews(null, null);
			$sameA = $repo->createNews($now, null);
			$sameB = $repo->createNews($now, null);

			$ids = self::newsIds(...);
			Assert::same([$undatedB, $undatedA, $future, $sameB, $sameA, $old], $ids($repo->findAll(10, 0)));
			Assert::same([$future, $sameB], $ids($repo->findAll(2, 2)));
			Assert::same(6, $repo->countAll());
		});
	}


	/**
	 * @param list<NewsListItem> $items
	 * @return list<int>
	 */
	private static function listedIds(array $items): array
	{
		return array_map(static fn (NewsListItem $i): int => $i->news->id, $items);
	}


	/**
	 * @param list<News> $items
	 * @return list<int>
	 */
	private static function newsIds(array $items): array
	{
		return array_map(static fn (News $n): int => $n->id, $items);
	}


	private static function translation(int $newsId, string $locale, string $slug, PublicationStatus $status, string $title = 'T'): NewsTranslation
	{
		return new NewsTranslation($newsId, $locale, $title, $slug, 'Perex', '', '', $status, new DateTimeImmutable(), 1);
	}
}
