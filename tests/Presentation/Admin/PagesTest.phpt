<?php

declare(strict_types=1);

use App\Model\Page\CreatePage;
use App\Model\Page\PageRepository;
use App\Model\Page\PageService;
use App\Model\PublicationStatus;
use App\Model\User\Role;
use Tester\Assert;
use Tests\Presentation\Admin\AdminBrowser;
use Tests\Support\HttpApplication;
use Tests\Support\HttpResult;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../bootstrap.php';

// The demo data have their own accounts with a public password; these use AdminBrowser::Password.
const EDITOR = 'pages-editor@example.com';
const ADMIN = 'pages-admin@example.com';
const DEMO_ORDER = ['Úvod', 'O nás', 'Tým', 'Kontakt', 'Rozpracováno'];


// Demo pages and two fresh accounts; every mutating test starts from here.
function resetDemo(HttpApplication $app): void
{
	TestDatabase::resetDummyData($app->container);
	AdminBrowser::seed($app->container, EDITOR, 'Eva Editorová', Role::Editor);
	AdminBrowser::seed($app->container, ADMIN, 'Adam Admin', Role::Admin);
}


/** @return list<string> titles of the tree rows in document order */
function treeTitles(HttpResult $result): array
{
	return array_map(
		static fn (SimpleXMLElement $title): string => trim((string) preg_replace('~\s+~u', ' ', HttpResult::textContent($title))),
		nodes($result->dom(), '//*[@data-testid="pages-tree"]//*[@data-testid="pages-item-title"]'),
	);
}


/** @return list<SimpleXMLElement> */
function nodes(SimpleXMLElement $context, string $xpath): array
{
	$found = $context->xpath($xpath);

	return is_array($found) ? array_values($found) : [];
}


function treeRow(HttpResult $result, string $title): SimpleXMLElement
{
	$rows = nodes($result->dom(), '//*[@data-testid="pages-item"][.//*[@data-testid="pages-item-title"][normalize-space()="' . $title . '"]]');
	Assert::count(1, $rows, "row '$title'");

	return $rows[0];
}


/**
 * The form of the ↑/↓ button in the row, with the body a browser would send by clicking it.
 *
 * @return array{string, array<string, string>} action and body
 */
function moveButton(HttpResult $result, string $title, string $testId): array
{
	$buttons = nodes(treeRow($result, $title), './/*[@data-testid="' . $testId . '"]');
	Assert::count(1, $buttons, "$testId of '$title'");
	$forms = nodes($buttons[0], 'ancestor::form');
	Assert::count(1, $forms);
	$form = $forms[0];
	Assert::same('post', strtolower((string) $form['method']));

	$body = [];
	foreach (nodes($form, './/input[@type="hidden"]') as $input) {
		$body[(string) $input['name']] = (string) $input['value'];
	}
	$body[(string) $buttons[0]['name']] = '';

	return [(string) $form['action'], $body];
}


function pagesAs(HttpApplication $app, string $email): HttpResult
{
	$app->newBrowser();
	AdminBrowser::signIn($app, $email);

	return $app->get('/admin/pages');
}


$app = HttpApplication::boot();
resetDemo($app);


test('an editor and an admin see every demo page in tree order with the badges', function () use ($app): void {
	foreach ([EDITOR, ADMIN] as $email) {
		$result = pagesAs($app, $email);
		Assert::same(200, $result->code, $email);
		Assert::same('Stránky', $result->text('admin-title'));
		Assert::same(DEMO_ORDER, treeTitles($result), $email);

		$homepage = nodes($result->dom(), '//*[@data-testid="pages-homepage-badge"]');
		Assert::count(1, $homepage);
		Assert::same('Úvodní', trim((string) $homepage[0]));
		Assert::count(1, nodes(treeRow($result, 'Úvod'), './/*[@data-testid="pages-homepage-badge"]'));

		$drafts = nodes($result->dom(), '//*[@data-testid="pages-draft-badge"]');
		Assert::count(1, $drafts);
		Assert::count(1, nodes(treeRow($result, 'Rozpracováno'), './/*[@data-testid="pages-draft-badge"]'));

		Assert::same('1', (string) treeRow($result, 'Tým')['data-depth']);
		Assert::same('0', (string) treeRow($result, 'O nás')['data-depth']);
	}
});


test('each row links to the page detail and the tree page links to a new page', function () use ($app): void {
	$result = pagesAs($app, EDITOR);
	$links = nodes(treeRow($result, 'O nás'), './/a[@data-testid="pages-item-title"]');
	Assert::count(1, $links);
	Assert::match('~^/admin/pages/\d+$~', (string) $links[0]['href']);
	$new = nodes($result->dom(), '//a[@data-testid="pages-new"]');
	Assert::count(1, $new);
	Assert::same('/admin/pages/new', (string) $new[0]['href']);
	Assert::same('Nová stránka', trim((string) $new[0]));
});


test('the admin navigation is shown to a signed-in user only', function () use ($app): void {
	$result = pagesAs($app, EDITOR);
	$nav = nodes($result->dom(), '//a[@data-testid="admin-nav-pages"]');
	Assert::count(1, $nav);
	Assert::same('/admin/pages', (string) $nav[0]['href']);
	Assert::same('page', (string) $nav[0]['aria-current']);
	Assert::count(1, nodes($result->dom(), '//nav[@aria-label="Administrace"]//a[@data-testid="admin-nav-pages"]'));
	Assert::count(1, nodes($result->dom(), '//nav//a[@href="/admin"]'));
	Assert::count(0, nodes($result->dom(), '//nav//a[@href="/admin"][@aria-current]'));
	Assert::contains('Nástěnka', $result->body);

	$app->newBrowser();
	$signIn = $app->get('/admin/sign-in');
	Assert::same(200, $signIn->code);
	Assert::null($signIn->text('admin-nav-pages'));
	Assert::notContains('Nástěnka', $signIn->body);
});


test('the dashboard links to the pages', function () use ($app): void {
	pagesAs($app, EDITOR);
	$dashboard = $app->get('/admin');
	Assert::count(1, nodes($dashboard->dom(), '//main//a[@href="/admin/pages"]'));
});


test('the first sibling has no ↑ and the last has no ↓; the only child has neither', function () use ($app): void {
	$result = pagesAs($app, EDITOR);
	Assert::count(0, nodes(treeRow($result, 'Úvod'), './/*[@data-testid="pages-up"]'));
	Assert::count(1, nodes(treeRow($result, 'Úvod'), './/*[@data-testid="pages-down"]'));
	Assert::count(1, nodes(treeRow($result, 'Rozpracováno'), './/*[@data-testid="pages-up"]'));
	Assert::count(0, nodes(treeRow($result, 'Rozpracováno'), './/*[@data-testid="pages-down"]'));
	Assert::count(0, nodes(treeRow($result, 'Tým'), './/*[@data-testid="pages-up" or @data-testid="pages-down"]'));
});


test('the ↑/↓ buttons name the page they move', function () use ($app): void {
	$result = pagesAs($app, EDITOR);
	$up = nodes(treeRow($result, 'O nás'), './/*[@data-testid="pages-up"]');
	$down = nodes(treeRow($result, 'O nás'), './/*[@data-testid="pages-down"]');
	Assert::same('Posunout výš: O nás', (string) $up[0]['aria-label']);
	Assert::same('Posunout níž: O nás', (string) $down[0]['aria-label']);
	Assert::same('Posunout výš', (string) $up[0]['title']);
});


test('↑ on the second page swaps it with the first and says so', function () use ($app): void {
	resetDemo($app);
	[$action, $body] = moveButton(pagesAs($app, EDITOR), 'O nás', 'pages-up');

	$result = $app->post($action, $body);
	Assert::same(303, $result->code);
	Assert::match('/admin/pages?_fid=%a%', (string) $result->location);

	$tree = $app->get((string) $result->location);
	Assert::same('Pořadí bylo změněno.', $tree->text('flash'));
	Assert::same(['O nás', 'Tým', 'Úvod', 'Kontakt', 'Rozpracováno'], treeTitles($tree));
});


test('↓ moves a page below its next sibling', function () use ($app): void {
	resetDemo($app);
	[$action, $body] = moveButton(pagesAs($app, ADMIN), 'Kontakt', 'pages-down');

	Assert::same(303, $app->post($action, $body)->code);
	Assert::same(['Úvod', 'O nás', 'Tým', 'Rozpracováno', 'Kontakt'], treeTitles($app->get('/admin/pages')));
});


test('↑ among the children of a page reorders only those children', function () use ($app): void {
	resetDemo($app);
	$pages = $app->container->getByType(PageRepository::class);
	$about = $pages->findPublishedByPath('cs', 'o-nas')?->pageId;
	assert(is_int($about));
	$app->container->getByType(PageService::class)->createPage(new CreatePage($about, true, 'cs', 'Kariéra', '', 'text', '', PublicationStatus::Published));
	$before = pagesAs($app, EDITOR);
	Assert::same(['Úvod', 'O nás', 'Tým', 'Kariéra', 'Kontakt', 'Rozpracováno'], treeTitles($before));
	[$action, $body] = moveButton($before, 'Kariéra', 'pages-up');

	Assert::same(303, $app->post($action, $body)->code);
	Assert::same(['Úvod', 'O nás', 'Kariéra', 'Tým', 'Kontakt', 'Rozpracováno'], treeTitles($app->get('/admin/pages')));
});


test('a move submitted without a button changes nothing and returns to the tree', function () use ($app): void {
	resetDemo($app);
	[$action, $body] = moveButton(pagesAs($app, EDITOR), 'O nás', 'pages-up');
	unset($body['up']);

	$result = $app->post($action, $body);
	Assert::same(303, $result->code);
	Assert::match('/admin/pages%a?%', (string) $result->location);
	$tree = $app->get((string) $result->location);
	Assert::null($tree->text('flash'));
	Assert::same(DEMO_ORDER, treeTitles($tree));
});


test('a cross-site POST of ↑ changes nothing', function () use ($app): void {
	resetDemo($app);
	[$action, $body] = moveButton(pagesAs($app, EDITOR), 'O nás', 'pages-up');

	$result = $app->post($action, $body, ['Sec-Fetch-Site' => 'cross-site']);
	Assert::same(303, $result->code);
	$tree = $app->get('/admin/pages');
	Assert::null($tree->text('flash'));
	Assert::same(DEMO_ORDER, treeTitles($tree));
});


test('a GET with the form signal executes nothing', function () use ($app): void {
	resetDemo($app);
	[$action, $body] = moveButton(pagesAs($app, EDITOR), 'O nás', 'pages-up');

	$result = $app->get($action . '&' . http_build_query($body));
	Assert::notSame(303, $result->code);
	Assert::null($result->text('flash'));
	Assert::same(DEMO_ORDER, treeTitles($app->get('/admin/pages')));
});


test('a POST stored before sign-in is not executed when restored', function () use ($app): void {
	resetDemo($app);
	[$action, $body] = moveButton(pagesAs($app, EDITOR), 'O nás', 'pages-up');
	$app->newBrowser();

	$stored = $app->post($action, $body);
	Assert::same(303, $stored->code);
	Assert::match('/admin/sign-in?backlink=%a%', (string) $stored->location);

	$restored = AdminBrowser::signIn($app, EDITOR, page: (string) $stored->location);
	Assert::same(200, $restored->code);
	Assert::same(DEMO_ORDER, treeTitles($restored));
	Assert::null($restored->text('flash'));
	Assert::same(DEMO_ORDER, treeTitles($app->get('/admin/pages')));
});


test('↑ of a page that does not exist is 404, as is a malformed id', function () use ($app): void {
	pagesAs($app, EDITOR);
	Assert::same(404, $app->post('/admin/pages?do=move-999999-submit', ['up' => ''])->code);
	Assert::same(404, $app->post('/admin/pages?do=move-abc-submit', ['up' => ''])->code);
	Assert::same(404, $app->post('/admin/pages?do=move-01-submit', ['up' => ''])->code);
});


test('without sign-in the tree redirects to sign-in with a backlink', function () use ($app): void {
	$app->newBrowser();
	$result = $app->get('/admin/pages');
	Assert::same(302, $result->code);
	Assert::match('/admin/sign-in?backlink=%a%', (string) $result->location);

	$restored = AdminBrowser::signIn($app, EDITOR, page: (string) $result->location);
	Assert::same(303, $restored->code);
	Assert::same('/admin/pages', $restored->location);
});


test('an empty site shows the empty message', function (): void {
	$app = HttpApplication::boot();
	AdminBrowser::seed($app->container, EDITOR, 'Eva Editorová', Role::Editor);
	$result = pagesAs($app, EDITOR);
	Assert::same(200, $result->code);
	Assert::same([], treeTitles($result));
	Assert::contains('Zatím tu nejsou žádné stránky.', $result->body);
});


test('a page without a default-language translation shows the missing-translation title', function (): void {
	$app = HttpApplication::boot();
	AdminBrowser::seed($app->container, EDITOR, 'Eva Editorová', Role::Editor);
	$app->container->getByType(PageRepository::class)->createPage(null, 0, true);
	$result = pagesAs($app, EDITOR);
	Assert::same(['Chybí překlad'], treeTitles($result));
	Assert::null($result->text('pages-draft-badge'));
});
