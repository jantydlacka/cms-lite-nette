<?php

declare(strict_types=1);

use App\Core\Database\ExplorerTransaction;
use App\Model\CmsConfig;
use App\Model\InvalidSlugException;
use App\Model\News\CreateNews;
use App\Model\News\Database\DatabaseNewsRepository;
use App\Model\News\NewsService;
use App\Model\News\SaveNewsTranslation;
use App\Model\News\StaleVersionException;
use App\Model\News\UpdateNews;
use App\Model\Page\CreatePage;
use App\Model\Page\PageService;
use App\Model\Page\SavePageTranslation;
use App\Model\PublicationStatus;
use App\Model\Redirect\Database\DatabasePathRedirectRepository;
use App\Model\Redirect\PathRedirectRepository;
use App\Model\Redirect\RedirectTarget;
use App\Model\Transaction;
use Nette\Database\Connection;
use Nette\Database\Explorer;
use Tester\Assert;
use Tests\Support\FrozenClock;
use Tests\Support\TestDatabase;
use function Tests\container;

require __DIR__ . '/../../bootstrap.php';

$container = container();
$explorer = $container->getByType(Explorer::class);
$transaction = $container->getByType(Transaction::class);
$clock = new FrozenClock(new DateTimeImmutable('2026-01-15 12:00:00', new DateTimeZone('UTC')));
$news = new DatabaseNewsRepository($explorer, $clock);
$redirects = new DatabasePathRedirectRepository($explorer, $clock);
$config = new CmsConfig('Site', 'cs', ['cs', 'en'], 10);
$service = new NewsService($news, $redirects, $transaction, $config, $clock);
$reset = static function () use ($container, $explorer, $clock): int {
	TestDatabase::prepare($container);
	$clock->set(new DateTimeImmutable('2026-01-15 12:00:00', new DateTimeZone('UTC')));
	$explorer->query('INSERT INTO `user` ?', ['email' => 'a@example.com', 'password_hash' => 'h', 'name' => 'Ann', 'role' => 'editor', 'created_at' => '2026-01-01 00:00:00']);

	return (int) $explorer->getInsertId();
};

$create = static fn (int $authorId, string $slug, PublicationStatus $status = PublicationStatus::Published, ?DateTimeImmutable $publishedAt = null, string $title = 'Title', string $perex = 'Perex', string $locale = 'cs'): CreateNews => new CreateNews(
	$publishedAt,
	$authorId,
	$locale,
	$title,
	$slug,
	$perex,
	'content',
	'description',
	$status,
);
$cmd = static fn (int $newsId, string $slug, string $locale = 'cs', ?int $version = null, PublicationStatus $status = PublicationStatus::Published, string $perex = 'Perex', string $title = 'Title', string $meta = 'description'): SaveNewsTranslation => new SaveNewsTranslation(
	$newsId,
	$locale,
	$title,
	$slug,
	$perex,
	'content',
	$meta,
	$status,
	$version,
);
$snapshot = static fn (): array => array_map(
	static fn (string $sql): array => array_map(
		static fn ($row): array => array_map(static fn ($v) => $v instanceof DateTimeInterface ? $v->format('Y-m-d H:i:s') : $v, (array) $row),
		$explorer->query($sql)->fetchAll(),
	),
	[
		'SELECT * FROM news ORDER BY id',
		'SELECT * FROM news_translation ORDER BY news_id, locale',
		'SELECT * FROM path_redirect ORDER BY locale, old_path',
	],
);
$publishedAt = static fn (int $id): ?string => $news->getNews($id)?->publishedAt?->format('Y-m-d H:i:s');
$redirect = static function (string $locale, string $path) use ($redirects): ?string {
	$target = $redirects->findTarget($locale, $path);

	return $target === null ? null : $target->type . ':' . $target->id;
};

test('creating with an empty slug derives it from the title and stores author and first translation', function () use ($reset, $service, $create, $news): void {
	$author = $reset();
	$saved = $service->createNews($create($author, '', title: '  Letní akce 2026 '));

	Assert::same('letni-akce-2026', $saved->slug);
	Assert::same('Letní akce 2026', $saved->title);
	Assert::same(1, $saved->version);
	Assert::same($author, $news->getNews($saved->newsId)?->authorId);
	Assert::same(1, $news->getNews($saved->newsId)?->version);
});

test('creating with an empty date: published dates the item now, a draft leaves it undated', function () use ($reset, $service, $create, $publishedAt): void {
	$author = $reset();
	$published = $service->createNews($create($author, 'a'));
	$draft = $service->createNews($create($author, 'b', PublicationStatus::Draft));

	Assert::same('2026-01-15 12:00:00', $publishedAt($published->newsId));
	Assert::null($publishedAt($draft->newsId));
});

test('creating with a date keeps it', function () use ($reset, $service, $create, $publishedAt): void {
	$author = $reset();
	$saved = $service->createNews($create($author, 'a', publishedAt: new DateTimeImmutable('2026-03-01 08:00:00', new DateTimeZone('UTC'))));
	Assert::same('2026-03-01 08:00:00', $publishedAt($saved->newsId));
});

test('a failing first translation leaves no news item', function () use ($reset, $service, $create, $snapshot): void {
	$author = $reset();
	$service->createNews($create($author, 'zabrano'));
	$before = $snapshot();

	$e = Assert::exception(fn () => $service->createNews($create($author, 'zabrano')), InvalidSlugException::class);
	assert($e instanceof InvalidSlugException);
	Assert::same(InvalidSlugException::TAKEN, $e->reason);
	Assert::same($before, $snapshot());
});

test('publishing a translation later dates an undated item now without raising its version', function () use ($reset, $service, $create, $cmd, $news, $clock, $publishedAt): void {
	$author = $reset();
	$draft = $service->createNews($create($author, 'a', PublicationStatus::Draft));
	$clock->set(new DateTimeImmutable('2026-02-01 09:30:00', new DateTimeZone('UTC')));

	$saved = $service->saveTranslation($cmd($draft->newsId, 'a', version: 1));
	Assert::same(2, $saved->version);
	Assert::same('2026-02-01 09:30:00', $publishedAt($draft->newsId));
	Assert::same(1, $news->getNews($draft->newsId)?->version);

	// Already dated: a later publication keeps the date.
	$clock->set(new DateTimeImmutable('2026-03-01 10:00:00', new DateTimeZone('UTC')));
	$service->saveTranslation($cmd($draft->newsId, 'a', 'en'));
	Assert::same('2026-02-01 09:30:00', $publishedAt($draft->newsId));
	Assert::same(1, $news->getNews($draft->newsId)?->version);
});

test('saving a draft translation leaves the item undated', function () use ($reset, $service, $create, $cmd, $publishedAt): void {
	$author = $reset();
	$draft = $service->createNews($create($author, 'a', PublicationStatus::Draft));
	$service->saveTranslation($cmd($draft->newsId, 'a', version: 1, status: PublicationStatus::Draft));
	Assert::null($publishedAt($draft->newsId));
});

test('updateNews writes the date and raises the version', function () use ($reset, $service, $create, $publishedAt): void {
	$author = $reset();
	$id = $service->createNews($create($author, 'a'))->newsId;
	$updated = $service->updateNews(new UpdateNews($id, new DateTimeImmutable('2026-05-05 05:05:00', new DateTimeZone('UTC')), 1));

	Assert::same(2, $updated->version);
	Assert::same('2026-05-05 05:05:00', $publishedAt($id));
});

test('updateNews with an empty date: now when a translation is published, undated otherwise', function () use ($reset, $service, $create, $clock, $publishedAt): void {
	$author = $reset();
	$published = $service->createNews($create($author, 'a', publishedAt: new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC'))))->newsId;
	$draft = $service->createNews($create($author, 'b', PublicationStatus::Draft, new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC'))))->newsId;
	$clock->set(new DateTimeImmutable('2026-02-02 02:02:00', new DateTimeZone('UTC')));

	Assert::same(2, $service->updateNews(new UpdateNews($published, null, 1))->version);
	Assert::same('2026-02-02 02:02:00', $publishedAt($published));
	Assert::same(2, $service->updateNews(new UpdateNews($draft, null, 1))->version);
	Assert::null($publishedAt($draft));
});

test('a stale translation or news version writes nothing', function () use ($reset, $service, $create, $cmd, $snapshot): void {
	$author = $reset();
	$id = $service->createNews($create($author, 'a', PublicationStatus::Draft))->newsId;
	$service->saveTranslation($cmd($id, 'b', version: 1, status: PublicationStatus::Draft));
	$before = $snapshot();

	$writes = [
		'existing translation, other version' => static fn () => $service->saveTranslation($cmd($id, 'c', version: 1)),
		'existing translation, no version' => static fn () => $service->saveTranslation($cmd($id, 'c')),
		'missing translation, a version' => static fn () => $service->saveTranslation($cmd($id, 'c', 'en', 1)),
		'news, other version' => static fn () => $service->updateNews(new UpdateNews($id, null, 2)),
	];
	foreach ($writes as $label => $write) {
		Assert::exception($write, StaleVersionException::class);
		Assert::same($before, $snapshot(), $label);
	}
});

test('changing the slug redirects the old segment path to the item per locale; the new slug clears its own path', function () use ($reset, $service, $create, $cmd, $redirect, $snapshot): void {
	$author = $reset();
	$id = $service->createNews($create($author, 'stary'))->newsId;
	$service->saveTranslation($cmd($id, 'old', 'en'));

	$service->saveTranslation($cmd($id, 'novy', version: 1));
	$service->saveTranslation($cmd($id, 'new', 'en', 1));
	Assert::same('news:' . $id, $redirect('cs', 'novinky/stary'));
	Assert::same('news:' . $id, $redirect('en', 'news/old'));
	Assert::null($redirect('cs', 'stary'));
	Assert::null($redirect('en', 'novinky/old'));

	// Same slug again: no new redirect.
	$before = $snapshot()[2];
	$service->saveTranslation($cmd($id, 'novy', version: 2));
	Assert::same($before, $snapshot()[2]);

	// Back to the old slug: its path stops redirecting, the abandoned one starts.
	$service->saveTranslation($cmd($id, 'stary', version: 3));
	Assert::null($redirect('cs', 'novinky/stary'));
	Assert::same('news:' . $id, $redirect('cs', 'novinky/novy'));
});

test('another item taking an old slug wins: the redirect from that path is deleted', function () use ($reset, $service, $create, $cmd, $redirect, $news): void {
	$author = $reset();
	$a = $service->createNews($create($author, 'stary'))->newsId;
	$service->saveTranslation($cmd($a, 'novy', version: 1));
	Assert::same('news:' . $a, $redirect('cs', 'novinky/stary'));

	$b = $service->createNews($create($author, 'stary'))->newsId;
	Assert::null($redirect('cs', 'novinky/stary'));
	Assert::same($b, $news->findVisibleBySlug('cs', 'stary')?->news->id);
});

test('a page and a news item with the same slug do not collide', function () use ($reset, $service, $create, $cmd, $redirect, $container): void {
	$author = $reset();
	$pages = $container->getByType(PageService::class);
	$page = $pages->createPage(new CreatePage(null, true, 'cs', 'Akce', 'akce', 'c', '', PublicationStatus::Published));
	$pages->saveTranslation(new SavePageTranslation($page->pageId, 'cs', 'Akce', 'akce-2', 'c', '', PublicationStatus::Published, 1));
	Assert::same('page:' . $page->pageId, $redirect('cs', 'akce'));

	$id = $service->createNews($create($author, 'akce'))->newsId;
	Assert::same('page:' . $page->pageId, $redirect('cs', 'akce'));
	$service->saveTranslation($cmd($id, 'akce-3', version: 1));
	Assert::same('news:' . $id, $redirect('cs', 'novinky/akce'));
	Assert::same('page:' . $page->pageId, $redirect('cs', 'akce'));
});

test('deleteNews removes the item, its translations and its redirects only', function () use ($reset, $service, $create, $cmd, $redirect, $explorer, $news): void {
	$author = $reset();
	$a = $service->createNews($create($author, 'a'))->newsId;
	$service->saveTranslation($cmd($a, 'a', 'en'));
	$service->saveTranslation($cmd($a, 'a2', version: 1));
	$service->saveTranslation($cmd($a, 'a3', 'en', 1));
	$b = $service->createNews($create($author, 'b'))->newsId;
	$service->saveTranslation($cmd($b, 'b2', version: 1));

	Assert::same('news:' . $a, $redirect('cs', 'novinky/a'));
	Assert::same('news:' . $a, $redirect('en', 'news/a'));

	$service->deleteNews($a);
	Assert::null($redirect('cs', 'novinky/a'));
	Assert::null($redirect('en', 'news/a'));
	Assert::same('news:' . $b, $redirect('cs', 'novinky/b'));
	Assert::null($news->getNews($a));
	Assert::same(1, $explorer->table('news')->count('*'));
	Assert::same(1, $explorer->table('news_translation')->count('*'));
});

test('perex, title, meta description and locale are checked and nothing is written', function () use ($reset, $service, $create, $cmd, $snapshot): void {
	$author = $reset();
	$id = $service->createNews($create($author, 'a', PublicationStatus::Draft))->newsId;
	$before = $snapshot();

	$cases = [
		'empty perex' => $cmd($id, 'a', version: 1, perex: ''),
		'blank perex' => $cmd($id, 'a', version: 1, perex: "  \n "),
		'301-character perex' => $cmd($id, 'a', version: 1, perex: str_repeat('ř', 301)),
		'blank title' => $cmd($id, 'a', version: 1, title: ' '),
		'201-character title' => $cmd($id, 'a', version: 1, title: str_repeat('ř', 201)),
		'301-character meta' => $cmd($id, 'a', version: 1, meta: str_repeat('ř', 301)),
		'unknown locale' => $cmd($id, 'a', 'de'),
		'unknown news item' => $cmd(999, 'a'),
	];
	foreach ($cases as $label => $command) {
		Assert::exception(fn () => $service->saveTranslation($command), InvalidArgumentException::class);
		Assert::same($before, $snapshot(), $label);
	}
	foreach (['empty perex' => '', '301-character perex' => str_repeat('ř', 301)] as $label => $perex) {
		Assert::exception(fn () => $service->createNews($create($author, 'x', perex: $perex)), InvalidArgumentException::class);
		Assert::same($before, $snapshot(), $label);
	}
	Assert::exception(fn () => $service->updateNews(new UpdateNews(999, null, 1)), InvalidArgumentException::class);
	Assert::exception(fn () => $service->deleteNews(999), InvalidArgumentException::class);
	Assert::same($before, $snapshot());

	$saved = $service->saveTranslation($cmd($id, 'a', version: 1, perex: ' ' . str_repeat('ř', 300) . ' ', meta: str_repeat('ř', 300)));
	Assert::same(str_repeat('ř', 300), $saved->perex);
});

test('slug format, length and taken are reported and nothing is written', function () use ($reset, $service, $create, $cmd, $snapshot): void {
	$author = $reset();
	$id = $service->createNews($create($author, 'a'))->newsId;
	$service->createNews($create($author, 'zabrano'));
	$before = $snapshot();

	foreach (['O-nas' => 'format', 'a--b' => 'format', str_repeat('a', 101) => 'long', 'zabrano' => 'taken'] as $slug => $reason) {
		$e = Assert::exception(fn () => $service->saveTranslation($cmd($id, $slug, version: 1)), InvalidSlugException::class);
		assert($e instanceof InvalidSlugException);
		Assert::same($reason, $e->reason, $slug);
		Assert::same($before, $snapshot(), $slug);
	}
	// The same slug in another locale is fine; "novinky" is not reserved under the segment.
	Assert::same('zabrano', $service->saveTranslation($cmd($id, 'zabrano', 'en'))->slug);
	Assert::same('novinky', $service->saveTranslation($cmd($id, 'novinky', version: 1))->slug);
});

test('every write refuses to run inside an already open transaction and writes nothing', function () use ($reset, $service, $create, $cmd, $transaction, $snapshot): void {
	$author = $reset();
	$id = $service->createNews($create($author, 'a'))->newsId;
	$before = $snapshot();
	$writes = [
		'createNews' => static fn () => $service->createNews($create($author, 'b')),
		'saveTranslation' => static fn () => $service->saveTranslation($cmd($id, 'c', version: 1)),
		'updateNews' => static fn () => $service->updateNews(new UpdateNews($id, null, 1)),
		'deleteNews' => static fn () => $service->deleteNews($id),
	];
	foreach ($writes as $label => $write) {
		$thrown = null;
		try {
			$transaction->run($write);
		} catch (Throwable $thrown) {
		}
		Assert::type(LogicException::class, $thrown, $label);
		assert($thrown instanceof LogicException);
		Assert::match('News writes must start their own transaction%a%', $thrown->getMessage(), $label);
		Assert::same($before, $snapshot(), $label);
	}
});

test('two items created at the same time do not block each other', function () use ($reset, $service, $create, $news, $container, $explorer, $clock, $config): void {
	$author = $reset();
	$service->createNews($create($author, 'existing'));

	// A second service on its own connection creates item B; while it holds B's row lock and has read B's missing
	// translation, the hook creates item A on the main connection. Both missing translations fall into the same index
	// gap, so a gap lock taken by B's read would block A's insert (and in production deadlock the pair).
	$params = $container->getParameters();
	assert(is_string($params['dbName']) && is_string($params['dbHost']) && is_string($params['dbPort']) && is_string($params['dbUser']) && is_string($params['dbPassword']));
	$otherExplorer = new Explorer(
		new Connection('mysql:host=' . $params['dbHost'] . ';port=' . $params['dbPort'] . ';dbname=' . $params['dbName'], $params['dbUser'], $params['dbPassword']),
		$explorer->getStructure(),
		$explorer->getConventions(),
	);
	$failure = null;
	$hook = static function () use ($service, $create, $author, &$failure): void {
		try {
			$service->createNews($create($author, 'a'));
		} catch (Throwable $e) {
			$failure = $e;
		}
	};
	$hookedRedirects = new class(new DatabasePathRedirectRepository($otherExplorer, $clock), $hook) implements PathRedirectRepository {
		/** @param Closure(): void $hook */
		public function __construct(private PathRedirectRepository $inner, private ?Closure $hook)
		{
		}


		public function findTarget(string $locale, string $path): ?RedirectTarget
		{
			return $this->inner->findTarget($locale, $path);
		}


		public function record(string $locale, string $oldPath, RedirectTarget $target): void
		{
			$this->inner->record($locale, $oldPath, $target);
		}


		public function deleteByPath(string $locale, string $path): void
		{
			$hook = $this->hook;
			$this->hook = null;
			if ($hook !== null) {
				$hook();
			}
			$this->inner->deleteByPath($locale, $path);
		}


		public function deleteForTarget(RedirectTarget $target): void
		{
			$this->inner->deleteForTarget($target);
		}
	};
	$other = new NewsService(new DatabaseNewsRepository($otherExplorer, $clock), $hookedRedirects, new ExplorerTransaction($otherExplorer), $config, $clock);

	$explorer->query('SET SESSION innodb_lock_wait_timeout = 1');
	try {
		$other->createNews($create($author, 'b'));
	} finally {
		$explorer->query('SET SESSION innodb_lock_wait_timeout = DEFAULT');
	}

	Assert::null($failure);
	Assert::notNull($news->findVisibleBySlug('cs', 'a'));
	Assert::notNull($news->findVisibleBySlug('cs', 'b'));
});

test('the container autowires NewsService', function () use ($container): void {
	Assert::type(NewsService::class, $container->getByType(NewsService::class));
});
