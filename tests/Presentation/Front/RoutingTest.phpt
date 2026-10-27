<?php

declare(strict_types=1);

use App\Model\Page\PageRepository;
use App\Model\Page\PageService;
use App\Model\Page\SavePageTranslation;
use App\Model\PublicationStatus;
use App\Model\Redirect\PathRedirectRepository;
use App\Model\Redirect\RedirectTarget;
use App\Model\Setting\SettingRepository;
use Nette\DI\Container;
use Tester\Assert;
use Tests\FakeLogger;
use Tests\Presentation\Front\FixedRedirectRepository;
use Tests\Presentation\Front\ThrowingPageRepository;
use Tests\Presentation\Front\ThrowingUserRepository;
use Tests\Support\HttpApplication;
use Tests\Support\HttpResult;
use Tracy\ILogger;

require __DIR__ . '/../../bootstrap.php';

const ROUTING_CONFIG = __DIR__ . '/routing.neon';


// Demo-like pages: Úvod (homepage) with Historie (not in the menu, no description), O nás + en About us, Tým (child of O nás), a draft and three old paths.
function seed(Container $container): void
{
	$pages = $container->getByType(PageRepository::class);
	$service = $container->getByType(PageService::class);
	$save = static function (int $pageId, string $locale, string $title, string $slug, PublicationStatus $status = PublicationStatus::Published, ?string $description = null) use ($service): void {
		$service->saveTranslation(new SavePageTranslation($pageId, $locale, $title, $slug, "Text of **$title**.", $description ?? "About $title", $status, null));
	};

	$home = $pages->createPage(null, 0, true);
	$save($home, 'cs', 'Úvod', 'uvod');
	$history = $pages->createPage($home, 0, false);
	$save($history, 'cs', 'Historie', 'historie', description: '');
	$about = $pages->createPage(null, 1, true);
	$save($about, 'cs', 'O nás', 'o-nas');
	$save($about, 'en', 'About us', 'about-us');
	$team = $pages->createPage($about, 0, true);
	$save($team, 'cs', 'Tým', 'tym');
	$draft = $pages->createPage(null, 2, true);
	$save($draft, 'cs', 'Rozpracováno', 'rozpracovano', PublicationStatus::Draft);

	$container->getByType(SettingRepository::class)->set('homepage', (string) $home);
	$redirects = $container->getByType(PathRedirectRepository::class);
	$redirects->record('cs', 'o-firme', new RedirectTarget('page', $about));
	$redirects->record('cs', 'stary-koncept', new RedirectTarget('page', $draft));
	$redirects->record('cs', 'stary-uvod', new RedirectTarget('page', $home));
}


$app = HttpApplication::boot([ROUTING_CONFIG]);
seed($app->container);


function assertNotFound(HttpResult $result): void
{
	Assert::same(404, $result->code);
	Assert::null($result->location);
	Assert::notSame(null, $result->text('error-title'));
}


test('/ renders the homepage', function () use ($app): void {
	$result = $app->get('/');

	Assert::same(200, $result->code);
	Assert::same('Úvod', $result->text('page-title'));
	Assert::contains('Text of', $result->text('page-content') ?? '');
	Assert::contains('<strong>Úvod</strong>', $result->body);
	Assert::contains('<html lang="cs">', $result->body);
	Assert::null($result->text('breadcrumbs'));
});


test('/o-nas renders the page with the active menu item', function () use ($app): void {
	$result = $app->get('/o-nas');

	Assert::same(200, $result->code);
	Assert::same('O nás', $result->text('page-title'));
	Assert::contains('<strong>O nás</strong>', $result->body);
	Assert::match('%A%<title>O nás | CMS Lite</title>%A%', $result->body);
	Assert::match('%A%<meta name="description" content="About O nás">%A%', $result->body);
	Assert::null($result->text('breadcrumbs'));

	$menu = $result->dom()->find('[data-testid="menu"] [data-testid="menu-item"]');
	$items = array_map(static fn (SimpleXMLElement $a): array => [(string) $a['href'], (string) $a, (string) $a['aria-current']], $menu);
	Assert::same([['/', 'Úvod', ''], ['/o-nas', 'O nás', 'page'], ['/tym', 'Tým', '']], $items);
	Assert::same('Hlavní menu', (string) $result->dom()->find('[data-testid="menu"]')[0]['aria-label']);
});


test('/tym renders breadcrumbs from the root', function () use ($app): void {
	$result = $app->get('/tym');

	Assert::same(200, $result->code);
	Assert::same('O nás › Tým', $result->text('breadcrumbs'));
	$crumbs = $result->dom()->find('[data-testid="breadcrumbs"] a');
	Assert::same(['/o-nas'], array_map(static fn (SimpleXMLElement $a): string => (string) $a['href'], $crumbs));
	Assert::count(1, $result->dom()->find('[data-testid="breadcrumbs"] [aria-current="page"]'));
	Assert::same('Drobečková navigace', (string) $result->dom()->find('[data-testid="breadcrumbs"]')[0]['aria-label']);
});


test('the homepage under its own path redirects to /', function () use ($app): void {
	$result = $app->get('/uvod');

	Assert::same(301, $result->code);
	Assert::same('/', $result->location);
});


test('breadcrumbs through the homepage link it to /', function () use ($app): void {
	$result = $app->get('/historie');

	Assert::same(200, $result->code);
	Assert::same('Úvod › Historie', $result->text('breadcrumbs'));
	Assert::same(['/'], array_map(static fn (SimpleXMLElement $a): string => (string) $a['href'], $result->dom()->find('[data-testid="breadcrumbs"] a')));
});


test('an empty meta description renders no meta tag', function () use ($app): void {
	$result = $app->get('/historie');

	Assert::same(200, $result->code);
	Assert::notContains('name="description"', $result->body);
});


test('an old path of the homepage redirects to / in one step', function () use ($app): void {
	$result = $app->get('/stary-uvod');

	Assert::same(301, $result->code);
	Assert::same('/', $result->location);
});


test('a draft page is not found', function () use ($app): void {
	assertNotFound($app->get('/rozpracovano'));
});


test('an old path redirects to the current path', function () use ($app): void {
	$result = $app->get('/o-firme');

	Assert::same(301, $result->code);
	Assert::same('/o-nas', $result->location);
});


test('an old path of a draft page is not found', function () use ($app): void {
	assertNotFound($app->get('/stary-koncept'));
});


test('an unknown path renders the 404 page', function () use ($app): void {
	$result = $app->get('/neexistuje');

	assertNotFound($result);
	Assert::same('Stránka nenalezena', $result->text('error-title'));
	Assert::contains('<html lang="cs">', $result->body);
	Assert::null($result->text('menu'));
});


foreach (['/admin/neco', '/O-NAS', '/en/O-NAS', '/%C3%BA', '/o--nas', '/o-nas%20', '/-o-nas', 'a × 500' => '/' . str_repeat('a', 500), 'a × 501' => '/' . str_repeat('a', 501)] as $name => $path) {
	test('a non-canonical or invalid path is not found: ' . (is_string($name) ? $name : $path), function () use ($app, $path): void {
		assertNotFound($app->get($path));
	});
}


test('/admin/ redirects like any trailing slash and /admin is the administration, not a page', function () use ($app): void {
	$result = $app->get('/admin/');
	Assert::same(301, $result->code);
	Assert::same('/admin', $result->location);

	$result = $app->get('/admin');
	Assert::same(302, $result->code);
	Assert::match('/admin/sign-in?backlink=%a%', (string) $result->location);
});


test('a trailing slash redirects to the path without it', function () use ($app): void {
	$result = $app->get('/o-nas/');
	Assert::same(301, $result->code);
	Assert::same('/o-nas', $result->location);

	$result = $app->get('/en/about-us/');
	Assert::same(301, $result->code);
	Assert::same('/en/about-us', $result->location);
});


test('/en/about-us renders the English translation in English', function () use ($app): void {
	$result = $app->get('/en/about-us');

	Assert::same(200, $result->code);
	Assert::contains('<html lang="en">', $result->body);
	Assert::same('About us', $result->text('page-title'));
	Assert::same('Main menu', (string) $result->dom()->find('[data-testid="menu"]')[0]['aria-label']);
	Assert::match('%A%data-testid="theme-toggle" aria-label="Dark mode"%A%', $result->body);
});


test('menu links in English carry the /en/ prefix', function () use ($app): void {
	$menu = $app->get('/en/about-us')->dom()->find('[data-testid="menu-item"]');

	Assert::same(['/en/about-us'], array_map(static fn (SimpleXMLElement $a): string => (string) $a['href'], $menu));
	Assert::same('page', (string) $menu[0]['aria-current']);
});


test('/en without a slash redirects to /en/', function () use ($app): void {
	$result = $app->get('/en');

	Assert::same(301, $result->code);
	Assert::same('/en/', $result->location);
});


test('/en/ without an English homepage is not found', function () use ($app): void {
	assertNotFound($app->get('/en/'));
});


test('/en/ renders the English homepage once its translation is published', function () use ($app): void {
	$home = (int) $app->container->getByType(SettingRepository::class)->get('homepage');
	$app->container->getByType(PageService::class)->saveTranslation(new SavePageTranslation($home, 'en', 'Home', 'home', 'Text of **Home**.', 'About Home', PublicationStatus::Published, null));

	$result = $app->get('/en/');

	Assert::same(200, $result->code);
	Assert::same('Home', $result->text('page-title'));
	Assert::contains('<html lang="en">', $result->body);
});


test('a Czech path under /en/ is not found, without a fallback language', function () use ($app): void {
	assertNotFound($app->get('/en/o-nas'));
});


test('the English 404 page is in English', function () use ($app): void {
	$result = $app->get('/en/neexistuje');

	assertNotFound($result);
	Assert::contains('<html lang="en">', $result->body);
	Assert::same('Page not found', $result->text('error-title'));
});


test('a locale in the query string does not switch the language', function () use ($app): void {
	$result = $app->get('/o-nas?locale=en');

	Assert::true(in_array($result->code, [200, 301], true));
	Assert::notContains('<html lang="en">', $result->body);
	Assert::notSame('/en/about-us', $result->location);
});


// The tests below boot their own application, which wipes the shared data above.
test('a redirect to anything but a page is not followed', function (): void {
	$app = HttpApplication::boot([ROUTING_CONFIG, ['services' => ['pathRedirectRepository' => ['create' => FixedRedirectRepository::class]]]]);
	$page = $app->container->getByType(PageRepository::class)->createPage(null, 0, true);
	$app->container->getByType(PageService::class)->saveTranslation(new SavePageTranslation($page, 'cs', 'O nás', 'o-nas', 'Text', '', PublicationStatus::Published, null));

	FixedRedirectRepository::$target = new RedirectTarget('page', $page);
	Assert::same('/o-nas', $app->get('/stara-cesta')->location);

	FixedRedirectRepository::$target = new RedirectTarget('news', $page);
	assertNotFound($app->get('/stara-cesta'));
});


test('error presenters requested directly are not found', function (): void {
	$app = HttpApplication::boot([ROUTING_CONFIG, __DIR__ . '/direct-error-routes.neon']);

	assertNotFound($app->get('/direct-error-4xx'));
	assertNotFound($app->get('/direct-error-5xx'));
});


test('the 500 page renders without the page repository', function (): void {
	$app = HttpApplication::boot([ROUTING_CONFIG, ['services' => ['pageRepository' => ['create' => ThrowingPageRepository::class], 'userRepository' => ['create' => ThrowingUserRepository::class]]]]);

	$result = $app->get('/o-nas');

	Assert::same(500, $result->code);
	Assert::same('Něco se pokazilo', $result->text('error-title'));
	Assert::null($result->text('menu'));
	$logger = $result->container->getService('tracy.logger');
	assert($logger instanceof FakeLogger);
	Assert::count(1, array_filter($logger->records, static fn (array $record): bool => $record[0] instanceof RuntimeException && $record[1] === ILogger::EXCEPTION));

	// The router rejects a path longer than any stored one before a query is made.
	assertNotFound($app->get('/' . str_repeat('a', 501)));
	Assert::same(500, $app->get('/' . str_repeat('a', 500))->code);
});


test('error pages use the language of the URL and need neither database nor session', function (): void {
	$app = HttpApplication::boot([ROUTING_CONFIG, ['services' => ['pageRepository' => ['create' => ThrowingPageRepository::class], 'userRepository' => ['create' => ThrowingUserRepository::class]]]]);

	foreach (['/en/O-NAS' => ['en', 'Page not found'], '/en/o-nas/x/y/z' . str_repeat('a', 500) => ['en', 'Page not found'], '/O-NAS' => ['cs', 'Stránka nenalezena'], '/admin/neexistuje' => ['cs', 'Stránka nenalezena'], '/enx/O-NAS' => ['cs', 'Stránka nenalezena']] as $path => [$lang, $title]) {
		$result = $app->get($path);
		Assert::contains("<html lang=\"$lang\">", $result->body, $path);
		Assert::same($title, $result->text('error-title'), $path);
		Assert::false($result->sessionStarted(), $path);
	}
});


test('a malformed homepage setting is a 500, a setting naming a missing page is a 404', function (): void {
	$app = HttpApplication::boot([ROUTING_CONFIG]);
	$settings = $app->container->getByType(SettingRepository::class);

	$settings->set('homepage', 'abc');
	$result = $app->get('/');
	Assert::same(500, $result->code);
	Assert::same('Něco se pokazilo', $result->text('error-title'));
	$logger = $result->container->getService('tracy.logger');
	assert($logger instanceof FakeLogger);
	Assert::count(1, array_filter($logger->records, static fn (array $record): bool => $record[0] instanceof UnexpectedValueException && $record[1] === ILogger::EXCEPTION));

	$settings->set('homepage', '999999');
	assertNotFound($app->get('/'));
});
