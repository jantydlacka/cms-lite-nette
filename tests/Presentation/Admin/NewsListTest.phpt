<?php

declare(strict_types=1);

use App\Model\News\CreateNews;
use App\Model\News\NewsService;
use App\Model\News\SaveNewsTranslation;
use App\Model\PublicationStatus;
use App\Model\User\Role;
use Nette\Database\Explorer;
use Tester\Assert;
use Tests\Presentation\Admin\AdminBrowser;
use Tests\Support\HttpApplication;
use Tests\Support\HttpResult;

require __DIR__ . '/../../bootstrap.php';

const EDITOR = 'news-editor@example.com';
const ADMIN = 'news-admin@example.com';

// Two languages so that a translation can be missing. The dates are far from now, so the states do not depend on the clock.
$app = HttpApplication::boot([__DIR__ . '/../Front/routing.neon']);
$editorId = AdminBrowser::seed($app->container, EDITOR, 'Eva Editorová', Role::Editor);
AdminBrowser::seed($app->container, ADMIN, 'Adam Admin', Role::Admin);
$service = $app->container->getByType(NewsService::class);
$explorer = $app->container->getByType(Explorer::class);


function utc(string $at): DateTimeImmutable
{
	return new DateTimeImmutable($at, new DateTimeZone('UTC'));
}


function create(NewsService $service, int $author, string $slug, ?string $at, PublicationStatus $status, string $locale = 'cs'): int
{
	return $service->createNews(new CreateNews($at === null ? null : utc($at), $author, $locale, "Title $slug", $slug, 'Perex', 'Content', '', $status))->newsId;
}


function listAs(HttpApplication $app, string $email, string $path = '/admin/news'): HttpResult
{
	$app->newBrowser();
	AdminBrowser::signIn($app, $email);

	return $app->get($path);
}


/** @return list<string> */
function titles(HttpResult $result): array
{
	return array_map(static fn (SimpleXMLElement $a): string => trim((string) $a), $result->dom()->find('[data-testid="admin-news-item"] [data-testid="admin-news-item-title"]'));
}


/** @return array<string, string> state label by locale, of the row with the title */
function states(HttpResult $result, string $title): array
{
	foreach ($result->dom()->find('[data-testid="admin-news-item"]') as $row) {
		$found = $row->xpath('.//*[@data-testid="admin-news-item-title"][normalize-space()="' . $title . '"]');
		if (is_array($found) && $found !== []) {
			$states = [];
			foreach ($row->xpath('.//*[@data-testid="admin-news-state"]') ?? [] as $state) {
				$states[(string) $state['data-locale']] = trim((string) preg_replace('~\s+~u', ' ', HttpResult::textContent($state)));
			}

			return $states;
		}
	}

	throw new LogicException("No row '$title'.");
}


test('an anonymous visitor is sent to sign-in', function () use ($app): void {
	$app->newBrowser();
	$result = $app->get('/admin/news');
	Assert::same(302, $result->code);
	Assert::match('/admin/sign-in?backlink=%a%', (string) $result->location);
});


// The author is deleted at the end of this test, as the following ones need the SET NULL.
$deleted = AdminBrowser::seed($app->container, 'gone@example.com', 'Gone', Role::Editor);
create($service, $editorId, 'visible', '2000-01-01 10:00:00', PublicationStatus::Published);
create($service, $editorId, 'scheduled', '2999-06-15 08:30:00', PublicationStatus::Published);
create($service, $editorId, 'draft', null, PublicationStatus::Draft);
$mixed = create($service, $editorId, 'mixed', '2000-02-02 10:00:00', PublicationStatus::Published);
$service->saveTranslation(new SaveNewsTranslation($mixed, 'en', 'Title mixed en', 'mixed-en', 'Perex', 'Content', '', PublicationStatus::Draft, null));
create($service, $deleted, 'orphan', '2000-03-03 10:00:00', PublicationStatus::Published);
create($service, $editorId, 'only-english', '2000-04-04 10:00:00', PublicationStatus::Published, 'en');
$explorer->table('user')->where('id', $deleted)->delete();


test('an editor and an admin see the list; undated items first, then the newest', function () use ($app): void {
	foreach ([EDITOR, ADMIN] as $email) {
		$result = listAs($app, $email);
		Assert::same(200, $result->code, $email);
		Assert::same('Novinky', $result->text('admin-title'));
		Assert::same(['Title draft', 'Title scheduled', 'Title only-english', 'Title orphan', 'Title mixed', 'Title visible'], titles($result), $email);
		Assert::null($result->text('admin-news-empty'));
		Assert::null($result->text('admin-news-pagination'));
	}
});


test('every enabled language has a badge with its state', function () use ($app): void {
	$result = listAs($app, EDITOR);

	Assert::same(['cs' => 'cs Koncept', 'en' => 'en Chybí překlad'], states($result, 'Title draft'));
	Assert::same(['cs' => 'cs Naplánováno na 15. 6. 2999 10:30', 'en' => 'en Chybí překlad'], states($result, 'Title scheduled'));
	Assert::same(['cs' => 'cs Zveřejněno 1. 1. 2000', 'en' => 'en Chybí překlad'], states($result, 'Title visible'));
	Assert::same(['cs' => 'cs Zveřejněno 2. 2. 2000', 'en' => 'en Koncept'], states($result, 'Title mixed'));
});


test('the title falls back to the first existing translation', function () use ($app): void {
	$result = listAs($app, EDITOR);

	Assert::same(['cs' => 'cs Chybí překlad', 'en' => 'en Zveřejněno 4. 4. 2000'], states($result, 'Title only-english'));
});


test('the author is named, a deleted one is marked', function () use ($app): void {
	$result = listAs($app, EDITOR);
	$authors = array_map(static fn (SimpleXMLElement $a): string => trim((string) $a), $result->dom()->find('[data-testid="admin-news-author"]'));

	Assert::same(['Eva Editorová', 'Eva Editorová', 'Eva Editorová', '(smazaný uživatel)', 'Eva Editorová', 'Eva Editorová'], $authors);
});


test('the list links to a new item and the navigation marks the screen', function () use ($app): void {
	$result = listAs($app, EDITOR);

	$new = $result->dom()->find('a[data-testid="admin-news-new"]');
	Assert::count(1, $new);
	Assert::same('/admin/news/new', (string) $new[0]['href']);
	Assert::same('Nová novinka', trim((string) $new[0]));

	$nav = $result->dom()->find('a[data-testid="admin-nav-news"]');
	Assert::count(1, $nav);
	Assert::same('/admin/news', (string) $nav[0]['href']);
	Assert::same('page', (string) $nav[0]['aria-current']);
	Assert::same('Novinky', trim((string) $nav[0]));

	$pages = listAs($app, EDITOR, '/admin/pages');
	Assert::same(200, $pages->code);
	Assert::count(0, $pages->dom()->find('a[data-testid="admin-nav-news"][aria-current]'));
});


test('21 items make two pages; the rest is 404, page 1 is a redirect', function () use ($app, $service, $editorId, $explorer): void {
	$explorer->table('news')->delete();
	for ($i = 1; $i <= 21; $i++) {
		$id = $service->createNews(new CreateNews(utc(sprintf('2000-01-%02d 10:00:00', $i)), $editorId, 'cs', "N $i", "n-$i", 'Perex', 'Content', '', PublicationStatus::Published))->newsId;
		Assert::true($id > 0);
	}

	$first = listAs($app, EDITOR);
	Assert::count(20, titles($first));
	Assert::same('N 21', titles($first)[0]);
	Assert::contains('Strana 1 z 2', (string) $first->text('admin-news-pagination'));
	$older = $first->dom()->find('[data-testid="admin-news-pagination"] a[rel="next"]');
	Assert::count(1, $older);
	Assert::same('/admin/news?page=2', (string) $older[0]['href']);

	$second = $app->get('/admin/news?page=2');
	Assert::same(200, $second->code);
	Assert::same(['N 1'], titles($second));
	$newer = $second->dom()->find('[data-testid="admin-news-pagination"] a[rel="prev"]');
	Assert::same('/admin/news', (string) $newer[0]['href']);

	$one = $app->get('/admin/news?page=1');
	Assert::same(301, $one->code);
	Assert::same('/admin/news', $one->location);

	foreach (['0', '3', 'abc', '01', '-1', ''] as $page) {
		$result = $app->get('/admin/news?page=' . $page);
		Assert::same(404, $result->code, "page '$page'");
		Assert::null($result->location, "page '$page'");
	}
});


test('no news shows a message and no pagination', function () use ($app, $explorer): void {
	$explorer->table('news')->delete();
	$result = listAs($app, EDITOR);

	Assert::same(200, $result->code);
	Assert::same('Zatím tu nejsou žádné novinky.', $result->text('admin-news-empty'));
	Assert::null($result->text('admin-news-list'));
	Assert::same(404, $app->get('/admin/news?page=2')->code);
});
