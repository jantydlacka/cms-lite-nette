<?php

declare(strict_types=1);

use App\Model\Page\PageRepository;
use App\Model\Page\PageService;
use App\Model\Page\SavePageTranslation;
use App\Model\User\Role;
use Nette\Database\Explorer;
use Tester\Assert;
use Tests\Presentation\Admin\AdminBrowser;
use Tests\Support\HttpApplication;
use Tests\Support\HttpResult;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../bootstrap.php';

const EDITOR = 'delete-editor@example.com';


function resetDemo(HttpApplication $app): void
{
	TestDatabase::resetDummyData($app->container);
	AdminBrowser::seed($app->container, EDITOR, 'Eva Editorová', Role::Editor);
	$app->newBrowser();
	AdminBrowser::signIn($app, EDITOR);
}


/** @return list<SimpleXMLElement> */
function nodes(SimpleXMLElement $context, string $xpath): array
{
	$found = $context->xpath($xpath);

	return is_array($found) ? array_values($found) : [];
}


function pageId(HttpApplication $app, string $title): int
{
	$id = $app->container->getByType(Explorer::class)->table('page_translation')->where('locale', 'cs')->where('title', $title)->fetch()?->page_id;
	assert(is_int($id), "page '$title'");

	return $id;
}


function pageCount(HttpApplication $app): int
{
	return $app->container->getByType(Explorer::class)->table('page')->count('*');
}


/** @return array{string, array<string, string>} action and body of the delete form as a browser would send it */
function deleteForm(HttpResult $result): array
{
	$forms = nodes($result->dom(), '//form[@data-testid="page-delete-form"]');
	Assert::count(1, $forms);
	Assert::same('post', strtolower((string) $forms[0]['method']));
	$body = [];
	foreach (nodes($forms[0], './/input[@name]') as $input) {
		if (strtolower((string) $input['type']) !== 'submit') {
			$body[(string) $input['name']] = (string) $input['value'];
		}
	}
	$buttons = nodes($forms[0], './/*[@data-testid="page-delete-confirm"]');
	Assert::count(1, $buttons);
	$body[(string) $buttons[0]['name']] = '';

	return [(string) $forms[0]['action'], $body];
}


function expireSignIn(HttpApplication $app): void
{
	$id = $app->cookies()[(string) session_name()] ?? throw new LogicException('No session cookie in the jar.');
	session_id($id);
	session_start();
	try {
		$nf = $_SESSION['__NF'] ?? null;
		assert(is_array($nf) && is_array($nf['DATA'] ?? null));
		$section = $nf['DATA']['Nette.Http.UserStorage/'] ?? null;
		assert(is_array($section));
		$nf['DATA']['Nette.Http.UserStorage/'] = ['expireTime' => time() - 1] + $section;
		$_SESSION['__NF'] = $nf;
	} finally {
		session_write_close();
		session_id('');
		$_SESSION = [];
	}
}


/** Forged POST: the form of a deletable page, posted to the delete address of another page. */
function forgedDelete(HttpApplication $app, int $leaf, int $target): HttpResult
{
	[$action, $body] = deleteForm($app->get("/admin/pages/$leaf/delete"));
	$forged = str_replace("/admin/pages/$leaf/delete", "/admin/pages/$target/delete", $action);
	Assert::notSame($action, $forged);

	return $app->post($forged, $body);
}


$app = HttpApplication::boot([['cms' => ['locales' => ['cs', 'en']]]]);


test('the detail links to the confirmation, which asks and offers delete and back', function () use ($app): void {
	resetDemo($app);
	$contact = pageId($app, 'Kontakt');
	$detail = $app->get("/admin/pages/$contact");
	$link = nodes($detail->dom(), '//a[@data-testid="page-delete"]');
	Assert::count(1, $link);
	Assert::same("/admin/pages/$contact/delete", (string) $link[0]['href']);
	Assert::same('Smazat stránku', trim((string) $link[0]));

	$screen = $app->get("/admin/pages/$contact/delete");
	Assert::same(200, $screen->code);
	Assert::same('Opravdu smazat stránku „Kontakt“? Smažou se všechny jazyky a staré adresy přestanou přesměrovávat.', $screen->text('page-delete-question'));
	$back = nodes($screen->dom(), '//a[@data-testid="page-delete-back"]');
	Assert::count(1, $back);
	Assert::same("/admin/pages/$contact", (string) $back[0]['href']);
	Assert::same('Zpět', trim((string) $back[0]));
	deleteForm($screen);
});


test('GET never deletes', function () use ($app): void {
	resetDemo($app);
	$contact = pageId($app, 'Kontakt');
	$count = pageCount($app);
	$app->get("/admin/pages/$contact/delete");
	$app->get("/admin/pages/$contact/delete?do=delete-submit&delete-delete=1");
	Assert::same($count, pageCount($app));
});


test('a leaf page is deleted with its translations and its old addresses', function () use ($app): void {
	resetDemo($app);
	$contact = pageId($app, 'Kontakt');
	$repository = $app->container->getByType(PageRepository::class);
	$old = $repository->findTranslation($contact, 'cs');
	assert($old !== null);
	$app->container->getByType(PageService::class)->saveTranslation(new SavePageTranslation(
		$contact,
		'cs',
		$old->title,
		'spojte-se',
		$old->content,
		$old->metaDescription,
		$old->status,
		$old->version,
	));
	Assert::same(301, $app->get('/kontakt')->code);
	Assert::same(200, $app->get('/spojte-se')->code);
	Assert::true(str_contains($app->get('/admin/pages')->body, "/admin/pages/$contact"));

	[$action, $body] = deleteForm($app->get("/admin/pages/$contact/delete"));
	$result = $app->post($action, $body);
	Assert::same(303, $result->code);
	Assert::match('/admin/pages?_fid=%a%', (string) $result->location);
	$tree = $app->get((string) $result->location);
	Assert::same('Stránka byla smazána.', $tree->text('flash'));
	Assert::false(str_contains($tree->body, "/admin/pages/$contact"));

	Assert::null($repository->getPage($contact));
	Assert::same(404, $app->get('/spojte-se')->code);
	Assert::same(404, $app->get('/kontakt')->code);
	Assert::same(404, $app->get("/admin/pages/$contact/delete")->code);
});


test('the homepage and a page with subpages show the reason, offer no delete and survive a forged POST', function () use ($app): void {
	resetDemo($app);
	$leaf = pageId($app, 'Kontakt');
	$cases = [
		['Úvod', 'Úvodní stránku nejde smazat ani skrýt, dokud nevyberete jinou.'],
		['O nás', 'Stránku s podstránkami nejde smazat.'],
	];
	foreach ($cases as [$title, $reason]) {
		$id = pageId($app, $title);
		$count = pageCount($app);
		$screen = $app->get("/admin/pages/$id/delete");
		Assert::same(200, $screen->code, $title);
		Assert::same($reason, $screen->text('page-delete-reason'), $title);
		Assert::null($screen->text('page-delete-question'), $title);
		Assert::count(0, nodes($screen->dom(), '//*[@data-testid="page-delete-confirm"]'), $title);
		Assert::count(1, nodes($screen->dom(), '//a[@data-testid="page-delete-back"]'), $title);

		$forged = forgedDelete($app, $leaf, $id);
		Assert::same(303, $forged->code, $title);
		$again = $app->get((string) $forged->location);
		Assert::same($reason, $again->text('flash'), $title);
		Assert::same($reason, $again->text('page-delete-reason'), $title);
		Assert::same($count, pageCount($app), $title);
		Assert::notNull($app->container->getByType(PageRepository::class)->getPage($id), $title);
	}
	Assert::same(200, $app->get('/')->code);
});


test('a forged POST for a missing page is a 404', function () use ($app): void {
	resetDemo($app);
	$leaf = pageId($app, 'Kontakt');
	Assert::same(404, $app->get('/admin/pages/999999/delete')->code);
	Assert::same(404, forgedDelete($app, $leaf, 999999)->code);
});


test('a cross-site POST deletes nothing', function () use ($app): void {
	resetDemo($app);
	$contact = pageId($app, 'Kontakt');
	[$action, $body] = deleteForm($app->get("/admin/pages/$contact/delete"));
	$count = pageCount($app);
	Assert::same(303, $app->post($action, $body, ['Sec-Fetch-Site' => 'cross-site'])->code);
	Assert::same($count, pageCount($app));
	Assert::notNull($app->container->getByType(PageRepository::class)->getPage($contact));
});


test('a POST restored after sign-in shows the screen and deletes nothing', function () use ($app): void {
	resetDemo($app);
	$contact = pageId($app, 'Kontakt');
	[$action, $body] = deleteForm($app->get("/admin/pages/$contact/delete"));
	expireSignIn($app);
	$stored = $app->post($action, $body);
	Assert::same(303, $stored->code);
	Assert::match('/admin/sign-in?backlink=%a%', (string) $stored->location);

	$restored = AdminBrowser::signIn($app, EDITOR, page: (string) $stored->location);
	Assert::same(200, $restored->code);
	Assert::notNull($restored->text('page-delete-question'));
	Assert::null($restored->text('flash'));
	Assert::notNull($app->container->getByType(PageRepository::class)->getPage($contact));

	// Clicking [Smazat] on the restored screen deletes.
	[$action, $body] = deleteForm($restored);
	Assert::same(303, $app->post($action, $body)->code);
	Assert::null($app->container->getByType(PageRepository::class)->getPage($contact));
});


test('without sign-in the confirmation redirects to sign-in', function () use ($app): void {
	resetDemo($app);
	$contact = pageId($app, 'Kontakt');
	$app->newBrowser();
	$result = $app->get("/admin/pages/$contact/delete");
	Assert::same(302, $result->code);
	Assert::match('/admin/sign-in?backlink=%a%', (string) $result->location);
});


test('the delete form posted to another view is a 404 and deletes nothing', function () use ($app): void {
	resetDemo($app);
	$contact = pageId($app, 'Kontakt');
	[$action, $body] = deleteForm($app->get("/admin/pages/$contact/delete"));
	$count = pageCount($app);
	foreach (["/admin/pages/$contact", '/admin/pages'] as $view) {
		$moved = str_replace("/admin/pages/$contact/delete", $view, $action);
		Assert::notSame($action, $moved);
		Assert::same(404, $app->post($moved, $body)->code, $view);
	}
	Assert::same($count, pageCount($app));
	Assert::notNull($app->container->getByType(PageRepository::class)->getPage($contact));
});


test('a page without a default-language translation is titled as missing on the confirmation', function () use ($app): void {
	resetDemo($app);
	$contact = pageId($app, 'Kontakt');
	$app->container->getByType(Explorer::class)->query('DELETE FROM page_translation WHERE page_id = ? AND locale = ?', $contact, 'cs');
	$screen = $app->get("/admin/pages/$contact/delete");
	Assert::same(200, $screen->code);
	Assert::contains('„Chybí překlad“', (string) $screen->text('page-delete-question'));
});
