<?php

declare(strict_types=1);

use App\Model\CmsConfig;
use App\Model\Page\CreatePage;
use App\Model\Page\Database\DatabasePageRepository;
use App\Model\Page\FlatPagePathStrategy;
use App\Model\Page\InvalidParentException;
use App\Model\Page\InvalidSlugException;
use App\Model\Page\Page;
use App\Model\Page\PagePathStrategy;
use App\Model\Page\PageRepository;
use App\Model\Page\PageService;
use App\Model\Page\PageTranslation;
use App\Model\Page\PageTreeLock;
use App\Model\Page\ReservedSegments;
use App\Model\Page\SavePageTranslation;
use App\Model\Page\StaleVersionException;
use App\Model\Page\UpdatePage;
use App\Model\PublicationStatus;
use App\Model\Redirect\Database\DatabasePathRedirectRepository;
use App\Model\Redirect\PathRedirectRepository;
use App\Model\Redirect\RedirectTarget;
use App\Model\Transaction;
use Nette\Database\Explorer;
use Nette\Database\UniqueConstraintViolationException;
use Tester\Assert;
use Tests\Model\Page\DelegatingPageRepository;
use Tests\Model\Page\RecordingPageRepository;
use Tests\Support\FrozenClock;
use Tests\Support\TestDatabase;
use function Tests\container;

require __DIR__ . '/../../bootstrap.php';
require __DIR__ . '/DelegatingPageRepository.php';
require __DIR__ . '/RecordingPageRepository.php';

$container = container();
$explorer = $container->getByType(Explorer::class);
$transaction = $container->getByType(Transaction::class);
$treeLock = $container->getByType(PageTreeLock::class);
$clock = new FrozenClock(new DateTimeImmutable('2026-01-15 12:00:00', new DateTimeZone('UTC')));
$pages = new DatabasePageRepository($explorer, $clock);
$redirects = new DatabasePathRedirectRepository($explorer, $clock);
$config = new CmsConfig('Site', 'cs', ['cs', 'en'], 10);
$reserved = new ReservedSegments($config);
$reset = static fn () => TestDatabase::prepare($container);

$service = static fn (?PageRepository $repo = null, ?PagePathStrategy $strategy = null, ?PageTreeLock $lock = null): PageService => new PageService(
	$repo ?? $pages,
	$redirects,
	$strategy ?? new FlatPagePathStrategy(),
	$reserved,
	$transaction,
	$lock ?? $treeLock,
	$config,
	$clock,
);
$cmd = static fn (int $pageId, string $slug, string $locale = 'cs', string $title = 'Title', string $meta = 'description', ?int $version = null): SavePageTranslation => new SavePageTranslation(
	$pageId,
	$locale,
	$title,
	$slug,
	'content',
	$meta,
	PublicationStatus::Published,
	$version,
);
$create = static fn (?int $parentId, string $slug, string $title = 'Title', bool $inMenu = true): CreatePage => new CreatePage(
	$parentId,
	$inMenu,
	'cs',
	$title,
	$slug,
	'content',
	'description',
	PublicationStatus::Published,
);
$counts = static fn (): array => [
	$explorer->table('page_translation')->count('*'),
	$explorer->table('path_redirect')->count('*'),
];
$pageCount = static fn (): int => $explorer->table('page')->count('*');
/** @return array<int, int> position by id, in sibling order */
$positions = static function (?int $parentId) use ($pages): array {
	$byId = [];
	foreach ($pages->findChildren($parentId) as $page) {
		$byId[$page->id] = $page->position;
	}

	return $byId;
};
$snapshot = static fn (): array => array_map(
	static fn ($row): array => $row->toArray(),
	$explorer->table('page')->select('id, parent_id, position, in_menu, version')->order('id')->fetchAll(),
);
$fullSnapshot = static fn (): array => array_map(
	static fn (string $sql): array => array_map(
		static fn ($row): array => array_map(static fn ($v) => $v instanceof DateTimeInterface ? $v->format('Y-m-d H:i:s') : $v, (array) $row),
		$explorer->query($sql)->fetchAll(),
	),
	[
		'SELECT id, parent_id, position, in_menu, version, updated_at FROM page ORDER BY id',
		'SELECT * FROM page_translation ORDER BY page_id, locale',
		'SELECT * FROM path_redirect ORDER BY locale, old_path',
	],
);
$tree = new class() implements PagePathStrategy {
	/** @param list<string> $ancestorSlugs */
	public function pathFor(string $slug, array $ancestorSlugs): string
	{
		return implode('/', [...$ancestorSlugs, $slug]);
	}
};
$redirectId = static fn (string $locale, string $path): ?int => $redirects->findTarget($locale, $path)?->id;

test('a valid slug is saved as translation with path equal to slug', function () use ($reset, $service, $cmd, $pages): void {
	$reset();
	$id = $pages->createPage(null, 0, true);
	$saved = $service()->saveTranslation($cmd($id, 'o-nas'));

	Assert::same('o-nas', $saved->path);
	Assert::same('o-nas', $saved->slug);
	Assert::same($id, $pages->findPublishedByPath('cs', 'o-nas')?->pageId);
});

test('an unknown page is an InvalidArgumentException and nothing is written', function () use ($reset, $service, $cmd, $counts): void {
	$reset();
	Assert::exception(fn () => $service()->saveTranslation($cmd(999, 'o-nas')), InvalidArgumentException::class);
	Assert::same([0, 0], $counts());
});

test('invalid formats, reserved segments and taken slugs fail with a reason and leave the database untouched', function () use ($reset, $service, $cmd, $pages, $counts): void {
	$reset();
	$id = $pages->createPage(null, 0, true);
	$other = $pages->createPage(null, 1, true);
	$service()->saveTranslation($cmd($other, 'zabrano'));
	$before = $counts();

	$cases = [
		'O-nas' => 'format',
		'o--nas' => 'format',
		str_repeat('a', 101) => 'long',
		'-x' => 'format',
		'x-' => 'format',
		'a b' => 'format',
		'admin' => 'reserved',
		'Novinky' => 'reserved', // reserved is judged before the format, case-insensitively
		'novinky' => 'reserved',
		'en' => 'reserved',
		'cs' => 'reserved',
		'news' => 'reserved',
		'sitemap.xml' => 'reserved',
		'zabrano' => 'taken',
	];
	foreach ($cases as $slug => $reason) {
		$e = Assert::exception(fn () => $service()->saveTranslation($cmd($id, $slug)), InvalidSlugException::class);
		assert($e instanceof InvalidSlugException);
		Assert::same($reason, $e->reason, $slug);
		Assert::same($before, $counts(), $slug);
	}
	Assert::same(str_repeat('a', 100), $service()->saveTranslation($cmd($id, str_repeat('a', 100)))->slug);
});

test('the same slug in another locale is fine', function () use ($reset, $service, $cmd, $pages): void {
	$reset();
	$a = $pages->createPage(null, 0, true);
	$b = $pages->createPage(null, 1, true);
	$service()->saveTranslation($cmd($a, 'kontakt', 'cs'));
	$service()->saveTranslation($cmd($b, 'kontakt', 'en'));

	Assert::same($a, $pages->findPublishedByPath('cs', 'kontakt')?->pageId);
	Assert::same($b, $pages->findPublishedByPath('en', 'kontakt')?->pageId);
});

test('saving again with the same slug updates the row without redirect', function () use ($reset, $service, $cmd, $pages, $counts): void {
	$reset();
	$id = $pages->createPage(null, 0, true);
	$service()->saveTranslation($cmd($id, 'o-nas', 'cs', 'One'));
	$service()->saveTranslation($cmd($id, 'o-nas', 'cs', 'Two', version: 1));

	Assert::same('Two', $pages->findTranslation($id, 'cs')?->title);
	Assert::same([1, 0], $counts());
});

test('a first save has no old path and records no redirect', function () use ($reset, $service, $cmd, $pages, $counts): void {
	$reset();
	$id = $pages->createPage(null, 0, true);
	$service()->saveTranslation($cmd($id, 'o-nas'));
	Assert::same([1, 0], $counts());
});

test('changing the slug records a redirect from the old path, changing back removes it', function () use ($reset, $service, $cmd, $pages, $redirectId, $counts): void {
	$reset();
	$id = $pages->createPage(null, 0, true);
	$service()->saveTranslation($cmd($id, 'o-nas'));
	$service()->saveTranslation($cmd($id, 'o-firme', version: 1));

	Assert::same($id, $redirectId('cs', 'o-nas'));
	Assert::null($redirectId('cs', 'o-firme'));

	$service()->saveTranslation($cmd($id, 'o-nas', version: 2));
	Assert::null($redirectId('cs', 'o-nas'));
	Assert::same($id, $redirectId('cs', 'o-firme'));
	Assert::same([1, 1], $counts());
});

test('redirects are per locale', function () use ($reset, $service, $cmd, $pages, $redirectId): void {
	$reset();
	$id = $pages->createPage(null, 0, true);
	$service()->saveTranslation($cmd($id, 'o-nas', 'cs'));
	$service()->saveTranslation($cmd($id, 'about', 'en'));
	$service()->saveTranslation($cmd($id, 'o-firme', 'cs', version: 1));

	Assert::same($id, $redirectId('cs', 'o-nas'));
	Assert::null($redirectId('en', 'o-nas'));
	Assert::null($redirectId('en', 'about'));
});

test('another page taking an old path wins: the redirect is deleted', function () use ($reset, $service, $cmd, $pages, $redirectId): void {
	$reset();
	$a = $pages->createPage(null, 0, true);
	$b = $pages->createPage(null, 1, true);
	$service()->saveTranslation($cmd($a, 'o-nas'));
	$service()->saveTranslation($cmd($a, 'o-firme', version: 1));
	Assert::same($a, $redirectId('cs', 'o-nas'));

	$service()->saveTranslation($cmd($b, 'o-nas'));
	Assert::null($redirectId('cs', 'o-nas'));
	Assert::same($b, $pages->findPublishedByPath('cs', 'o-nas')?->pageId);
});

test('an exception inside the transaction rolls back the redirect and the row', function () use ($reset, $service, $cmd, $pages, $counts): void {
	$reset();
	$id = $pages->createPage(null, 0, true);
	$service()->saveTranslation($cmd($id, 'o-nas'));
	$before = $counts();

	$failing = new DelegatingPageRepository($pages, static function (): void {
		throw new RuntimeException('boom');
	});
	Assert::exception(fn () => $service($failing)->saveTranslation($cmd($id, 'o-firme', version: 1)), RuntimeException::class, 'boom');

	Assert::same($before, $counts());
	Assert::same('o-nas', $pages->findTranslation($id, 'cs')?->path);
});

test('a database unique violation on another key than the path propagates unchanged', function () use ($reset, $service, $cmd, $pages, $counts): void {
	$reset();
	$id = $pages->createPage(null, 0, true);
	$before = $counts();
	$failing = new DelegatingPageRepository($pages, static function (): void {
		throw new UniqueConstraintViolationException("Duplicate entry '1-cs' for key 'page_translation.PRIMARY'");
	});

	Assert::exception(fn () => $service($failing)->saveTranslation($cmd($id, 'o-nas')), UniqueConstraintViolationException::class);
	Assert::same($before, $counts());
});

test('descendants are recomputed after the save, flat paths stay and no redirect is recorded for them', function () use ($reset, $service, $cmd, $pages, $counts, $redirectId): void {
	$reset();
	$parent = $pages->createPage(null, 0, true);
	$child = $pages->createPage($parent, 0, true);
	$service()->saveTranslation($cmd($parent, 'firma'));
	$service()->saveTranslation($cmd($child, 'tym'));

	$spy = new class() implements PagePathStrategy {
		/** @var list<array{string, list<string>}> */
		public array $calls = [];


		/** @param list<string> $ancestorSlugs */
		public function pathFor(string $slug, array $ancestorSlugs): string
		{
			$this->calls[] = [$slug, $ancestorSlugs];

			return (new FlatPagePathStrategy())->pathFor($slug, $ancestorSlugs);
		}
	};
	$service(null, $spy)->saveTranslation($cmd($parent, 'spolecnost', version: 1));

	Assert::same([['spolecnost', []], ['tym', ['spolecnost']]], $spy->calls);
	Assert::same('tym', $pages->findTranslation($child, 'cs')?->path);
	Assert::same($parent, $redirectId('cs', 'firma'));
	Assert::null($redirectId('cs', 'tym'));
	Assert::same([2, 1], $counts());
});

test('a hierarchical strategy moves descendants and redirects their old paths', function () use ($reset, $service, $cmd, $pages, $redirectId): void {
	$reset();
	$parent = $pages->createPage(null, 0, true);
	$child = $pages->createPage($parent, 0, true);
	$leaf = $pages->createPage($child, 0, true);
	$tree = new class() implements PagePathStrategy {
		/** @param list<string> $ancestorSlugs */
		public function pathFor(string $slug, array $ancestorSlugs): string
		{
			return implode('/', [...$ancestorSlugs, $slug]);
		}
	};
	$service(null, $tree)->saveTranslation($cmd($parent, 'firma'));
	$service(null, $tree)->saveTranslation($cmd($child, 'tym'));
	$service(null, $tree)->saveTranslation($cmd($leaf, 'lide'));
	Assert::same('firma/tym/lide', $pages->findTranslation($leaf, 'cs')?->path);

	$service(null, $tree)->saveTranslation($cmd($parent, 'spolecnost', version: 1));

	Assert::same('spolecnost/tym', $pages->findTranslation($child, 'cs')?->path);
	Assert::same('spolecnost/tym/lide', $pages->findTranslation($leaf, 'cs')?->path);
	Assert::same($child, $redirectId('cs', 'firma/tym'));
	Assert::same($leaf, $redirectId('cs', 'firma/tym/lide'));
	Assert::same($parent, $redirectId('cs', 'firma'));
});

test('a descendant path that collides rolls the whole save back', function () use ($reset, $service, $cmd, $pages, $counts): void {
	$reset();
	$parent = $pages->createPage(null, 0, true);
	$child = $pages->createPage($parent, 0, true);
	$squatter = $pages->createPage(null, 1, true);
	$tree = new class() implements PagePathStrategy {
		/** @param list<string> $ancestorSlugs */
		public function pathFor(string $slug, array $ancestorSlugs): string
		{
			return implode('/', [...$ancestorSlugs, $slug]);
		}
	};
	$service(null, $tree)->saveTranslation($cmd($parent, 'firma'));
	$service(null, $tree)->saveTranslation($cmd($child, 'tym'));
	$pages->saveTranslation(new PageTranslation($squatter, 'cs', 'S', 'tym', 'spolecnost/tym', '', '', PublicationStatus::Draft, new DateTimeImmutable(), 1));
	$before = $counts();

	$e = Assert::exception(fn () => $service(null, $tree)->saveTranslation($cmd($parent, 'spolecnost', version: 1)), InvalidSlugException::class);
	assert($e instanceof InvalidSlugException);
	Assert::same('taken', $e->reason);
	Assert::same($before, $counts());
	Assert::same('firma', $pages->findTranslation($parent, 'cs')?->path);
});

test('an unknown locale is rejected before anything is written', function () use ($reset, $cmd, $pages, $counts, $redirects, $transaction, $treeLock, $clock): void {
	$reset();
	$id = $pages->createPage(null, 0, true);
	$csOnly = new CmsConfig('Site', 'cs', ['cs'], 10);
	$service = new PageService($pages, $redirects, new FlatPagePathStrategy(), new ReservedSegments($csOnly), $transaction, $treeLock, $csOnly, $clock);

	Assert::exception(fn () => $service->saveTranslation($cmd($id, 'o-nas', 'de')), InvalidArgumentException::class);
	Assert::exception(fn () => $service->saveTranslation($cmd($id, 'o-nas', 'en')), InvalidArgumentException::class);
	Assert::same([0, 0], $counts());
	Assert::same('o-nas', $service->saveTranslation($cmd($id, 'o-nas', 'cs'))->path);
});

test('a blank or too long title and an oversized description are rejected without writes', function () use ($reset, $service, $cmd, $pages, $counts): void {
	$reset();
	$id = $pages->createPage(null, 0, true);
	foreach (['', "  \t", str_repeat('x', 201), str_repeat('ž', 201)] as $title) {
		Assert::exception(fn () => $service()->saveTranslation($cmd($id, 'o-nas', 'cs', $title)), InvalidArgumentException::class);
	}
	Assert::exception(fn () => $service()->saveTranslation($cmd($id, 'o-nas', 'cs', 'T', str_repeat('ž', 301))), InvalidArgumentException::class);
	Assert::same([0, 0], $counts());

	$service()->saveTranslation($cmd($id, 'o-nas', 'cs', str_repeat('ž', 200), str_repeat('ž', 300)));
	Assert::same([1, 0], $counts());
});

test('the title is stored trimmed and its length is checked after trimming', function () use ($reset, $service, $cmd, $pages): void {
	$reset();
	$id = $pages->createPage(null, 0, true);

	Assert::same('O nás', $service()->saveTranslation($cmd($id, 'o-nas', 'cs', ' O nás '))->title);
	Assert::same('O nás', $pages->findTranslation($id, 'cs')?->title);

	$long = str_repeat('ž', 200);
	Assert::same($long, $service()->saveTranslation($cmd($id, 'o-nas', 'cs', "  {$long}\t", version: 1))->title);
	Assert::same('O nás', $service()->saveTranslation($cmd($id, 'o-nas', 'cs', "\u{A0}O nás\u{2003}", version: 2))->title);
});

test('a computed path whose first segment is reserved is rejected', function () use ($reset, $service, $cmd, $pages, $counts): void {
	$reset();
	$id = $pages->createPage(null, 0, true);
	$prefixing = new class() implements PagePathStrategy {
		/** @param list<string> $ancestorSlugs */
		public function pathFor(string $slug, array $ancestorSlugs): string
		{
			return 'admin/' . $slug;
		}
	};

	$e = Assert::exception(fn () => $service(null, $prefixing)->saveTranslation($cmd($id, 'x')), InvalidSlugException::class);
	assert($e instanceof InvalidSlugException);
	Assert::same('reserved', $e->reason);
	Assert::same([0, 0], $counts());
});

test('createPage creates the page and its first translation, placed after the last sibling', function () use ($reset, $service, $create, $pages): void {
	$reset();
	$pages->createPage(null, 4, true);
	$top = $service()->createPage($create(null, 'firma', 'Firma'));
	$first = $service()->createPage($create($top->pageId, 'tym', 'Tým', false));
	$second = $service()->createPage($create($top->pageId, 'kariera', 'Kariéra'));

	Assert::same(5, $pages->getPage($top->pageId)?->position);
	Assert::null($pages->getPage($top->pageId)?->parentId);
	Assert::same(0, $pages->getPage($first->pageId)?->position);
	Assert::same(1, $pages->getPage($second->pageId)?->position);
	Assert::same($top->pageId, $pages->getPage($first->pageId)?->parentId);
	Assert::false($pages->getPage($first->pageId)?->inMenu);
	Assert::true($pages->getPage($second->pageId)?->inMenu);

	Assert::same('Tým', $first->title);
	Assert::same('tym', $first->path);
	Assert::same('cs', $first->locale);
	Assert::same('content', $first->content);
	Assert::same('description', $first->metaDescription);
	Assert::same(PublicationStatus::Published, $first->status);
	Assert::same(1, $first->version);
	Assert::same($first->pageId, $pages->findPublishedByPath('cs', 'tym')?->pageId);
});

test('createPage writes no page when the slug is taken or reserved', function () use ($reset, $service, $create, $pageCount, $counts): void {
	$reset();
	$service()->createPage($create(null, 'zabrano'));
	$before = [$pageCount(), ...$counts()];

	foreach (['zabrano' => 'taken', 'admin' => 'reserved', 'O-nas' => 'format', str_repeat('a', 101) => 'long'] as $slug => $reason) {
		$e = Assert::exception(fn () => $service()->createPage($create(null, $slug)), InvalidSlugException::class);
		assert($e instanceof InvalidSlugException);
		Assert::same($reason, $e->reason, $slug);
		Assert::same($before, [$pageCount(), ...$counts()], $slug);
	}
});

test('createPage under a missing parent is an InvalidArgumentException and writes nothing', function () use ($reset, $service, $create, $pageCount, $counts): void {
	$reset();
	Assert::exception(fn () => $service()->createPage($create(999, 'o-nas')), InvalidArgumentException::class);
	Assert::same([0, 0, 0], [$pageCount(), ...$counts()]);
});

test('an empty slug is derived from the title', function () use ($reset, $service, $create, $cmd, $pages): void {
	$reset();
	Assert::same('o-nas', $service()->createPage($create(null, '', ' O nás '))->slug);

	$id = $pages->createPage(null, 5, true);
	$saved = $service()->saveTranslation($cmd($id, '', 'en', 'Contact us!'));
	Assert::same('contact-us', $saved->slug);
	Assert::same('contact-us', $saved->path);
});

test('a slug derived from the title goes through the same checks', function () use ($reset, $service, $create, $cmd, $pages, $pageCount, $counts): void {
	$reset();
	$service()->createPage($create(null, 'kontakt'));
	$id = $pages->createPage(null, 5, true);
	$before = [$pageCount(), ...$counts()];

	foreach (['Novinky' => 'reserved', 'Admin' => 'reserved', '!!!' => 'format', 'Kontakt' => 'taken'] as $title => $reason) {
		$e = Assert::exception(fn () => $service()->createPage($create(null, '', $title)), InvalidSlugException::class);
		assert($e instanceof InvalidSlugException);
		Assert::same($reason, $e->reason, $title);

		$e = Assert::exception(fn () => $service()->saveTranslation($cmd($id, '', 'cs', $title)), InvalidSlugException::class);
		assert($e instanceof InvalidSlugException);
		Assert::same($reason, $e->reason, $title);
		Assert::same($before, [$pageCount(), ...$counts()], $title);
	}
});

test('a long title gives a slug of at most 100 characters cut at a hyphen', function () use ($reset, $service, $create): void {
	$reset();
	// 150 characters; the 101st character of the webalized title falls inside a word
	$title = str_repeat('abcdefg ', 18) . 'abcdef';
	Assert::same(150, strlen($title));

	$slug = $service()->createPage($create(null, '', $title))->slug;
	Assert::same(implode('-', array_fill(0, 12, 'abcdefg')), $slug);

	// the 101st character is a hyphen: the first 100 characters are kept
	$slug = $service()->createPage($create(null, '', str_repeat('abcdefghi ', 9) . 'abcdefghij more'))->slug;
	Assert::same(str_repeat('abcdefghi-', 9) . 'abcdefghij', $slug);
	Assert::same(100, strlen($slug));

	// one word without any hyphen is cut at 100 characters
	$slug = $service()->createPage($create(null, '', str_repeat('x', 150)))->slug;
	Assert::same(str_repeat('x', 100), $slug);
});

test('a manual slug longer than 100 characters is long', function () use ($reset, $service, $cmd, $pages, $counts): void {
	$reset();
	$id = $pages->createPage(null, 0, true);
	$e = Assert::exception(fn () => $service()->saveTranslation($cmd($id, str_repeat('a', 101))), InvalidSlugException::class);
	assert($e instanceof InvalidSlugException);
	Assert::same(InvalidSlugException::LONG, $e->reason);
	Assert::same('long', InvalidSlugException::LONG);
	Assert::same([0, 0], $counts());
});

test('every write takes the tree lock before reading the tree', function () use ($reset, $service, $cmd, $create, $pages, $treeLock): void {
	$reset();
	$parent = $pages->createPage(null, 0, true);
	$child = $pages->createPage($parent, 0, true);
	$service()->saveTranslation($cmd($parent, 'firma'));

	/** @var ArrayObject<int, string> $log */
	$log = new ArrayObject();
	$lock = new class($treeLock, $log) implements PageTreeLock {
		/** @param ArrayObject<int, string> $log */
		public function __construct(private readonly PageTreeLock $inner, private readonly ArrayObject $log)
		{
		}


		public function acquire(): void
		{
			$this->log[] = 'acquire';
			$this->inner->acquire();
		}
	};
	$recording = new RecordingPageRepository($pages, $log);

	$service($recording, null, $lock)->saveTranslation($cmd($child, 'tym'));
	$calls = $log->getArrayCopy();
	Assert::same('acquire', $calls[0]);
	Assert::same(1, count(array_keys($calls, 'acquire', true)));
	Assert::contains('findTranslation', $calls); // the ancestor slug of $parent
	Assert::contains('lockTranslation', $calls);
	Assert::contains('saveTranslation', $calls);

	$log->exchangeArray([]);
	$service($recording, null, $lock)->createPage($create($parent, 'kariera'));
	$calls = $log->getArrayCopy();
	Assert::same('acquire', $calls[0]);
	Assert::same(1, count(array_keys($calls, 'acquire', true)));
	Assert::contains('findChildren', $calls);
	Assert::contains('createPage', $calls);
	Assert::contains('saveTranslation', $calls);
});

test('a translation version goes from 1 to 2 on the second save', function () use ($reset, $service, $cmd, $pages): void {
	$reset();
	$id = $pages->createPage(null, 0, true);
	Assert::same(1, $service()->saveTranslation($cmd($id, 'o-nas'))->version);
	Assert::same(2, $service()->saveTranslation($cmd($id, 'o-nas', 'cs', 'Again', version: 1))->version);
	Assert::same(2, $pages->findTranslation($id, 'cs')?->version);
});

test('two saves based on the same version: the second is stale and the first stays', function () use ($reset, $service, $cmd, $pages, $counts): void {
	$reset();
	$id = $pages->createPage(null, 0, true);
	$service()->saveTranslation($cmd($id, 'o-nas', 'cs', 'Original'));

	$first = new SavePageTranslation($id, 'cs', 'First', 'prvni', 'first content', 'first', PublicationStatus::Published, 1);
	$second = new SavePageTranslation($id, 'cs', 'Second', 'druhy', 'second content', 'second', PublicationStatus::Draft, 1);
	$service()->saveTranslation($first);
	$before = $counts();

	Assert::exception(fn () => $service()->saveTranslation($second), StaleVersionException::class);

	$stored = $pages->findTranslation($id, 'cs');
	Assert::same(['First', 'prvni', 'prvni', 'first content', 'first', PublicationStatus::Published, 2], [
		$stored?->title, $stored?->slug, $stored?->path, $stored?->content, $stored?->metaDescription, $stored?->status, $stored?->version,
	]);
	Assert::same($before, $counts());
	Assert::null($pages->findPublishedByPath('cs', 'druhy'));
});

test('a new translation with an expected version and an existing one without it are stale', function () use ($reset, $service, $cmd, $pages, $counts): void {
	$reset();
	$id = $pages->createPage(null, 0, true);

	Assert::exception(fn () => $service()->saveTranslation($cmd($id, 'o-nas', version: 1)), StaleVersionException::class);
	Assert::same([0, 0], $counts());

	$service()->saveTranslation($cmd($id, 'o-nas', 'cs', 'Original'));
	Assert::exception(fn () => $service()->saveTranslation($cmd($id, 'o-firme', 'cs', 'Other')), StaleVersionException::class);
	Assert::same('Original', $pages->findTranslation($id, 'cs')?->title);
	Assert::same(1, $pages->findTranslation($id, 'cs')?->version);
	Assert::same([1, 0], $counts());
});

test('the container autowires the service', function () use ($container): void {
	Assert::type(PageService::class, $container->getByType(PageService::class));
	Assert::type(PathRedirectRepository::class, $container->getByType(PathRedirectRepository::class));
	Assert::type(RedirectTarget::class, new RedirectTarget('page', 1));
});


test('updatePage changes the menu flag and raises the page version from 1 to 2, keeping parent and position', function () use ($reset, $service, $pages): void {
	$reset();
	$parent = $pages->createPage(null, 0, true);
	$id = $pages->createPage($parent, 4, true);

	$updated = $service()->updatePage(new UpdatePage($id, $parent, false, 1));

	Assert::same([$id, $parent, 4, false, 2], [$updated->id, $updated->parentId, $updated->position, $updated->inMenu, $updated->version]);
	$stored = $pages->getPage($id);
	Assert::same([$parent, 4, false, 2], [$stored?->parentId, $stored?->position, $stored?->inMenu, $stored?->version]);
	Assert::same(1, $pages->getPage($parent)?->version);
});

test('a parent that is the page itself, its child or its grandchild is an InvalidParentException and nothing changes', function () use ($reset, $service, $pages, $snapshot): void {
	$reset();
	$page = $pages->createPage(null, 0, true);
	$child = $pages->createPage($page, 0, true);
	$grandchild = $pages->createPage($child, 0, true);
	$before = $snapshot();

	foreach (['self' => $page, 'child' => $child, 'grandchild' => $grandchild] as $label => $parent) {
		Assert::exception(fn () => $service()->updatePage(new UpdatePage($page, $parent, false, 1)), InvalidParentException::class);
		Assert::same($before, $snapshot(), $label);
	}
});

test('a missing page or parent in updatePage is an InvalidArgumentException and nothing changes', function () use ($reset, $service, $pages, $snapshot): void {
	$reset();
	$id = $pages->createPage(null, 0, true);
	$before = $snapshot();

	Assert::exception(fn () => $service()->updatePage(new UpdatePage($id + 1000, null, true, 1)), InvalidArgumentException::class);
	Assert::exception(fn () => $service()->updatePage(new UpdatePage($id, $id + 1000, true, 1)), InvalidArgumentException::class);
	Assert::same($before, $snapshot());
});

test('updatePage with a stale version is a StaleVersionException and nothing changes', function () use ($reset, $service, $pages, $snapshot): void {
	$reset();
	$parent = $pages->createPage(null, 0, true);
	$id = $pages->createPage(null, 1, true);
	$service()->updatePage(new UpdatePage($id, null, false, 1));
	$before = $snapshot();

	Assert::exception(fn () => $service()->updatePage(new UpdatePage($id, $parent, true, 1)), StaleVersionException::class);
	Assert::same($before, $snapshot());
});

test('moving under another parent puts the page last among the new siblings and renumbers the old ones', function () use ($reset, $service, $pages, $positions): void {
	$reset();
	$oldParent = $pages->createPage(null, 0, true);
	$newParent = $pages->createPage(null, 1, true);
	$a = $pages->createPage($oldParent, 0, true);
	$moved = $pages->createPage($oldParent, 1, true);
	$b = $pages->createPage($oldParent, 5, true);
	$c = $pages->createPage($oldParent, 7, true);
	$x = $pages->createPage($newParent, 0, true);
	$y = $pages->createPage($newParent, 3, true);

	$updated = $service()->updatePage(new UpdatePage($moved, $newParent, true, 1));

	Assert::same([$newParent, 4, 2], [$updated->parentId, $updated->position, $updated->version]);
	Assert::same([$x => 0, $y => 3, $moved => 4], $positions($newParent));
	Assert::same([$a => 0, $b => 1, $c => 2], $positions($oldParent));
	foreach ([$a, $b, $c, $x, $y, $oldParent, $newParent] as $other) {
		Assert::same(1, $pages->getPage($other)?->version);
	}
});

test('moving to the top level and under an empty parent', function () use ($reset, $service, $pages, $positions): void {
	$reset();
	$root = $pages->createPage(null, 2, true);
	$empty = $pages->createPage(null, 6, true);
	$child = $pages->createPage($root, 0, true);

	Assert::same(0, $service()->updatePage(new UpdatePage($child, $empty, true, 1))->position);
	Assert::same(7, $service()->updatePage(new UpdatePage($child, null, true, 2))->position);
	Assert::same([$root => 2, $empty => 6, $child => 7], $positions(null));
	Assert::same([], $positions($empty));
});

test('moveUp and moveDown swap with the neighbour, renumber the siblings and keep every version', function () use ($reset, $service, $pages, $positions): void {
	$reset();
	$parent = $pages->createPage(null, 0, true);
	$a = $pages->createPage($parent, 3, true);
	$b = $pages->createPage($parent, 3, true);
	$c = $pages->createPage($parent, 9, true);
	$other = $pages->createPage(null, 1, true);

	$service()->moveUp($c);
	Assert::same([$a => 0, $c => 1, $b => 2], $positions($parent));
	$service()->moveDown($a);
	Assert::same([$c => 0, $a => 1, $b => 2], $positions($parent));
	$service()->moveUp($parent);
	Assert::same([$parent => 0, $other => 1], $positions(null));
	foreach ([$parent, $a, $b, $c, $other] as $id) {
		Assert::same(1, $pages->getPage($id)?->version);
	}
});

test('moveUp on the first and moveDown on the last sibling change nothing', function () use ($reset, $service, $pages, $snapshot): void {
	$reset();
	$a = $pages->createPage(null, 2, true);
	$b = $pages->createPage(null, 5, true);
	$only = $pages->createPage($a, 4, true);
	$before = $snapshot();

	$service()->moveUp($a);
	$service()->moveDown($b);
	$service()->moveUp($only);
	$service()->moveDown($only);
	Assert::same($before, $snapshot());
});

test('moveUp and moveDown on a missing page are an InvalidArgumentException', function () use ($reset, $service): void {
	$reset();
	Assert::exception(fn () => $service()->moveUp(999), InvalidArgumentException::class);
	Assert::exception(fn () => $service()->moveDown(999), InvalidArgumentException::class);
});

test('moveUp between opening and saving the properties form: the save passes and keeps the moved position', function () use ($reset, $service, $pages, $positions): void {
	$reset();
	$a = $pages->createPage(null, 0, true);
	$b = $pages->createPage(null, 1, true);
	$opened = $pages->getPage($b);
	assert($opened !== null);

	$service()->moveUp($b);
	Assert::same(1, $pages->getPage($b)?->version);
	$saved = $service()->updatePage(new UpdatePage($b, $opened->parentId, false, $opened->version));

	Assert::same([0, false, 2], [$saved->position, $saved->inMenu, $saved->version]);
	Assert::same([$b => 0, $a => 1], $positions(null));
});

test('descendantIds lists every descendant, not the page itself', function () use ($reset, $service, $pages): void {
	$reset();
	$root = $pages->createPage(null, 0, true);
	$child = $pages->createPage($root, 0, true);
	$sibling = $pages->createPage($root, 1, true);
	$grandchild = $pages->createPage($child, 0, true);
	$pages->createPage(null, 1, true);

	$ids = $service()->descendantIds($root);
	sort($ids);
	Assert::same([$child, $sibling, $grandchild], $ids);
	Assert::same([$grandchild], $service()->descendantIds($child));
	Assert::same([], $service()->descendantIds($grandchild));
});

test('a cycle in the stored tree makes descendantIds, updatePage and saveTranslation throw instead of looping', function () use ($reset, $service, $cmd, $pages, $explorer, $snapshot): void {
	$reset();
	$a = $pages->createPage(null, 0, true);
	$b = $pages->createPage($a, 0, true);
	$other = $pages->createPage(null, 1, true);
	$explorer->query('UPDATE page SET parent_id = ? WHERE id = ?', $b, $a);
	$before = $snapshot();

	Assert::exception(fn () => $service()->descendantIds($a), UnexpectedValueException::class, 'Page tree contains a cycle at page ' . $a);
	// the cycle check walks b -> a -> b
	Assert::exception(fn () => $service()->updatePage(new UpdatePage($b, $other, true, 1)), UnexpectedValueException::class, 'Page tree contains a cycle at page ' . $b);
	// ancestorSlugs walks b -> a -> b
	Assert::exception(fn () => $service()->saveTranslation($cmd($b, 'kruh')), UnexpectedValueException::class, 'Page tree contains a cycle at page ' . $b);
	Assert::same($before, $snapshot());
	Assert::null($pages->findTranslation($b, 'cs'));

	$explorer->query('UPDATE page SET parent_id = ? WHERE id = ?', $b, $b);
	Assert::exception(fn () => $service()->saveTranslation($cmd($b, 'kruh')), UnexpectedValueException::class, 'Page tree contains a cycle at page ' . $b);
});

test('a cycle below the saved page makes the descendant recompute throw instead of looping', function () use ($reset, $service, $cmd, $pages, $explorer): void {
	$reset();
	$a = $pages->createPage(null, 0, true);
	$b = $pages->createPage($a, 0, true);
	$c = $pages->createPage($b, 0, true);
	$explorer->query('UPDATE page SET parent_id = ? WHERE id = ?', $c, $a);
	// The ancestor walk sees $a as a root, so only the walk down through the children meets the cycle.
	$asRoot = new DelegatingPageRepository($pages, static function (): void {
	}, static fn (Page $p): Page => $p->id === $a ? new Page($p->id, null, $p->position, $p->inMenu, $p->version) : $p);

	Assert::exception(fn () => $service($asRoot)->saveTranslation($cmd($a, 'kruh')), UnexpectedValueException::class, 'Page tree contains a cycle at page ' . $a);
	Assert::null($pages->findTranslation($a, 'cs'));
});

test('a parent change recomputes the paths of the page and its descendants in every locale', function () use ($reset, $service, $cmd, $pages, $tree, $redirectId): void {
	$reset();
	$firma = $pages->createPage(null, 0, true);
	$sluzby = $pages->createPage(null, 1, true);
	$tym = $pages->createPage($firma, 0, true);
	$lide = $pages->createPage($tym, 0, true);
	foreach (['cs' => ['firma', 'sluzby', 'tym', 'lide'], 'en' => ['company', 'services', 'team', 'people']] as $locale => $slugs) {
		foreach ([$firma, $sluzby, $tym, $lide] as $i => $id) {
			$service(null, $tree)->saveTranslation($cmd($id, $slugs[$i], $locale));
		}
	}

	$service(null, $tree)->updatePage(new UpdatePage($tym, $sluzby, true, 1));

	Assert::same('sluzby/tym', $pages->findTranslation($tym, 'cs')?->path);
	Assert::same('sluzby/tym/lide', $pages->findTranslation($lide, 'cs')?->path);
	Assert::same('services/team', $pages->findTranslation($tym, 'en')?->path);
	Assert::same('services/team/people', $pages->findTranslation($lide, 'en')?->path);
	Assert::same($tym, $redirectId('cs', 'firma/tym'));
	Assert::same($lide, $redirectId('en', 'company/team/people'));
});

test('with the flat strategy a parent change leaves every path and records no redirect', function () use ($reset, $service, $cmd, $pages, $counts): void {
	$reset();
	$a = $pages->createPage(null, 0, true);
	$b = $pages->createPage(null, 1, true);
	$child = $pages->createPage($a, 0, true);
	$service()->saveTranslation($cmd($child, 'dite'));
	$service()->saveTranslation($cmd($child, 'child', 'en'));
	$before = $counts();

	$service()->updatePage(new UpdatePage($child, $b, true, 1));
	Assert::same('dite', $pages->findTranslation($child, 'cs')?->path);
	Assert::same('child', $pages->findTranslation($child, 'en')?->path);
	Assert::same($before, $counts());
});

test('updatePage, moveUp and moveDown take the tree lock before reading the tree', function () use ($reset, $service, $pages, $treeLock): void {
	$reset();
	$a = $pages->createPage(null, 0, true);
	$b = $pages->createPage(null, 1, true);
	$child = $pages->createPage($a, 0, true);

	/** @var ArrayObject<int, string> $log */
	$log = new ArrayObject();
	$lock = new class($treeLock, $log) implements PageTreeLock {
		/** @param ArrayObject<int, string> $log */
		public function __construct(private readonly PageTreeLock $inner, private readonly ArrayObject $log)
		{
		}


		public function acquire(): void
		{
			$this->log[] = 'acquire';
			$this->inner->acquire();
		}
	};
	$recording = new RecordingPageRepository($pages, $log);
	$cases = [
		'updatePage' => fn () => $service($recording, null, $lock)->updatePage(new UpdatePage($a, $b, true, 1)),
		'moveUp' => fn () => $service($recording, null, $lock)->moveUp($b),
		'moveDown' => fn () => $service($recording, null, $lock)->moveDown($child),
	];
	foreach ($cases as $label => $run) {
		$log->exchangeArray([]);
		$run();
		$calls = $log->getArrayCopy();
		Assert::same('acquire', $calls[0] ?? null, $label);
		Assert::same(1, count(array_keys($calls, 'acquire', true)), $label);
		Assert::contains('findChildren', $calls, $label);
	}
});


test('a reparent whose recomputed path is taken rolls back the page, the sibling positions, translations and redirects', function () use ($reset, $service, $cmd, $pages, $tree, $fullSnapshot): void {
	$reset();
	$firma = $pages->createPage(null, 0, true);
	$sluzby = $pages->createPage(null, 1, true);
	$first = $pages->createPage($firma, 0, true);
	$tym = $pages->createPage($firma, 1, true);
	$last = $pages->createPage($firma, 5, true);
	$squatter = $pages->createPage(null, 2, true);
	$service(null, $tree)->saveTranslation($cmd($firma, 'firma'));
	$service(null, $tree)->saveTranslation($cmd($sluzby, 'sluzby'));
	$service(null, $tree)->saveTranslation($cmd($tym, 'tym'));
	$pages->saveTranslation(new PageTranslation($squatter, 'cs', 'S', 'tym', 'sluzby/tym', '', '', PublicationStatus::Draft, new DateTimeImmutable(), 1));
	$before = $fullSnapshot();

	$e = Assert::exception(fn () => $service(null, $tree)->updatePage(new UpdatePage($tym, $sluzby, false, 1)), InvalidSlugException::class);
	assert($e instanceof InvalidSlugException);
	Assert::same(InvalidSlugException::TAKEN, $e->reason);
	Assert::same($before, $fullSnapshot());
	Assert::same([$first, $last], [$pages->findChildren($firma)[0]->id, $pages->findChildren($firma)[2]->id]);
});

test('a reparent under a page whose ancestry is a cycle throws and rolls everything back', function () use ($reset, $service, $pages, $explorer, $fullSnapshot): void {
	$reset();
	$a = $pages->createPage(null, 0, true);
	$b = $pages->createPage($a, 0, true);
	$sibling = $pages->createPage(null, 1, true);
	$moved = $pages->createPage(null, 2, true);
	$after = $pages->createPage(null, 3, true);
	$explorer->query('UPDATE page SET parent_id = ? WHERE id = ?', $b, $a);
	$before = $fullSnapshot();

	Assert::same([], $service()->descendantIds($moved));
	Assert::exception(fn () => $service()->updatePage(new UpdatePage($moved, $a, false, 1)), UnexpectedValueException::class, 'Page tree contains a cycle at page %d%');
	Assert::same($before, $fullSnapshot());
	Assert::same([$sibling, $moved, $after], array_map(static fn (Page $p): int => $p->id, $pages->findChildren(null)));
});

test('a reparent also writes the menu flag', function () use ($reset, $service, $pages): void {
	$reset();
	$a = $pages->createPage(null, 0, true);
	$b = $pages->createPage(null, 1, true);
	$child = $pages->createPage($a, 0, true);

	$updated = $service()->updatePage(new UpdatePage($child, $b, false, 1));
	Assert::same([$b, false, 2], [$updated->parentId, $updated->inMenu, $updated->version]);
	Assert::false($pages->getPage($child)?->inMenu);
});

test('a page missing from its own sibling list is an UnexpectedValueException and nothing moves', function () use ($reset, $service, $pages, $snapshot): void {
	$reset();
	$a = $pages->createPage(null, 0, true);
	$b = $pages->createPage(null, 1, true);
	$elsewhere = $pages->createPage(null, 2, true);
	$child = $pages->createPage($elsewhere, 0, true);
	// getPage reports $child under the top level, but findChildren(null) does not list it.
	$lying = new DelegatingPageRepository($pages, static function (): void {
	}, static fn (Page $p): Page => $p->id === $child ? new Page($p->id, null, $p->position, $p->inMenu, $p->version) : $p);
	$before = $snapshot();

	Assert::exception(fn () => $service($lying)->moveDown($child), UnexpectedValueException::class);
	Assert::same($before, $snapshot());
	Assert::same([$a, $b, $elsewhere], array_map(static fn (Page $p): int => $p->id, $pages->findChildren(null)));
});
