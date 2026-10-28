<?php

declare(strict_types=1);

use App\Model\News\CreateNews;
use App\Model\News\NewsService;
use App\Model\News\SaveNewsTranslation;
use App\Model\PublicationStatus;
use App\Model\Redirect\PathRedirectRepository;
use App\Model\Redirect\RedirectTarget;
use Nette\Database\Explorer;
use Nette\DI\Container;
use Tester\Assert;
use Tests\Support\HttpApplication;
use Tests\Support\HttpResult;

require __DIR__ . '/../../bootstrap.php';

const NEWS_CONFIG = __DIR__ . '/routing.neon';


final class NewsSeed
{
	private int $author;


	public function __construct(
		private readonly Container $container,
	) {
		$explorer = $container->getByType(Explorer::class);
		$explorer->query('INSERT INTO `user` ?', ['email' => 'a@example.com', 'password_hash' => 'h', 'name' => 'Ann', 'role' => 'editor', 'created_at' => '2026-01-01 00:00:00']);
		$this->author = (int) $explorer->getInsertId();
	}


	/** Returns the news id. */
	public function create(
		string $slug,
		string $publishedAt = '2026-01-01 10:00:00',
		PublicationStatus $status = PublicationStatus::Published,
		?string $title = null,
		string $perex = 'Perex',
		string $meta = '',
		string $locale = 'cs',
	): int {
		return $this->service()->createNews(new CreateNews(
			new DateTimeImmutable($publishedAt, new DateTimeZone('UTC')),
			$this->author,
			$locale,
			$title ?? "Title $slug",
			$slug,
			$perex,
			"Content of **$slug**.",
			$meta,
			$status,
		))->newsId;
	}


	public function translate(int $newsId, string $locale, string $slug, ?int $version = null, PublicationStatus $status = PublicationStatus::Published): void
	{
		$this->service()->saveTranslation(new SaveNewsTranslation($newsId, $locale, "Title $slug", $slug, 'Perex', "Content of **$slug**.", '', $status, $version));
	}


	/** Visible items 1 … $count, item $count newest. */
	public function many(int $count): void
	{
		for ($i = 1; $i <= $count; $i++) {
			$this->create("novinka-$i", sprintf('2026-01-%02d 10:00:00', $i), title: "Novinka $i");
		}
	}


	private function service(): NewsService
	{
		return $this->container->getByType(NewsService::class);
	}
}


/**
 * @param list<string|array<string, mixed>> $configs
 * @return array{HttpApplication, NewsSeed}
 */
function newsApp(array $configs = []): array
{
	$app = HttpApplication::boot([NEWS_CONFIG, ...$configs]);

	return [$app, new NewsSeed($app->container)];
}


function assertNotFound(HttpResult $result, string $message = ''): void
{
	Assert::same(404, $result->code, $message);
	Assert::null($result->location, $message);
	Assert::notSame(null, $result->text('error-title'), $message);
}


function assertMovedTo(string $location, HttpResult $result, string $message = ''): void
{
	Assert::same(301, $result->code, $message);
	Assert::same($location, $result->location, $message);
}


/** @return list<string> */
function itemTitles(HttpResult $result): array
{
	return array_map(static fn (SimpleXMLElement $a): string => trim((string) $a), $result->dom()->find('[data-testid="news-item-title"]'));
}


[$app, $seed] = newsApp();
$seed->many(12);


test('the first page lists the 10 newest items with a link to older news', function () use ($app): void {
	$result = $app->get('/novinky');

	Assert::same(200, $result->code);
	Assert::same('Novinky', $result->text('page-title'));
	Assert::match('%A%<title>Novinky | CMS Lite</title>%A%', $result->body);
	Assert::same(['Novinka 12', 'Novinka 11', 'Novinka 10', 'Novinka 9', 'Novinka 8', 'Novinka 7', 'Novinka 6', 'Novinka 5', 'Novinka 4', 'Novinka 3'], itemTitles($result));
	Assert::count(10, $result->dom()->find('[data-testid="news-list"] [data-testid="news-item"]'));
	Assert::count(10, $result->dom()->find('[data-testid="news-item"] h2 > [data-testid="news-item-title"]'), 'list items have h2 headings');
	Assert::count(0, $result->dom()->find('[data-testid="news-list"] h3'));
	Assert::same('/novinky/novinka-12', (string) $result->dom()->find('[data-testid="news-item-title"]')[0]['href']);
	Assert::same('Perex', $result->text('news-item-perex'));
	Assert::same('12. 1. 2026', $result->text('news-item-date'));

	Assert::contains('Strana 1 z 2', (string) $result->text('news-pagination'));
	Assert::same('Stránkování novinek', (string) $result->dom()->find('nav[data-testid="news-pagination"]')[0]['aria-label']);
	$older = $result->dom()->find('[data-testid="news-older"]');
	Assert::count(1, $older);
	Assert::same('/novinky?strana=2', (string) $older[0]['href']);
	Assert::same('Starší novinky', trim((string) $older[0]));
	Assert::null($result->text('news-newer'));
	Assert::null($result->text('news-empty'));
});


test('the second page lists the rest with a link to newer news', function () use ($app): void {
	$result = $app->get('/novinky?strana=2');

	Assert::same(200, $result->code);
	Assert::same(['Novinka 2', 'Novinka 1'], itemTitles($result));
	Assert::contains('Strana 2 z 2', (string) $result->text('news-pagination'));
	$newer = $result->dom()->find('[data-testid="news-newer"]');
	Assert::count(1, $newer);
	Assert::same('/novinky', (string) $newer[0]['href']);
	Assert::same('Novější novinky', trim((string) $newer[0]));
	Assert::null($result->text('news-older'));
});


test('page 1 in the query redirects to the clean URL', function () use ($app): void {
	assertMovedTo('/novinky', $app->get('/novinky?strana=1'));
});


foreach (['0', '3', 'abc', '01', '-1', '1x', '', '2%0A'] as $page) {
	test("page '$page' is not found", function () use ($app, $page): void {
		assertNotFound($app->get('/novinky?strana=' . $page));
	});
}


test('a foreign page parameter redirects to the clean URL', function () use ($app): void {
	assertMovedTo('/novinky', $app->get('/novinky?page=2'));
	assertMovedTo('/en/news', $app->get('/en/news?strana=2'));
});


test('a foreign page parameter next to the own one keeps the own page', function () use ($app): void {
	assertMovedTo('/novinky?strana=2', $app->get('/novinky?strana=2&page=3'));
	assertMovedTo('/novinky?strana=2', $app->get('/novinky?page=3&strana=2'));
});


test('the English list shows only English items, under its own segment', function () use ($app, $seed): void {
	$id = $seed->create('jen-cesky-a-anglicky', '2025-06-01 10:00:00');
	$seed->translate($id, 'en', 'english-one');

	$result = $app->get('/en/news');
	Assert::same(200, $result->code);
	Assert::contains('<html lang="en">', $result->body);
	Assert::same('News', $result->text('page-title'));
	Assert::same(['Title english-one'], itemTitles($result));
	Assert::same('/en/news/english-one', (string) $result->dom()->find('[data-testid="news-item-title"]')[0]['href']);
	Assert::null($result->text('news-pagination'));

	$detail = $app->get('/en/news/english-one');
	Assert::same(200, $detail->code);
	Assert::contains('<html lang="en">', $detail->body);
	Assert::same('/en/news', (string) $detail->dom()->find('[data-testid="news-back"]')[0]['href']);
	Assert::same('Back to news', $detail->text('news-back'));

	assertNotFound($app->get('/en/novinky'));
	assertNotFound($app->get('/news'));
	assertNotFound($app->get('/en/novinky/jen-cesky-a-anglicky'));
	assertNotFound($app->get('/en/news/jen-cesky-a-anglicky'));
	assertNotFound($app->get('/news/english-one'));
});


test('the detail renders title, site date, Markdown content and a link back', function () use ($app): void {
	$result = $app->get('/novinky/novinka-3');

	Assert::same(200, $result->code);
	Assert::notSame(null, $result->text('news-detail'));
	Assert::same('Novinka 3', $result->text('page-title'));
	Assert::same('3. 1. 2026', $result->text('news-detail-date'));
	Assert::contains('<strong>novinka-3</strong>', $result->body);
	Assert::match('%A%<title>Novinka 3 | CMS Lite</title>%A%', $result->body);
	Assert::same('/novinky', (string) $result->dom()->find('[data-testid="news-back"]')[0]['href']);
	Assert::same('Zpět na novinky', $result->text('news-back'));
});


test('the menu has the news item last, marked current on the list and the detail', function () use ($app): void {
	foreach (['/novinky', '/novinky?strana=2', '/novinky/novinka-1'] as $path) {
		$news = $app->get($path)->dom()->find('[data-testid="menu"] [data-testid="menu-news"]');
		Assert::count(1, $news, $path);
		Assert::same('/novinky', (string) $news[0]['href'], $path);
		Assert::same('Novinky', trim((string) $news[0]), $path);
		Assert::same('page', (string) $news[0]['aria-current'], $path);
	}

	$en = $app->get('/en/news')->dom()->find('[data-testid="menu-news"]');
	Assert::same('/en/news', (string) $en[0]['href']);
	Assert::same('News', trim((string) $en[0]));
});


// The tests below boot their own application, which wipes the data above.
test('no news is page 1 with the empty message and no pagination', function (): void {
	[$app] = newsApp();

	$result = $app->get('/novinky');
	Assert::same(200, $result->code);
	Assert::same('Zatím tu nejsou žádné novinky.', $result->text('news-empty'));
	Assert::null($result->text('news-list'));
	Assert::null($result->text('news-pagination'));
	assertNotFound($app->get('/novinky?strana=2'));
});


test('the English list pages with ?page=N', function (): void {
	[$app, $seed] = newsApp();
	for ($i = 1; $i <= 12; $i++) {
		$seed->create("en-item-$i", sprintf('2026-01-%02d 10:00:00', $i), title: "News $i", locale: 'en');
	}

	$first = $app->get('/en/news');
	Assert::same(200, $first->code);
	Assert::count(10, itemTitles($first));
	Assert::same('/en/news?page=2', (string) $first->dom()->find('[data-testid="news-older"]')[0]['href']);
	Assert::contains('Page 1 of 2', (string) $first->text('news-pagination'));
	Assert::same('News pages', (string) $first->dom()->find('nav[data-testid="news-pagination"]')[0]['aria-label']);

	$second = $app->get('/en/news?page=2');
	Assert::same(200, $second->code);
	Assert::same(['News 2', 'News 1'], itemTitles($second));
	Assert::same('/en/news', (string) $second->dom()->find('[data-testid="news-newer"]')[0]['href']);

	assertMovedTo('/en/news', $app->get('/en/news?page=1'));
	assertMovedTo('/en/news?page=2', $app->get('/en/news?page=2&strana=3'));
	assertNotFound($app->get('/en/news?page=3'));
});


test('exactly one page of news has no pagination', function (): void {
	[$app, $seed] = newsApp();
	$seed->many(10);

	$result = $app->get('/novinky');
	Assert::same(200, $result->code);
	Assert::count(10, itemTitles($result));
	Assert::null($result->text('news-pagination'));
	Assert::null($result->text('news-older'));
	assertNotFound($app->get('/novinky?strana=2'));
});


test('scheduled and draft items are neither listed nor shown', function (): void {
	[$app, $seed] = newsApp();
	$seed->create('viditelna');
	$seed->create('naplanovana', '2099-01-01 10:00:00');
	$seed->create('koncept', status: PublicationStatus::Draft);

	Assert::same(['Title viditelna'], itemTitles($app->get('/novinky')));
	Assert::null($app->get('/novinky')->text('news-pagination'));
	Assert::same(200, $app->get('/novinky/viditelna')->code);
	assertNotFound($app->get('/novinky/naplanovana'));
	assertNotFound($app->get('/novinky/koncept'));
});


test('an old slug redirects to the new one, in both languages', function (): void {
	[$app, $seed] = newsApp();
	$id = $seed->create('stary');
	$seed->translate($id, 'en', 'old');
	$seed->translate($id, 'cs', 'novy', 1);
	$seed->translate($id, 'en', 'new', 1);

	assertMovedTo('/novinky/novy', $app->get('/novinky/stary'));
	assertMovedTo('/en/news/new', $app->get('/en/news/old'));
	Assert::same(200, $app->get('/novinky/novy')->code);
});


test('a redirect to a draft or scheduled item is not found', function (): void {
	[$app, $seed] = newsApp();
	$draft = $seed->create('koncept-stary', status: PublicationStatus::Draft);
	$seed->translate($draft, 'cs', 'koncept-novy', 1, PublicationStatus::Draft);
	$scheduled = $seed->create('budouci-stary', '2099-01-01 10:00:00');
	$seed->translate($scheduled, 'cs', 'budouci-novy', 1);

	assertNotFound($app->get('/novinky/koncept-stary'));
	assertNotFound($app->get('/novinky/budouci-stary'));
});


test('a live item wins over a redirect from its path', function (): void {
	[$app, $seed] = newsApp();
	$a = $seed->create('a', title: 'A');
	$seed->create('x', title: 'B');
	$app->container->getByType(PathRedirectRepository::class)->record('cs', 'novinky/x', new RedirectTarget('news', $a));

	$result = $app->get('/novinky/x');
	Assert::same(200, $result->code);
	Assert::same('B', $result->text('page-title'));
});


test('a redirect of a page type is not followed from a news path', function (): void {
	[$app, $seed] = newsApp();
	$a = $seed->create('a');
	$app->container->getByType(PathRedirectRepository::class)->record('cs', 'novinky/stara', new RedirectTarget('page', $a));

	assertNotFound($app->get('/novinky/stara'));
});


test('dates are in the site time zone: 2025-12-31 23:30 UTC is 1 January 2026', function (): void {
	[$app, $seed] = newsApp();
	$seed->create('silvestr', '2025-12-31 23:30:00');

	Assert::same('1. 1. 2026', $app->get('/novinky')->text('news-item-date'));
	Assert::same('1. 1. 2026', $app->get('/novinky/silvestr')->text('news-detail-date'));
});


test('the meta description is the own one, else the perex', function (): void {
	[$app, $seed] = newsApp();
	$seed->create('bez-popisu', perex: 'Krátký <úvod>');
	$seed->create('s-popisem', perex: 'Perex', meta: 'Vlastní popis');

	Assert::match('%A%<meta name="description" content="Krátký &lt;úvod&gt;">%A%', $app->get('/novinky/bez-popisu')->body);
	Assert::match('%A%<meta name="description" content="Vlastní popis">%A%', $app->get('/novinky/s-popisem')->body);
	Assert::contains('Krátký &lt;úvod&gt;', $app->get('/novinky')->body);
});


test('newsInMenu: false leaves the news item out of the menu', function (): void {
	[$app, $seed] = newsApp([['cms' => ['newsInMenu' => false]]]);
	$seed->create('a');

	foreach (['/novinky', '/novinky/a'] as $path) {
		$result = $app->get($path);
		Assert::same(200, $result->code, $path);
		Assert::same([], $result->dom()->find('[data-testid="menu-news"]'), $path);
	}
});


test('public news pages start no session', function (): void {
	[$app, $seed] = newsApp();
	$seed->create('a');

	foreach (['/novinky', '/novinky/a', '/novinky/neexistuje'] as $path) {
		Assert::false($app->get($path)->sessionStarted(), $path);
	}
});
