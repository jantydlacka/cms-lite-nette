<?php

declare(strict_types=1);

use App\Model\Page\PageRepository;
use App\Model\Page\PageService;
use App\Model\Page\PageTranslation;
use App\Model\Page\UpdatePage;
use App\Model\PublicationStatus;
use App\Model\Setting\SettingRepository;
use App\Model\User\Role;
use Nette\Database\Explorer;
use Tester\Assert;
use Tests\Presentation\Admin\AdminBrowser;
use Tests\Support\HttpApplication;
use Tests\Support\HttpResult;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../bootstrap.php';

const EDITOR = 'edit-editor@example.com';
const ADMIN = 'edit-admin@example.com';


// Demo pages and two fresh accounts; every mutating test starts from here.
function resetDemo(HttpApplication $app): void
{
	TestDatabase::loadDummyData($app->container);
	AdminBrowser::seed($app->container, EDITOR, 'Eva Editorová', Role::Editor);
	AdminBrowser::seed($app->container, ADMIN, 'Adam Admin', Role::Admin);
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


function translation(HttpApplication $app, int $pageId, string $locale = 'cs'): ?PageTranslation
{
	return $app->container->getByType(PageRepository::class)->findTranslation($pageId, $locale);
}


function pageVersion(HttpApplication $app, int $pageId): int
{
	$page = $app->container->getByType(PageRepository::class)->getPage($pageId);
	assert($page !== null);

	return $page->version;
}


function signedIn(HttpApplication $app, string $email = EDITOR): void
{
	$app->newBrowser();
	AdminBrowser::signIn($app, $email);
}


/**
 * The form with the test id and the body a browser would send by clicking the button: hidden, text, checked
 * checkboxes, textareas and selected options, then the overrides (null drops a field).
 *
 * @param array<string, ?string> $overrides
 * @return array{string, array<string, string>} action and body
 */
function formSubmit(HttpResult $result, string $formTestId, string $buttonTestId, array $overrides = []): array
{
	$forms = nodes($result->dom(), '//form[@data-testid="' . $formTestId . '"]');
	Assert::count(1, $forms, $formTestId);
	$form = $forms[0];
	Assert::same('post', strtolower((string) $form['method']));

	$body = [];
	foreach (nodes($form, './/input[@name]') as $input) {
		$type = strtolower((string) $input['type']);
		if ($type === 'submit') {
			continue;
		}
		if ($type === 'checkbox' && !isset($input['checked'])) {
			continue;
		}
		$body[(string) $input['name']] = $type === 'checkbox' && !isset($input['value']) ? 'on' : (string) $input['value'];
	}
	foreach (nodes($form, './/textarea[@name]') as $textarea) {
		$body[(string) $textarea['name']] = dom_import_simplexml($textarea)->textContent;
	}
	foreach (nodes($form, './/select[@name]') as $select) {
		$selected = nodes($select, './/option[@selected]');
		$selected = $selected === [] ? nodes($select, './/option') : $selected;
		$body[(string) $select['name']] = $selected === [] ? '' : (string) $selected[0]['value'];
	}

	$buttons = nodes($form, './/*[@data-testid="' . $buttonTestId . '"]');
	Assert::count(1, $buttons, $buttonTestId);
	$body[(string) $buttons[0]['name']] = '';

	foreach ($overrides as $name => $value) {
		if ($value === null) {
			unset($body[$name]);
		} else {
			$body[$name] = $value;
		}
	}

	return [(string) $form['action'], $body];
}


/** @return array<int|string, string> option value (a page id as int) => text of the parent dropdown */
function parentOptions(HttpResult $result): array
{
	$options = [];
	foreach (nodes($result->dom(), '//select[@data-testid="page-parent"]/option') as $option) {
		$options[(string) $option['value']] = trim((string) $option);
	}

	return $options;
}


function fieldValue(HttpResult $result, string $testId): string
{
	$found = nodes($result->dom(), '//*[@data-testid="' . $testId . '"]');
	Assert::count(1, $found, $testId);
	$element = $found[0];
	if ($element->getName() === 'textarea') {
		return dom_import_simplexml($element)->textContent;
	}
	if ($element->getName() === 'select') {
		$selected = nodes($element, './/option[@selected]');

		return $selected === [] ? '' : (string) $selected[0]['value'];
	}
	if ((string) $element['type'] === 'checkbox') {
		return isset($element['checked']) ? 'on' : '';
	}

	return (string) $element['value'];
}


/** Value of the selected option of the parent dropdown; null when no option is marked selected. */
function selectedParent(HttpResult $result, string $formTestId): ?string
{
	$selected = nodes($result->dom(), '//form[@data-testid="' . $formTestId . '"]//select[@data-testid="page-parent"]/option[@selected]');

	return $selected === [] ? null : (string) $selected[0]['value'];
}


/** @param array<string, string> $cookies */
function useCookies(HttpApplication $app, array $cookies): void
{
	$app->newBrowser();
	foreach ($cookies as $name => $value) {
		$app->setCookie($name, $value);
	}
}


/** @return list<string> */
function errors(HttpResult $result, string $formTestId): array
{
	return array_map(
		static fn (SimpleXMLElement $item): string => trim((string) $item),
		nodes($result->dom(), '//form[@data-testid="' . $formTestId . '"]//*[@data-testid="form-error"]/li'),
	);
}


/** Moves the inactivity deadline of the signed-in session into the past, as AccessTest does. */
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
		Assert::true($section['authenticated'] ?? null);
		$nf['DATA']['Nette.Http.UserStorage/'] = ['expireTime' => time() - 1] + $section;
		$_SESSION['__NF'] = $nf;
	} finally {
		session_write_close();
		session_id('');
		$_SESSION = [];
	}
}


// Two languages, as with the demo configuration; the tests default to one.
$app = HttpApplication::boot([['cms' => ['locales' => ['cs', 'en']]]]);
resetDemo($app);


test('the detail shows the properties and the default-language translation', function () use ($app): void {
	signedIn($app);
	$about = pageId($app, 'O nás');
	$result = $app->get("/admin/pages/$about");
	Assert::same(200, $result->code);
	Assert::same('O nás', $result->text('admin-title'));
	Assert::same('O nás', fieldValue($result, 'page-title-input'));
	Assert::same('o-nas', fieldValue($result, 'page-slug-input'));
	Assert::contains('Jsme malý ukázkový web.', fieldValue($result, 'page-content-input'));
	Assert::same('Informace o ukázkovém webu.', fieldValue($result, 'page-meta-input'));
	Assert::same('published', fieldValue($result, 'page-status-input'));
	Assert::same('', fieldValue($result, 'page-parent'));
	Assert::same('on', fieldValue($result, 'page-in-menu'));
	Assert::contains('Prázdné = vytvoří se z názvu.', $result->body);
	Assert::null($result->text('page-preview-output'));

	$tabs = nodes($result->dom(), '//*[@data-testid="page-tab"]');
	Assert::count(2, $tabs);
	Assert::same("/admin/pages/$about", (string) $tabs[0]['href']);
	Assert::same('page', (string) $tabs[0]['aria-current']);
	Assert::same("/admin/pages/$about/en", (string) $tabs[1]['href']);
	Assert::false(isset($tabs[1]['aria-current']));

	$en = $app->get("/admin/pages/$about/en");
	Assert::same(200, $en->code);
	Assert::same('About us', fieldValue($en, 'page-title-input'));
	Assert::same('about-us', fieldValue($en, 'page-slug-input'));
});


test('saving a translation changes the content, raises the version and says so', function () use ($app): void {
	resetDemo($app);
	signedIn($app);
	$about = pageId($app, 'O nás');
	[$action, $body] = formSubmit($app->get("/admin/pages/$about"), 'page-translation-form', 'page-save', ['content' => "## Nový obsah\n\nText."]);
	Assert::same('1', $body['version']);

	$result = $app->post($action, $body);
	Assert::same(303, $result->code);
	Assert::match("/admin/pages/$about?_fid=%a%", (string) $result->location);

	$detail = $app->get((string) $result->location);
	Assert::same('Stránka byla uložena.', $detail->text('flash'));
	Assert::same("## Nový obsah\n\nText.", fieldValue($detail, 'page-content-input'));
	Assert::same('2', (string) nodes($detail->dom(), '//form[@data-testid="page-translation-form"]//input[@name="version"]')[0]['value']);

	$stored = translation($app, $about);
	assert($stored !== null);
	Assert::same("## Nový obsah\n\nText.", $stored->content);
	Assert::same(2, $stored->version);
	Assert::same('o-nas', $stored->slug);
});


test('an empty slug is derived from the title', function () use ($app): void {
	resetDemo($app);
	signedIn($app);
	$contact = pageId($app, 'Kontakt');
	[$action, $body] = formSubmit($app->get("/admin/pages/$contact"), 'page-translation-form', 'page-save', ['title' => 'Napište nám', 'slug' => '']);

	Assert::same(303, $app->post($action, $body)->code);
	$stored = translation($app, $contact);
	assert($stored !== null);
	Assert::same('Napište nám', $stored->title);
	Assert::same('napiste-nam', $stored->slug);
	Assert::same('napiste-nam', $stored->path);
});


test('a taken or reserved slug is an error at the slug field and nothing is saved', function () use ($app): void {
	resetDemo($app);
	signedIn($app);
	$contact = pageId($app, 'Kontakt');
	$messages = [
		'o-nas' => 'Tuto adresu už používá jiná stránka.',
		'admin' => 'Tento slug je vyhrazený systémem.',
		'Špatně' => 'Adresa smí obsahovat jen malá písmena bez diakritiky, číslice a pomlčky.',
		str_repeat('a', 101) => 'Adresa může mít nejvýš 100 znaků.',
	];
	foreach ($messages as $slug => $message) {
		[$action, $body] = formSubmit($app->get("/admin/pages/$contact"), 'page-translation-form', 'page-save', ['slug' => $slug, 'content' => 'Změna']);
		$result = $app->post($action, $body);
		Assert::same(200, $result->code, $slug);
		Assert::same([$message], errors($result, 'page-translation-form'), $slug);
		$input = nodes($result->dom(), '//*[@data-testid="page-slug-input"]')[0];
		Assert::same('true', (string) $input['aria-invalid'], $slug);
		Assert::same($slug, (string) $input['value']);
		Assert::same('Změna', fieldValue($result, 'page-content-input'));
		Assert::null($result->text('flash'));

		$stored = translation($app, $contact);
		assert($stored !== null);
		Assert::same('kontakt', $stored->slug);
		Assert::same(1, $stored->version);
	}
});


test('a title over the limit and an empty title are refused by the form', function () use ($app): void {
	resetDemo($app);
	signedIn($app);
	$contact = pageId($app, 'Kontakt');
	// A no-break space is trimmed as the service trims it.
	foreach (['' => 'Vyplňte název.', "\u{a0}\u{2003}" => 'Vyplňte název.', str_repeat('a', 201) => 'Text je příliš dlouhý.'] as $title => $message) {
		[$action, $body] = formSubmit($app->get("/admin/pages/$contact"), 'page-translation-form', 'page-save', ['title' => $title]);
		$result = $app->post($action, $body);
		Assert::same(200, $result->code);
		Assert::same([$message], errors($result, 'page-translation-form'));
	}
	[$action, $body] = formSubmit($app->get("/admin/pages/$contact"), 'page-translation-form', 'page-save', ['metaDescription' => str_repeat('a', 301)]);
	Assert::same(['Text je příliš dlouhý.'], errors($app->post($action, $body), 'page-translation-form'));
	Assert::same(1, translation($app, $contact)?->version);
});


test('two browsers save the same translation version: the second is stale and keeps its text', function () use ($app): void {
	resetDemo($app);
	$about = pageId($app, 'O nás');
	signedIn($app, EDITOR);
	[$firstAction, $firstBody] = formSubmit($app->get("/admin/pages/$about"), 'page-translation-form', 'page-save', ['content' => 'První verze']);
	$firstCookies = $app->cookies();

	signedIn($app, ADMIN);
	[$secondAction, $secondBody] = formSubmit($app->get("/admin/pages/$about"), 'page-translation-form', 'page-save', ['content' => 'Druhá verze', 'title' => 'Druhý název']);
	$secondCookies = $app->cookies();

	$app->newBrowser();
	foreach ($firstCookies as $name => $value) {
		$app->setCookie($name, $value);
	}
	Assert::same(303, $app->post($firstAction, $firstBody)->code);

	$app->newBrowser();
	foreach ($secondCookies as $name => $value) {
		$app->setCookie($name, $value);
	}
	$result = $app->post($secondAction, $secondBody);
	Assert::same(200, $result->code);
	Assert::same(['Stránku mezitím upravil někdo jiný. Tvoje úpravy zůstaly ve formuláři – zkopíruj si je, načti aktuální verzi a ulož znovu.'], errors($result, 'page-translation-form'));
	Assert::same('Druhá verze', fieldValue($result, 'page-content-input'));
	Assert::same('Druhý název', fieldValue($result, 'page-title-input'));
	Assert::null($result->text('flash'));

	// The re-rendered form still carries the version it was opened with, so saving it again is stale again.
	[$againAction, $againBody] = formSubmit($result, 'page-translation-form', 'page-save');
	Assert::same($secondBody['version'], $againBody['version']);
	$again = $app->post($againAction, $againBody);
	Assert::same(200, $again->code);
	Assert::same(['Stránku mezitím upravil někdo jiný. Tvoje úpravy zůstaly ve formuláři – zkopíruj si je, načti aktuální verzi a ulož znovu.'], errors($again, 'page-translation-form'));

	$stored = translation($app, $about);
	assert($stored !== null);
	Assert::same('První verze', $stored->content);
	Assert::same('O nás', $stored->title);
	Assert::same(2, $stored->version);
});


test('two browsers save the same page properties version: the second is stale and keeps its values', function () use ($app): void {
	resetDemo($app);
	$contact = pageId($app, 'Kontakt');
	$about = pageId($app, 'O nás');
	signedIn($app, EDITOR);
	[$firstAction, $firstBody] = formSubmit($app->get("/admin/pages/$contact"), 'page-properties-form', 'page-properties-save', ['inMenu' => null]);
	$firstCookies = $app->cookies();

	signedIn($app, ADMIN);
	[$secondAction, $secondBody] = formSubmit($app->get("/admin/pages/$contact"), 'page-properties-form', 'page-properties-save', ['parent' => (string) $about]);
	Assert::same('1', $secondBody['version']);
	$secondCookies = $app->cookies();

	$app->newBrowser();
	foreach ($firstCookies as $name => $value) {
		$app->setCookie($name, $value);
	}
	$first = $app->post($firstAction, $firstBody);
	Assert::same(303, $first->code);
	Assert::same('Stránka byla uložena.', $app->get((string) $first->location)->text('flash'));

	$app->newBrowser();
	foreach ($secondCookies as $name => $value) {
		$app->setCookie($name, $value);
	}
	$result = $app->post($secondAction, $secondBody);
	Assert::same(200, $result->code);
	Assert::same(['Stránku mezitím upravil někdo jiný. Tvoje úpravy zůstaly ve formuláři – zkopíruj si je, načti aktuální verzi a ulož znovu.'], errors($result, 'page-properties-form'));
	Assert::same((string) $about, fieldValue($result, 'page-parent'));
	Assert::same('on', fieldValue($result, 'page-in-menu'));

	[$againAction, $againBody] = formSubmit($result, 'page-properties-form', 'page-properties-save');
	Assert::same('1', $againBody['version']);
	$again = $app->post($againAction, $againBody);
	Assert::same(200, $again->code);
	Assert::same(['Stránku mezitím upravil někdo jiný. Tvoje úpravy zůstaly ve formuláři – zkopíruj si je, načti aktuální verzi a ulož znovu.'], errors($again, 'page-properties-form'));

	$page = $app->container->getByType(PageRepository::class)->getPage($contact);
	assert($page !== null);
	Assert::null($page->parentId);
	Assert::false($page->inMenu);
	Assert::same(2, $page->version);
});


test('the parent dropdown offers the top level and every page except the page itself and its descendants', function () use ($app): void {
	resetDemo($app);
	signedIn($app);
	$about = pageId($app, 'O nás');
	$result = $app->get("/admin/pages/$about");
	$options = parentOptions($result);
	Assert::same('(žádná – nejvyšší úroveň)', $options[''] ?? null);
	// The top level is the prompt here, so the detail dropdown is never required.
	Assert::false(isset(nodes($result->dom(), '//select[@data-testid="page-parent"]')[0]['required']));
	Assert::same(['', (string) pageId($app, 'Úvod'), (string) pageId($app, 'Kontakt'), (string) pageId($app, 'Rozpracováno')], array_map(strval(...), array_keys($options)));

	$team = pageId($app, 'Tým');
	$teamOptions = parentOptions($app->get("/admin/pages/$team"));
	Assert::true(isset($teamOptions[$about]));
	Assert::false(isset($teamOptions[$team]));
	Assert::same((string) $about, fieldValue($app->get("/admin/pages/$team"), 'page-parent'));
});


test('a forged POST with a descendant, the page itself or no page as the parent is refused', function () use ($app): void {
	resetDemo($app);
	signedIn($app);
	$about = pageId($app, 'O nás');
	$team = pageId($app, 'Tým');
	foreach ([$team, $about, 999999, 'abc'] as $parent) {
		[$action, $body] = formSubmit($app->get("/admin/pages/$about"), 'page-properties-form', 'page-properties-save', ['parent' => (string) $parent]);
		$result = $app->post($action, $body);
		Assert::same(200, $result->code, (string) $parent);
		Assert::same([in_array($parent, [$team, $about], true) ? 'Stránku nejde přesunout pod sebe ani pod svou podstránku.' : 'Vybraná nadřazená stránka už neexistuje, vyberte jinou.'], errors($result, 'page-properties-form'), (string) $parent);
		Assert::null($result->text('flash'));
	}

	$page = $app->container->getByType(PageRepository::class)->getPage($about);
	assert($page !== null);
	Assert::null($page->parentId);
	Assert::same(1, $page->version);

	// Tým is under O nás: after a refused parent the dropdown shows O nás again, never the top level.
	foreach ([$team, 999999, 'abc'] as $parent) {
		[$action, $body] = formSubmit($app->get("/admin/pages/$team"), 'page-properties-form', 'page-properties-save', ['parent' => (string) $parent]);
		$result = $app->post($action, $body);
		Assert::same([$parent === $team ? 'Stránku nejde přesunout pod sebe ani pod svou podstránku.' : 'Vybraná nadřazená stránka už neexistuje, vyberte jinou.'], errors($result, 'page-properties-form'), (string) $parent);
		Assert::same((string) $about, selectedParent($result, 'page-properties-form'), (string) $parent);
		$select = nodes($result->dom(), '//select[@data-testid="page-parent"]')[0];
		Assert::same('true', (string) $select['aria-invalid'], (string) $parent);
		Assert::same(errors($result, 'page-properties-form'), [trim((string) nodes($result->dom(), '//*[@id="' . $select['aria-describedby'] . '"]')[0])], (string) $parent);
	}
	Assert::same($about, $app->container->getByType(PageRepository::class)->getPage($team)?->parentId);
});


test('a forged status is refused as an invalid choice and nothing is saved', function () use ($app): void {
	resetDemo($app);
	signedIn($app);
	$about = pageId($app, 'O nás');
	[$action, $body] = formSubmit($app->get("/admin/pages/$about"), 'page-translation-form', 'page-save', ['status' => 'deleted', 'content' => 'Změna']);
	$result = $app->post($action, $body);
	Assert::same(200, $result->code);
	Assert::same(['Vyberte platnou hodnotu.'], errors($result, 'page-translation-form'));
	Assert::same('Změna', fieldValue($result, 'page-content-input'));
	Assert::same(1, translation($app, $about)?->version);
});


test('the two save buttons of the detail say what they save', function () use ($app): void {
	signedIn($app);
	$detail = $app->get('/admin/pages/' . pageId($app, 'O nás'));
	Assert::same('Uložit vlastnosti', $detail->text('page-properties-save'));
	Assert::same('Uložit text', $detail->text('page-save'));
	Assert::same('Uložit', $app->get('/admin/pages/new')->text('page-save'));
});


test('a parent moved under the page or deleted meanwhile is refused and the dropdown shows the stored parent', function () use ($app): void {
	resetDemo($app);
	signedIn($app);
	$about = pageId($app, 'O nás');
	$team = pageId($app, 'Tým');
	$contact = pageId($app, 'Kontakt');
	$draft = pageId($app, 'Rozpracováno');

	[$action, $body] = formSubmit($app->get("/admin/pages/$team"), 'page-properties-form', 'page-properties-save', ['parent' => (string) $contact]);
	// Another editor moves Kontakt under Tým before this form is saved.
	$app->container->getByType(PageService::class)->updatePage(new UpdatePage($contact, $team, true, 1));
	$result = $app->post($action, $body);
	Assert::same(200, $result->code);
	Assert::same(['Stránku nejde přesunout pod sebe ani pod svou podstránku.'], errors($result, 'page-properties-form'));
	Assert::same((string) $about, selectedParent($result, 'page-properties-form'));

	[$action, $body] = formSubmit($app->get("/admin/pages/$team"), 'page-properties-form', 'page-properties-save', ['parent' => (string) $draft]);
	$app->container->getByType(Explorer::class)->query('DELETE FROM page WHERE id = ?', $draft);
	$result = $app->post($action, $body);
	Assert::same(200, $result->code);
	Assert::same(['Vybraná nadřazená stránka už neexistuje, vyberte jinou.'], errors($result, 'page-properties-form'));
	Assert::same((string) $about, selectedParent($result, 'page-properties-form'));
	Assert::same($about, $app->container->getByType(PageRepository::class)->getPage($team)?->parentId);
});


test('saving the properties moves the page under another parent and raises the page version', function () use ($app): void {
	resetDemo($app);
	signedIn($app);
	$contact = pageId($app, 'Kontakt');
	$about = pageId($app, 'O nás');
	[$action, $body] = formSubmit($app->get("/admin/pages/$contact"), 'page-properties-form', 'page-properties-save', ['parent' => (string) $about, 'inMenu' => null]);
	$result = $app->post($action, $body);
	Assert::same(303, $result->code);
	Assert::match("/admin/pages/$contact?_fid=%a%", (string) $result->location);
	Assert::same('Stránka byla uložena.', $app->get((string) $result->location)->text('flash'));

	$page = $app->container->getByType(PageRepository::class)->getPage($contact);
	assert($page !== null);
	Assert::same($about, $page->parentId);
	Assert::false($page->inMenu);
	Assert::same(2, $page->version);
	Assert::same(1, translation($app, $contact)?->version);
});


test('preview renders the Markdown below the form, keeps the form and saves nothing', function () use ($app): void {
	resetDemo($app);
	signedIn($app);
	$about = pageId($app, 'O nás');
	[$action, $body] = formSubmit($app->get("/admin/pages/$about"), 'page-translation-form', 'page-preview', ['content' => "## Náhledový nadpis\n\nOdstavec.", 'title' => '']);

	$result = $app->post($action, $body);
	Assert::same(200, $result->code);
	$output = nodes($result->dom(), '//*[@data-testid="page-preview-output"]');
	Assert::count(1, $output);
	Assert::same('Náhledový nadpis', trim((string) nodes($output[0], './/h2')[0]));
	Assert::contains('Náhled (neuloženo)', $result->body);
	Assert::same("## Náhledový nadpis\n\nOdstavec.", fieldValue($result, 'page-content-input'));
	Assert::same('', fieldValue($result, 'page-title-input'));
	Assert::same([], errors($result, 'page-translation-form'));
	Assert::null($result->text('flash'));
	Assert::null($app->get("/admin/pages/$about")->text('flash'));

	$stored = translation($app, $about);
	assert($stored !== null);
	Assert::same(1, $stored->version);
	Assert::same('O nás', $stored->title);
	Assert::contains('Jsme malý ukázkový web.', $stored->content);
});


test('the preview escapes raw HTML as the public page does', function () use ($app): void {
	resetDemo($app);
	signedIn($app);
	$about = pageId($app, 'O nás');
	[$action, $body] = formSubmit($app->get("/admin/pages/$about"), 'page-translation-form', 'page-preview', ['content' => '<script>alert(1)</script>']);
	$result = $app->post($action, $body);
	Assert::count(0, nodes($result->dom(), '//*[@data-testid="page-preview-output"]//script'));
});


test('a draft page cannot become the homepage', function () use ($app): void {
	resetDemo($app);
	signedIn($app);
	$homepage = $app->container->getByType(SettingRepository::class)->get('homepage');
	$draft = pageId($app, 'Rozpracováno');
	[$action, $body] = formSubmit($app->get("/admin/pages/$draft"), 'page-homepage-form', 'page-set-homepage');

	$result = $app->post($action, $body);
	Assert::same(200, $result->code);
	Assert::same(['Úvodní stránka musí mít publikovaný překlad ve výchozím jazyce.'], errors($result, 'page-homepage-form'));
	Assert::same($homepage, $app->container->getByType(SettingRepository::class)->get('homepage'));
});


test('a published page becomes the homepage', function () use ($app): void {
	resetDemo($app);
	signedIn($app);
	$contact = pageId($app, 'Kontakt');
	[$action, $body] = formSubmit($app->get("/admin/pages/$contact"), 'page-homepage-form', 'page-set-homepage');

	$result = $app->post($action, $body);
	Assert::same(303, $result->code);
	$detail = $app->get((string) $result->location);
	Assert::same('Úvodní stránka byla nastavena.', $detail->text('flash'));
	Assert::same((string) $contact, $app->container->getByType(SettingRepository::class)->get('homepage'));
	Assert::count(0, nodes($detail->dom(), '//*[@data-testid="page-set-homepage"]'));
	// The demo Kontakt has no English translation.
	Assert::same('Úvodní stránka nemá publikovaný překlad v jazyce: en', $detail->text('page-homepage-missing'));
});


test('the homepage shows the languages without a published translation', function () use ($app): void {
	resetDemo($app);
	signedIn($app);
	$home = pageId($app, 'Úvod');
	$result = $app->get("/admin/pages/$home");
	Assert::count(0, nodes($result->dom(), '//*[@data-testid="page-set-homepage"]'));
	Assert::null($result->text('page-homepage-missing'));

	[$action, $body] = formSubmit($app->get("/admin/pages/$home/en"), 'page-translation-form', 'page-save', ['status' => 'draft']);
	Assert::same(303, $app->post($action, $body)->code);
	Assert::same('Úvodní stránka nemá publikovaný překlad v jazyce: en', $app->get("/admin/pages/$home")->text('page-homepage-missing'));
});


test('the default translation of the homepage cannot become a draft', function () use ($app): void {
	resetDemo($app);
	signedIn($app);
	$home = pageId($app, 'Úvod');
	[$action, $body] = formSubmit($app->get("/admin/pages/$home"), 'page-translation-form', 'page-save', ['status' => 'draft']);

	$result = $app->post($action, $body);
	Assert::same(200, $result->code);
	Assert::same(['Úvodní stránku nejde smazat ani skrýt, dokud nevyberete jinou.'], errors($result, 'page-translation-form'));
	Assert::same('draft', fieldValue($result, 'page-status-input'));
	$stored = translation($app, $home);
	assert($stored !== null);
	Assert::same(PublicationStatus::Published, $stored->status);
	Assert::same(1, $stored->version);
});


test('a missing translation is an empty form headed add translation, and saving it creates the translation', function () use ($app): void {
	resetDemo($app);
	signedIn($app);
	$team = pageId($app, 'Tým');
	$result = $app->get("/admin/pages/$team/en");
	Assert::same(200, $result->code);
	Assert::same('Přidat překlad', $result->text('page-translation-heading'));
	Assert::same('', fieldValue($result, 'page-title-input'));
	Assert::same('', fieldValue($result, 'page-content-input'));
	Assert::same('draft', fieldValue($result, 'page-status-input'));
	[$action, $body] = formSubmit($result, 'page-translation-form', 'page-save', ['title' => 'Team', 'content' => 'Our team.']);
	Assert::same('', $body['version']);

	$saved = $app->post($action, $body);
	Assert::same(303, $saved->code);
	Assert::match("/admin/pages/$team/en?_fid=%a%", (string) $saved->location);
	$stored = translation($app, $team, 'en');
	assert($stored !== null);
	Assert::same('team', $stored->slug);
	Assert::same(1, $stored->version);
	Assert::same(PublicationStatus::Draft, $stored->status);

	// The same empty form posted again is stale: the translation exists now.
	$again = $app->post($action, $body);
	Assert::same(200, $again->code);
	Assert::same(['Stránku mezitím upravil někdo jiný. Tvoje úpravy zůstaly ve formuláři – zkopíruj si je, načti aktuální verzi a ulož znovu.'], errors($again, 'page-translation-form'));
});


test('a new page gets its slug from the title and opens in the detail with a message', function () use ($app): void {
	resetDemo($app);
	signedIn($app);
	$about = pageId($app, 'O nás');
	$form = $app->get('/admin/pages/new');
	Assert::same(200, $form->code);
	Assert::same('Nová stránka', $form->text('admin-title'));
	$options = parentOptions($form);
	Assert::same('(žádná – nejvyšší úroveň)', $options[0] ?? null);
	// The placeholder, the top level and the five demo pages; the top level is chosen by default.
	Assert::count(7, $options);
	Assert::same('0', selectedParent($form, 'page-new-form'));
	[$action, $body] = formSubmit($form, 'page-new-form', 'page-save', ['parent' => (string) $about, 'title' => 'Naše historie', 'content' => 'Text', 'status' => 'published']);

	$result = $app->post($action, $body);
	Assert::same(303, $result->code);
	$id = pageId($app, 'Naše historie');
	Assert::match("/admin/pages/$id?_fid=%a%", (string) $result->location);
	$detail = $app->get((string) $result->location);
	Assert::same('Stránka byla vytvořena.', $detail->text('flash'));
	Assert::same('nase-historie', fieldValue($detail, 'page-slug-input'));

	$page = $app->container->getByType(PageRepository::class)->getPage($id);
	assert($page !== null);
	Assert::same($about, $page->parentId);
	Assert::true($page->inMenu);
	Assert::same(PublicationStatus::Published, translation($app, $id)?->status);
});


test('a new page with a taken slug keeps the form and creates nothing', function () use ($app): void {
	resetDemo($app);
	signedIn($app);
	$count = $app->container->getByType(Explorer::class)->table('page')->count('*');
	[$action, $body] = formSubmit($app->get('/admin/pages/new'), 'page-new-form', 'page-save', ['title' => 'Kontakt', 'content' => 'Můj text']);

	$result = $app->post($action, $body);
	Assert::same(200, $result->code);
	Assert::same(['Tuto adresu už používá jiná stránka.'], errors($result, 'page-new-form'));
	Assert::same('Můj text', fieldValue($result, 'page-content-input'));
	Assert::same($count, $app->container->getByType(Explorer::class)->table('page')->count('*'));
});


test('a new page with a parent that is not a page is refused and the parent has to be chosen again', function () use ($app): void {
	resetDemo($app);
	signedIn($app);
	$draft = pageId($app, 'Rozpracováno');
	$explorer = $app->container->getByType(Explorer::class);
	foreach ([999999, 'abc', $draft] as $parent) {
		[$action, $body] = formSubmit($app->get('/admin/pages/new'), 'page-new-form', 'page-save', ['parent' => (string) $parent, 'title' => 'Nová', 'content' => 'Můj text']);
		if ($parent === $draft) {
			$explorer->query('DELETE FROM page WHERE id = ?', $draft);
		}
		$count = $explorer->table('page')->count('*');
		$result = $app->post($action, $body);
		Assert::same(200, $result->code, (string) $parent);
		Assert::same(['Vybraná nadřazená stránka už neexistuje, vyberte jinou.'], errors($result, 'page-new-form'), (string) $parent);
		$select = nodes($result->dom(), '//form[@data-testid="page-new-form"]//select[@data-testid="page-parent"]')[0];
		Assert::true(isset($select['required']), (string) $parent);
		// Nothing is selected, so the browser shows the placeholder, which a required select refuses.
		Assert::null(selectedParent($result, 'page-new-form'), (string) $parent);
		$first = nodes($select, './option')[0];
		Assert::same('', (string) $first['value']);
		Assert::same('– Nadřazená stránka –', trim((string) $first));
		Assert::same('true', (string) $select['aria-invalid']);
		Assert::same('Vybraná nadřazená stránka už neexistuje, vyberte jinou.', trim((string) nodes($result->dom(), '//*[@id="' . $select['aria-describedby'] . '"]')[0]));
		Assert::same('Můj text', fieldValue($result, 'page-content-input'));
		Assert::same($count, $explorer->table('page')->count('*'));
	}

	// The placeholder itself is refused too.
	[$action, $body] = formSubmit($app->get('/admin/pages/new'), 'page-new-form', 'page-save', ['parent' => '', 'title' => 'Nová']);
	Assert::same(['Vyberte platnou hodnotu.'], errors($app->post($action, $body), 'page-new-form'));
});


test('a save after the sign-in expired leads to sign-in and then shows the submitted text unsaved', function () use ($app): void {
	resetDemo($app);
	signedIn($app);
	$about = pageId($app, 'O nás');
	[$action, $body] = formSubmit($app->get("/admin/pages/$about"), 'page-translation-form', 'page-save', ['content' => 'Rozepsaný text']);

	expireSignIn($app);
	$stored = $app->post($action, $body);
	Assert::same(303, $stored->code);
	Assert::match('/admin/sign-in?backlink=%a%', (string) $stored->location);
	Assert::same('Byli jste odhlášeni kvůli nečinnosti.', $app->get((string) $stored->location)->text('flash'));

	$restored = AdminBrowser::signIn($app, EDITOR, page: (string) $stored->location);
	Assert::same(200, $restored->code);
	Assert::same('Rozepsaný text', fieldValue($restored, 'page-content-input'));
	Assert::null($restored->text('flash'));

	$translation = translation($app, $about);
	assert($translation !== null);
	Assert::same(1, $translation->version);
	Assert::contains('Jsme malý ukázkový web.', $translation->content);

	// Saving the restored form works.
	[$action, $body] = formSubmit($restored, 'page-translation-form', 'page-save');
	Assert::same(303, $app->post($action, $body)->code);
	Assert::same('Rozepsaný text', translation($app, $about)?->content);
});


test('a missing page, a language that is not configured and a cross-site save are refused', function () use ($app): void {
	resetDemo($app);
	signedIn($app);
	$about = pageId($app, 'O nás');
	Assert::same(404, $app->get('/admin/pages/999999')->code);
	Assert::same(404, $app->get('/admin/pages/999999/en')->code);
	Assert::same(404, $app->get("/admin/pages/$about/de")->code);
	Assert::same(404, $app->get("/admin/pages/$about/xx-yy")->code);

	[$action, $body] = formSubmit($app->get("/admin/pages/$about"), 'page-translation-form', 'page-save', ['content' => 'Cizí']);
	Assert::same(303, $app->post($action, $body, ['Sec-Fetch-Site' => 'cross-site'])->code);
	Assert::same(1, translation($app, $about)?->version);
});


test('a restored properties save and a restored new page show the sent values and save nothing', function () use ($app): void {
	resetDemo($app);
	signedIn($app);
	$contact = pageId($app, 'Kontakt');
	$about = pageId($app, 'O nás');
	$explorer = $app->container->getByType(Explorer::class);

	[$action, $body] = formSubmit($app->get("/admin/pages/$contact"), 'page-properties-form', 'page-properties-save', ['parent' => (string) $about, 'inMenu' => null]);
	expireSignIn($app);
	$stored = $app->post($action, $body);
	Assert::match('/admin/sign-in?backlink=%a%', (string) $stored->location);
	$restored = AdminBrowser::signIn($app, EDITOR, page: (string) $stored->location);
	Assert::same(200, $restored->code);
	Assert::same((string) $about, selectedParent($restored, 'page-properties-form'));
	Assert::same('', fieldValue($restored, 'page-in-menu'));
	Assert::null($restored->text('flash'));
	$page = $app->container->getByType(PageRepository::class)->getPage($contact);
	assert($page !== null);
	Assert::null($page->parentId);
	Assert::true($page->inMenu);
	Assert::same(1, $page->version);

	$count = $explorer->table('page')->count('*');
	[$action, $body] = formSubmit($app->get('/admin/pages/new'), 'page-new-form', 'page-save', ['title' => 'Rozepsaná', 'content' => 'Rozepsaný text']);
	expireSignIn($app);
	$stored = $app->post($action, $body);
	Assert::match('/admin/sign-in?backlink=%a%', (string) $stored->location);
	$restored = AdminBrowser::signIn($app, EDITOR, page: (string) $stored->location);
	Assert::same(200, $restored->code);
	Assert::same('Rozepsaná', fieldValue($restored, 'page-title-input'));
	Assert::same('Rozepsaný text', fieldValue($restored, 'page-content-input'));
	Assert::null($restored->text('flash'));
	Assert::same($count, $explorer->table('page')->count('*'));
});


test('a properties version that is empty, zero or not a number is stale', function () use ($app): void {
	resetDemo($app);
	signedIn($app);
	$contact = pageId($app, 'Kontakt');
	foreach (['', '0', 'abc'] as $version) {
		[$action, $body] = formSubmit($app->get("/admin/pages/$contact"), 'page-properties-form', 'page-properties-save', ['version' => $version, 'inMenu' => null]);
		$result = $app->post($action, $body);
		Assert::same(200, $result->code, $version);
		Assert::same(['Stránku mezitím upravil někdo jiný. Tvoje úpravy zůstaly ve formuláři – zkopíruj si je, načti aktuální verzi a ulož znovu.'], errors($result, 'page-properties-form'), $version);
	}
	Assert::same(1, pageVersion($app, $contact));
});


test('a page deleted between rendering and saving is 404 for every form', function () use ($app): void {
	resetDemo($app);
	signedIn($app);
	$contact = pageId($app, 'Kontakt');
	$detail = $app->get("/admin/pages/$contact");
	$submissions = [
		formSubmit($detail, 'page-properties-form', 'page-properties-save'),
		formSubmit($detail, 'page-translation-form', 'page-save'),
		formSubmit($detail, 'page-homepage-form', 'page-set-homepage'),
	];
	$app->container->getByType(Explorer::class)->query('DELETE FROM page WHERE id = ?', $contact);
	foreach ($submissions as [$action, $body]) {
		Assert::same(404, $app->post($action, $body)->code, $action);
	}
});


test('a form submitted to another screen is 404', function () use ($app): void {
	resetDemo($app);
	signedIn($app);
	$about = pageId($app, 'O nás');
	$posts = [
		'/admin/pages?do=homepage-submit' => ['set' => ''],
		'/admin/pages?do=properties-submit' => ['save' => '', 'version' => '1'],
		'/admin/pages?do=translation-submit' => ['save' => '', 'title' => 'X'],
		'/admin/pages/new?do=translation-submit' => ['save' => '', 'title' => 'X'],
		'/admin/pages/new?do=homepage-submit' => ['set' => ''],
		"/admin/pages/$about?do=newPage-submit" => ['save' => '', 'title' => 'X', 'parent' => '0'],
		'/admin/pages?do=newPage-submit' => ['save' => '', 'title' => 'X', 'parent' => '0'],
	];
	foreach ($posts as $path => $body) {
		Assert::same(404, $app->post($path, $body)->code, $path);
	}
	Assert::same(1, translation($app, $about)?->version);
});


test('the language tabs, field errors and the preview heading are labelled for assistive technology', function () use ($app): void {
	resetDemo($app);
	signedIn($app);
	$contact = pageId($app, 'Kontakt');
	$detail = $app->get("/admin/pages/$contact");
	$nav = nodes($detail->dom(), '//nav[.//*[@data-testid="page-tab"]]');
	Assert::count(1, $nav);
	$labelledBy = (string) $nav[0]['aria-labelledby'];
	Assert::notSame('', $labelledBy);
	Assert::count(1, nodes($detail->dom(), '//*[@id="' . $labelledBy . '"]'));

	// The title is checked by the form, the slug by the service after the form passed, so each needs its own save.
	foreach (['page-slug-input' => ['slug', 'o-nas', 'Tuto adresu už používá jiná stránka.'], 'page-title-input' => ['title', '', 'Vyplňte název.']] as $testId => [$field, $value, $message]) {
		[$action, $body] = formSubmit($detail, 'page-translation-form', 'page-save', [$field => $value]);
		$result = $app->post($action, $body);
		$input = nodes($result->dom(), '//*[@data-testid="' . $testId . '"]')[0];
		$texts = [];
		foreach (explode(' ', (string) $input['aria-describedby']) as $id) {
			foreach (nodes($result->dom(), '//*[@id="' . $id . '"]') as $described) {
				$texts[] = trim((string) $described);
			}
		}
		Assert::contains($message, $texts, $testId);
	}

	[$action, $body] = formSubmit($detail, 'page-translation-form', 'page-preview', ['content' => '## Nadpis']);
	$preview = $app->post($action, $body);
	Assert::count(1, nodes($preview->dom(), '//h3[normalize-space()="Náhled (neuloženo)"]'));
});


test('without sign-in the detail and the new page redirect to sign-in', function () use ($app): void {
	$app->newBrowser();
	foreach (['/admin/pages/1', '/admin/pages/new'] as $path) {
		$result = $app->get($path);
		Assert::same(302, $result->code, $path);
		Assert::match('/admin/sign-in?backlink=%a%', (string) $result->location);
	}
});


test('with one language there are no language tabs', function (): void {
	$app = HttpApplication::boot();
	TestDatabase::loadDummyData($app->container);
	AdminBrowser::seed($app->container, EDITOR, 'Eva Editorová', Role::Editor);
	signedIn($app);
	$result = $app->get('/admin/pages/' . pageId($app, 'O nás'));
	Assert::same(200, $result->code);
	Assert::count(0, nodes($result->dom(), '//*[@data-testid="page-tab"]'));
	Assert::same(404, $app->get('/admin/pages/' . pageId($app, 'O nás') . '/en')->code);
});
