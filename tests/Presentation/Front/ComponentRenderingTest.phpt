<?php

declare(strict_types=1);

use App\Model\News\CreateNews;
use App\Model\News\NewsService;
use App\Model\Page\Homepage;
use App\Model\PublicationStatus;
use Nette\Database\Explorer;
use Tester\Assert;
use Tests\FakeLogger;
use Tests\Support\HttpApplication;
use Tests\Support\HttpResult;
use Tests\Support\TestDatabase;
use Tracy\ILogger;

require __DIR__ . '/../../bootstrap.php';

$app = HttpApplication::boot([__DIR__ . '/routing.neon']);


/** Demo data without the demo news. */
function resetNewsDemo(HttpApplication $app): void
{
	TestDatabase::resetDummyData($app->container);
	db($app)->query('DELETE FROM news');
}


function db(HttpApplication $app): Explorer
{
	return $app->container->getByType(Explorer::class);
}


/** Returns the news id; the date is in UTC. */
function news(HttpApplication $app, string $slug, string $publishedAt, string $locale = 'cs', PublicationStatus $status = PublicationStatus::Published, string $content = 'Text.'): int
{
	$author = db($app)->table('user')->where('email', 'admin@example.com')->fetch()?->id;
	assert(is_int($author));

	return $app->container->getByType(NewsService::class)->createNews(new CreateNews(
		new DateTimeImmutable($publishedAt, new DateTimeZone('UTC')),
		$author,
		$locale,
		"Title $slug",
		$slug,
		"Perex $slug",
		$content,
		'',
		$status,
	))->newsId;
}


function homepageId(HttpApplication $app): int
{
	$id = $app->container->getByType(Homepage::class)->pageId();
	assert($id !== null);

	return $id;
}


/** Stores the content directly, as content made before markers existed or edited in the database would be. */
function storeHomepage(HttpApplication $app, string $locale, string $content): void
{
	db($app)->query('UPDATE page_translation SET content = ? WHERE page_id = ? AND locale = ?', $content, homepageId($app), $locale);
}


/** @return list<string> */
function componentTitles(HttpResult $result): array
{
	return array_map(
		static fn (SimpleXMLElement $a): string => trim((string) $a),
		$result->dom()->find('[data-testid="component-news"] [data-testid="component-news-item"] [data-testid="news-item-title"]'),
	);
}


/** @return list<string> */
function warnings(HttpResult $result): array
{
	$logger = $result->container->getByType(ILogger::class);
	assert($logger instanceof FakeLogger);
	$warnings = [];
	foreach ($logger->records as [$message, $level]) {
		if ($level === ILogger::WARNING) {
			$warnings[] = is_string($message) ? $message : get_debug_type($message);
		}
	}

	return $warnings;
}


test('the news component lists the newest visible news of the page language with a link to all news', function () use ($app): void {
	resetNewsDemo($app);
	news($app, 'starsi', '2026-01-01 10:00:00');
	news($app, 'novejsi', '2026-01-02 10:00:00');
	news($app, 'nejnovejsi', '2026-01-03 10:00:00');
	news($app, 'naplanovana', '2099-01-01 10:00:00');
	news($app, 'koncept', '2026-01-04 10:00:00', status: PublicationStatus::Draft);
	news($app, 'english', '2026-01-05 10:00:00', 'en');
	storeHomepage($app, 'cs', "Úvod\n\n::: novinky 2\n\nKonec");

	$result = $app->get('/');
	Assert::same(200, $result->code);
	Assert::same(['Title nejnovejsi', 'Title novejsi'], componentTitles($result));
	Assert::same('/novinky/nejnovejsi', (string) $result->dom()->find('[data-testid="component-news-item"] [data-testid="news-item-title"]')[0]['href']);
	Assert::same('Perex nejnovejsi', $result->text('news-item-perex'));
	Assert::same('3. 1. 2026', $result->text('news-item-date'));
	$all = $result->dom()->find('[data-testid="component-news-all"]');
	Assert::count(1, $all);
	Assert::same('/novinky', (string) $all[0]['href']);
	Assert::same('Všechny novinky →', trim((string) $all[0]));
	// The component sits between the paragraphs of the content.
	Assert::match('%A%<p>Úvod</p>%A%data-testid="component-news"%A%<p>Konec</p>%A%', (string) $result->dom()->find('[data-testid="page-content"]')[0]->asXML());
	Assert::same([], warnings($result));
	Assert::false($result->sessionStarted());
});


test('the English page lists only English news and links to the English list', function () use ($app): void {
	resetNewsDemo($app);
	news($app, 'cesky', '2026-01-03 10:00:00');
	news($app, 'english-one', '2026-01-01 10:00:00', 'en');
	news($app, 'english-two', '2026-01-02 10:00:00', 'en');
	news($app, 'english-later', '2099-01-01 10:00:00', 'en');
	storeHomepage($app, 'en', '::: novinky');

	$result = $app->get('/en/');
	Assert::same(200, $result->code);
	Assert::same(['Title english-two', 'Title english-one'], componentTitles($result));
	Assert::same('/en/news/english-two', (string) $result->dom()->find('[data-testid="component-news-item"] [data-testid="news-item-title"]')[0]['href']);
	$all = $result->dom()->find('[data-testid="component-news-all"]');
	Assert::same('/en/news', (string) $all[0]['href']);
	Assert::same('All news →', trim((string) $all[0]));
});


test('without visible news the component renders nothing', function () use ($app): void {
	resetNewsDemo($app);
	news($app, 'naplanovana', '2099-01-01 10:00:00');
	storeHomepage($app, 'cs', "Úvod\n\n::: novinky\n");

	$result = $app->get('/');
	Assert::same(200, $result->code);
	Assert::same('Úvod', $result->text('page-content'));
	Assert::null($result->text('component-news'));
	Assert::null($result->text('component-empty'));
	Assert::same([], warnings($result));
});


test('a broken marker stored in a page is left out with a warning, never a server error or a session', function () use ($app): void {
	resetNewsDemo($app);
	news($app, 'jedna', '2026-01-01 10:00:00');
	storeHomepage($app, 'cs', "Úvod\n\n::: neznamy\n:::novinky\n::: novinky 0\n\nKonec");

	$result = $app->get('/');
	Assert::same(200, $result->code);
	Assert::same('Úvod Konec', $result->text('page-content'));
	Assert::null($result->text('component-news'));
	Assert::null($result->text('component-error'));
	Assert::notContains(':::', $result->body);
	$page = homepageId($app);
	Assert::same([
		"Component marker left out: \"neznamy\" on line 3 of page $page (cs), content.component.unknown.",
		"Component marker left out: malformed \":::novinky\" on line 4 of page $page (cs), content.component.syntax.",
		"Component marker left out: \"novinky\" on line 5 of page $page (cs), news.component.arguments.",
	], warnings($result));
	Assert::false($result->sessionStarted());
});


test('a news item with the news component stored in it leaves the component out with a warning', function () use ($app): void {
	resetNewsDemo($app);
	$id = news($app, 'jedna', '2026-01-01 10:00:00', content: 'Text.');
	db($app)->query('UPDATE news_translation SET content = ? WHERE news_id = ?', "Text.\n\n::: novinky\n", $id);

	$result = $app->get('/novinky/jedna');
	Assert::same(200, $result->code);
	Assert::same('Text.', $result->text('page-content'));
	Assert::null($result->text('component-news'));
	Assert::same(["Component marker left out: \"novinky\" on line 3 of news $id (cs), content.component.notAllowedInNews."], warnings($result));
	Assert::false($result->sessionStarted());
});


test('a marker in code and an escaped marker stay text', function () use ($app): void {
	resetNewsDemo($app);
	news($app, 'jedna', '2026-01-01 10:00:00');
	storeHomepage($app, 'cs', "```\n::: novinky 3\n```\n\n\\::: novinky\n");

	$result = $app->get('/');
	Assert::same(200, $result->code);
	Assert::same('::: novinky 3', trim((string) $result->dom()->find('[data-testid="page-content"] pre code')[0]));
	Assert::same('::: novinky', trim((string) $result->dom()->find('[data-testid="page-content"] p')[0]));
	Assert::null($result->text('component-news'));
	Assert::same([], warnings($result));
});


test('an item of the component is the same html as the item of the news list', function () use ($app): void {
	resetNewsDemo($app);
	news($app, 'jedna', '2026-01-01 10:00:00');
	storeHomepage($app, 'cs', '::: novinky 1');

	$item = static function (HttpResult $result, string $testId): string {
		$found = $result->dom()->find('[data-testid="' . $testId . '"]');
		Assert::count(1, $found, $testId);
		$xml = '';
		foreach ($found[0]->children() as $child) {
			$xml .= $child->asXML();
		}

		return $xml;
	};

	$component = $item($app->get('/'), 'component-news-item');
	Assert::contains('data-testid="news-item-title"', $component);
	Assert::same($item($app->get('/novinky'), 'news-item'), $component);
});
