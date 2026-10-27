<?php

declare(strict_types=1);

use App\Model\CmsConfig;
use App\Model\Page\Database\DatabasePageRepository;
use App\Model\Page\FlatPagePathStrategy;
use App\Model\Page\InvalidSlugException;
use App\Model\Page\PagePathStrategy;
use App\Model\Page\PageRepository;
use App\Model\Page\PageService;
use App\Model\Page\PageTranslation;
use App\Model\Page\ReservedSegments;
use App\Model\Page\SavePageTranslation;
use App\Model\PublicationStatus;
use App\Model\Redirect\Database\DatabasePathRedirectRepository;
use App\Model\Redirect\PathRedirectRepository;
use App\Model\Redirect\RedirectTarget;
use App\Model\Transaction;
use Nette\Database\Explorer;
use Nette\Database\UniqueConstraintViolationException;
use Tester\Assert;
use Tests\Model\Page\DelegatingPageRepository;
use Tests\Support\FrozenClock;
use Tests\Support\TestDatabase;
use function Tests\container;

require __DIR__ . '/../../bootstrap.php';
require __DIR__ . '/DelegatingPageRepository.php';

$container = container();
$explorer = $container->getByType(Explorer::class);
$transaction = $container->getByType(Transaction::class);
$clock = new FrozenClock(new DateTimeImmutable('2026-01-15 12:00:00', new DateTimeZone('UTC')));
$pages = new DatabasePageRepository($explorer, $clock);
$redirects = new DatabasePathRedirectRepository($explorer, $clock);
$config = new CmsConfig('Site', 'cs', ['cs', 'en'], 10);
$reserved = new ReservedSegments($config);
$reset = static fn () => TestDatabase::prepare($container);

$service = static fn (?PageRepository $repo = null, ?PagePathStrategy $strategy = null): PageService => new PageService(
	$repo ?? $pages,
	$redirects,
	$strategy ?? new FlatPagePathStrategy(),
	$reserved,
	$transaction,
	$config,
	$clock,
);
$cmd = static fn (int $pageId, string $slug, string $locale = 'cs', string $title = 'Title', string $meta = 'description'): SavePageTranslation => new SavePageTranslation(
	$pageId,
	$locale,
	$title,
	$slug,
	'content',
	$meta,
	PublicationStatus::Published,
);
$counts = static fn (): array => [
	$explorer->table('page_translation')->count('*'),
	$explorer->table('path_redirect')->count('*'),
];
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
		'' => 'format',
		str_repeat('a', 101) => 'format',
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
	$service()->saveTranslation($cmd($id, 'o-nas', 'cs', 'Two'));

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
	$service()->saveTranslation($cmd($id, 'o-firme'));

	Assert::same($id, $redirectId('cs', 'o-nas'));
	Assert::null($redirectId('cs', 'o-firme'));

	$service()->saveTranslation($cmd($id, 'o-nas'));
	Assert::null($redirectId('cs', 'o-nas'));
	Assert::same($id, $redirectId('cs', 'o-firme'));
	Assert::same([1, 1], $counts());
});

test('redirects are per locale', function () use ($reset, $service, $cmd, $pages, $redirectId): void {
	$reset();
	$id = $pages->createPage(null, 0, true);
	$service()->saveTranslation($cmd($id, 'o-nas', 'cs'));
	$service()->saveTranslation($cmd($id, 'about', 'en'));
	$service()->saveTranslation($cmd($id, 'o-firme', 'cs'));

	Assert::same($id, $redirectId('cs', 'o-nas'));
	Assert::null($redirectId('en', 'o-nas'));
	Assert::null($redirectId('en', 'about'));
});

test('another page taking an old path wins: the redirect is deleted', function () use ($reset, $service, $cmd, $pages, $redirectId): void {
	$reset();
	$a = $pages->createPage(null, 0, true);
	$b = $pages->createPage(null, 1, true);
	$service()->saveTranslation($cmd($a, 'o-nas'));
	$service()->saveTranslation($cmd($a, 'o-firme'));
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
	Assert::exception(fn () => $service($failing)->saveTranslation($cmd($id, 'o-firme')), RuntimeException::class, 'boom');

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
	$service(null, $spy)->saveTranslation($cmd($parent, 'spolecnost'));

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

	$service(null, $tree)->saveTranslation($cmd($parent, 'spolecnost'));

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

	$e = Assert::exception(fn () => $service(null, $tree)->saveTranslation($cmd($parent, 'spolecnost')), InvalidSlugException::class);
	assert($e instanceof InvalidSlugException);
	Assert::same('taken', $e->reason);
	Assert::same($before, $counts());
	Assert::same('firma', $pages->findTranslation($parent, 'cs')?->path);
});

test('an unknown locale is rejected before anything is written', function () use ($reset, $cmd, $pages, $counts, $redirects, $transaction, $clock): void {
	$reset();
	$id = $pages->createPage(null, 0, true);
	$csOnly = new CmsConfig('Site', 'cs', ['cs'], 10);
	$service = new PageService($pages, $redirects, new FlatPagePathStrategy(), new ReservedSegments($csOnly), $transaction, $csOnly, $clock);

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
	Assert::same($long, $service()->saveTranslation($cmd($id, 'o-nas', 'cs', "  {$long}\t"))->title);
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

test('the container autowires the service', function () use ($container): void {
	Assert::type(PageService::class, $container->getByType(PageService::class));
	Assert::type(PathRedirectRepository::class, $container->getByType(PathRedirectRepository::class));
	Assert::type(RedirectTarget::class, new RedirectTarget('page', 1));
});
