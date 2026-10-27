<?php

declare(strict_types=1);

use App\Model\News\CreateNews;
use App\Model\News\NewsRepository;
use App\Model\News\NewsService;
use App\Model\News\NewsTranslation;
use App\Model\PublicationStatus;
use App\Model\User\Role;
use Nette\Database\Explorer;
use Tester\Assert;
use Tests\Presentation\Admin\AdminBrowser;
use Tests\Support\HttpApplication;
use Tests\Support\HttpResult;
use Tests\Support\TestDatabase;

require __DIR__ . '/../../bootstrap.php';

const EDITOR = 'news-edit-editor@example.com';
const AUTHOR = 'news-edit-author@example.com';

/** The new item form has no test id of its own; it is the form with the title field. */
const NEW_FORM = '//form[.//*[@data-testid="news-title-input"]]';
const PROPERTIES_FORM = '//form[@data-testid="news-properties-form"]';
const TRANSLATION_FORM = '//form[@data-testid="news-translation-form"]';
const DELETE_FORM = '//form[.//*[@data-testid="news-delete-confirm"]]';

const STALE = 'Novinku mezitím upravil někdo jiný. Tvoje úpravy zůstaly ve formuláři – zkopíruj si je, načti aktuální verzi a ulož znovu.';
const DELETED_MEANWHILE = 'Novinka mezitím byla smazána. Text zůstal ve formuláři – zkopírujte si ho.';
const INVALID_DATE = 'Zadejte platné datum a čas.';


/** @return array{int, int} ids of the signed-in editor and of another author */
function resetNews(HttpApplication $app): array
{
	TestDatabase::resetDummyData($app->container);

	return [
		AdminBrowser::seed($app->container, EDITOR, 'Eva Editorová', Role::Editor),
		AdminBrowser::seed($app->container, AUTHOR, 'Petr Pisálek', Role::Editor),
	];
}


/** @return list<SimpleXMLElement> */
function nodes(SimpleXMLElement $context, string $xpath): array
{
	$found = $context->xpath($xpath);

	return is_array($found) ? array_values($found) : [];
}


function utc(string $at): DateTimeImmutable
{
	return new DateTimeImmutable($at, new DateTimeZone('UTC'));
}


/** A news item with a Czech translation; the dates are far from now, so the states do not depend on the clock. */
function createNews(HttpApplication $app, int $author, string $title, ?string $at = '2000-01-01 10:00:00', PublicationStatus $status = PublicationStatus::Published): int
{
	return $app->container->getByType(NewsService::class)->createNews(new CreateNews(
		$at === null ? null : utc($at),
		$author,
		'cs',
		$title,
		'',
		'Perex ' . $title,
		'Obsah ' . $title,
		'',
		$status,
	))->newsId;
}


function newsTranslation(HttpApplication $app, int $newsId, string $locale = 'cs'): ?NewsTranslation
{
	return $app->container->getByType(NewsRepository::class)->findTranslation($newsId, $locale);
}


/** Stored publication date in UTC, or null. */
function storedAt(HttpApplication $app, int $newsId): ?string
{
	$news = $app->container->getByType(NewsRepository::class)->getNews($newsId);
	assert($news !== null);

	return $news->publishedAt?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i');
}


function newsVersion(HttpApplication $app, int $newsId): int
{
	$news = $app->container->getByType(NewsRepository::class)->getNews($newsId);
	assert($news !== null);

	return $news->version;
}


function signedIn(HttpApplication $app): void
{
	$app->newBrowser();
	AdminBrowser::signIn($app, EDITOR);
}


/**
 * The form found by the XPath and the body a browser would send by clicking the button, then the overrides (null drops a field).
 *
 * @param array<string, ?string> $overrides
 * @return array{string, array<string, string>} action and body
 */
function formSubmit(HttpResult $result, string $formXpath, string $buttonTestId, array $overrides = []): array
{
	$forms = nodes($result->dom(), $formXpath);
	Assert::count(1, $forms, $formXpath);
	$form = $forms[0];
	Assert::same('post', strtolower((string) $form['method']));

	$body = [];
	foreach (nodes($form, './/input[@name]') as $input) {
		$type = strtolower((string) $input['type']);
		if ($type === 'submit' || ($type === 'checkbox' && !isset($input['checked']))) {
			continue;
		}
		$body[(string) $input['name']] = (string) $input['value'];
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

	return (string) $element['value'];
}


/** @return list<string> */
function errors(HttpResult $result, string $formXpath): array
{
	return array_map(
		static fn (SimpleXMLElement $item): string => trim((string) $item),
		nodes($result->dom(), $formXpath . '//*[@data-testid="form-error"]/li'),
	);
}


/** @return list<string> texts the field points to with aria-describedby */
function describedBy(HttpResult $result, string $testId): array
{
	$input = nodes($result->dom(), '//*[@data-testid="' . $testId . '"]')[0];
	$texts = [];
	foreach (explode(' ', (string) $input['aria-describedby']) as $id) {
		foreach (nodes($result->dom(), '//*[@id="' . $id . '"]') as $described) {
			$texts[] = trim((string) $described);
		}
	}

	return $texts;
}


/** @return list<string> */
function flashes(HttpResult $result): array
{
	return array_map(
		static fn (SimpleXMLElement $item): string => trim((string) preg_replace('~\s+~u', ' ', dom_import_simplexml($item)->textContent)),
		nodes($result->dom(), '//*[@data-testid="flash"]'),
	);
}


function newsIdByTitle(HttpApplication $app, string $title): int
{
	$id = $app->container->getByType(Explorer::class)->table('news_translation')->where('title', $title)->fetch()?->news_id;
	assert(is_int($id), "news '$title'");

	return $id;
}


function newsCount(HttpApplication $app): int
{
	return $app->container->getByType(Explorer::class)->table('news')->count('*');
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


/**
 * Posts the form after the sign-in expired and signs in again; returns the restored screen.
 *
 * @param array<string, string> $body
 */
function restoredAfterSignIn(HttpApplication $app, string $action, array $body): HttpResult
{
	expireSignIn($app);
	$stored = $app->post($action, $body);
	Assert::same(303, $stored->code);
	Assert::match('/admin/sign-in?backlink=%a%', (string) $stored->location);
	$restored = AdminBrowser::signIn($app, EDITOR, page: (string) $stored->location);
	Assert::same(200, $restored->code);
	Assert::null($restored->text('flash'));

	return $restored;
}


// Two languages, as with the demo configuration; the tests default to one.
$app = HttpApplication::boot([['cms' => ['locales' => ['cs', 'en']]]]);


test('the list links every title to the detail', function () use ($app): void {
	[$editor] = resetNews($app);
	$id = createNews($app, $editor, 'Odkazovaná novinka');
	signedIn($app);
	$links = nodes($app->get('/admin/news')->dom(), '//a[@data-testid="admin-news-item-title"][normalize-space()="Odkazovaná novinka"]');
	Assert::count(1, $links);
	Assert::same("/admin/news/$id", (string) $links[0]['href']);
});


test('a new item with an empty slug and date gets the slug from the title, the signed-in author and no date', function () use ($app): void {
	[$editor] = resetNews($app);
	signedIn($app);
	$form = $app->get('/admin/news/new');
	Assert::same(200, $form->code);
	Assert::same('Nová novinka', $form->text('admin-title'));
	Assert::same('', fieldValue($form, 'news-published-at'));
	Assert::same('datetime-local', (string) nodes($form->dom(), '//*[@data-testid="news-published-at"]')[0]['type']);
	Assert::contains('Prázdné = okamžik zveřejnění; u už zveřejněné novinky okamžik uložení. Čas je v pásmu Europe/Prague.', $form->body);
	Assert::same('draft', fieldValue($form, 'news-status-input'));

	[$action, $body] = formSubmit($form, NEW_FORM, 'news-save', [
		'title' => 'Letní slavnost',
		'slug' => '',
		'publishedAt' => '',
		'perex' => 'Zveme vás na slavnost.',
		'content' => '## Program',
	]);
	$result = $app->post($action, $body);
	Assert::same(303, $result->code);
	$id = newsIdByTitle($app, 'Letní slavnost');
	Assert::match("/admin/news/$id?_fid=%a%", (string) $result->location);

	$detail = $app->get((string) $result->location);
	Assert::same(['Novinka byla vytvořena.'], flashes($detail));
	Assert::same('Letní slavnost', $detail->text('admin-title'));
	Assert::same('Eva Editorová', $detail->text('admin-news-author'));

	$translation = newsTranslation($app, $id);
	Assert::same('letni-slavnost', $translation?->slug);
	Assert::same('Zveme vás na slavnost.', $translation?->perex);
	Assert::same(PublicationStatus::Draft, $translation?->status);
	Assert::null(storedAt($app, $id));
	$authorId = $app->container->getByType(NewsRepository::class)->getNews($id)?->authorId;
	Assert::same($editor, $authorId);
});


test('a time entered in the site zone is stored in UTC and shown again as entered', function () use ($app): void {
	[$editor] = resetNews($app);
	signedIn($app);
	[$action, $body] = formSubmit($app->get('/admin/news/new'), NEW_FORM, 'news-save', [
		'title' => 'Podzimní novinka',
		'publishedAt' => '2026-11-01T08:00',
		'perex' => 'Perex.',
	]);
	$result = $app->post($action, $body);
	Assert::same(303, $result->code);
	$id = newsIdByTitle($app, 'Podzimní novinka');
	Assert::same('2026-11-01 07:00', storedAt($app, $id));
	$detail = $app->get((string) $result->location);
	Assert::same(['Novinka byla vytvořena.'], flashes($detail));
	Assert::same('2026-11-01T08:00', fieldValue($detail, 'news-published-at'));

	// The properties form: summer time is two hours ahead.
	[$action, $body] = formSubmit($detail, PROPERTIES_FORM, 'news-properties-save', ['publishedAt' => '2027-07-01T10:00']);
	$saved = $app->post($action, $body);
	Assert::same(303, $saved->code);
	Assert::same('2027-07-01 08:00', storedAt($app, $id));
	$after = $app->get((string) $saved->location);
	Assert::same(['Novinka byla uložena.'], flashes($after));
	Assert::same('2027-07-01T10:00', fieldValue($after, 'news-published-at'));
	Assert::same(2, newsVersion($app, $id));

	// The second occurrence of the doubled hour; no shift to report.
	[$action, $body] = formSubmit($after, PROPERTIES_FORM, 'news-properties-save', ['publishedAt' => '2026-10-25T02:30']);
	$ambiguous = $app->post($action, $body);
	Assert::same('2026-10-25 01:30', storedAt($app, $id));
	Assert::same(['Novinka byla uložena.'], flashes($app->get((string) $ambiguous->location)));
	Assert::true($editor > 0);
});


test('a local time skipped by the change to summer time is saved shifted and reported', function () use ($app): void {
	[$editor] = resetNews($app);
	$id = createNews($app, $editor, 'Jarní novinka');
	signedIn($app);
	[$action, $body] = formSubmit($app->get("/admin/news/$id"), PROPERTIES_FORM, 'news-properties-save', ['publishedAt' => '2026-03-29T02:30']);
	$result = $app->post($action, $body);
	Assert::same(303, $result->code);
	Assert::same('2026-03-29 01:30', storedAt($app, $id));
	$detail = $app->get((string) $result->location);
	Assert::same([
		'Novinka byla uložena.',
		'Čas 02:30 v tento den neexistuje (přechod na letní čas), uloženo 03:30.',
	], flashes($detail));
	Assert::same('2026-03-29T03:30', fieldValue($detail, 'news-published-at'));

	// A new item reports the shift too.
	[$action, $body] = formSubmit($app->get('/admin/news/new'), NEW_FORM, 'news-save', ['title' => 'Posunutá', 'publishedAt' => '2026-03-29T02:15', 'perex' => 'Perex.']);
	$created = $app->get((string) $app->post($action, $body)->location);
	Assert::same([
		'Novinka byla vytvořena.',
		'Čas 02:15 v tento den neexistuje (přechod na letní čas), uloženo 03:15.',
	], flashes($created));
});


test('an invalid date is an error at the field and nothing is saved', function () use ($app): void {
	[$editor] = resetNews($app);
	$id = createNews($app, $editor, 'Datovaná novinka');
	signedIn($app);
	foreach (['2026-02-30T10:00', 'abc', '2026-13-01T10:00', '0000-01-01T10:00', '99999-01-01T10:00', 'tomorrow', '10:00', '2026-11-01T08:00+05:00'] as $value) {
		[$action, $body] = formSubmit($app->get("/admin/news/$id"), PROPERTIES_FORM, 'news-properties-save', ['publishedAt' => $value]);
		$result = $app->post($action, $body);
		Assert::same(200, $result->code, $value);
		Assert::same([INVALID_DATE], errors($result, PROPERTIES_FORM), $value);
		Assert::contains(INVALID_DATE, describedBy($result, 'news-published-at'), $value);
		// Never a date the user did not type: the control would show its loose parse (`tomorrow`, `99999-…` as 2009).
		$shown = fieldValue($result, 'news-published-at');
		Assert::true($shown === '' || $shown === $value, "$value shown as $shown");
		if (in_array($value, ['99999-01-01T10:00', 'tomorrow', '10:00', '2026-11-01T08:00+05:00'], true)) {
			Assert::same('', $shown, $value);
		}
		Assert::same([], flashes($result), $value);
		Assert::same('2000-01-01 10:00', storedAt($app, $id), $value);
		Assert::same(1, newsVersion($app, $id), $value);
	}

	$count = newsCount($app);
	[$action, $body] = formSubmit($app->get('/admin/news/new'), NEW_FORM, 'news-save', ['title' => 'Neplatná', 'publishedAt' => '2026-02-30T10:00', 'perex' => 'Perex.', 'content' => 'Můj text']);
	$result = $app->post($action, $body);
	Assert::same(200, $result->code);
	Assert::same([INVALID_DATE], errors($result, NEW_FORM));
	Assert::same('Můj text', fieldValue($result, 'news-content-input'));
	Assert::same($count, newsCount($app));

	foreach (['tomorrow', '99999-01-01T10:00'] as $value) {
		[$action, $body] = formSubmit($app->get('/admin/news/new'), NEW_FORM, 'news-save', ['title' => 'Neplatná', 'publishedAt' => $value, 'perex' => 'Perex.']);
		$result = $app->post($action, $body);
		Assert::same([INVALID_DATE], errors($result, NEW_FORM), $value);
		Assert::same('', fieldValue($result, 'news-published-at'), $value);
	}
	Assert::same($count, newsCount($app));
});


test('the detail shows the properties, the author, the tabs and the default-language translation', function () use ($app): void {
	[, $author] = resetNews($app);
	$id = createNews($app, $author, 'Detail novinky', '2000-01-01 10:00:00');
	signedIn($app);
	$result = $app->get("/admin/news/$id");
	Assert::same(200, $result->code);
	Assert::same('Detail novinky', $result->text('admin-title'));
	Assert::same('2000-01-01T11:00', fieldValue($result, 'news-published-at'));
	Assert::same('Petr Pisálek', $result->text('admin-news-author'));
	Assert::same('Detail novinky', fieldValue($result, 'news-title-input'));
	Assert::same('detail-novinky', fieldValue($result, 'news-slug-input'));
	Assert::same('Perex Detail novinky', fieldValue($result, 'news-perex-input'));
	Assert::same('Obsah Detail novinky', fieldValue($result, 'news-content-input'));
	Assert::same('', fieldValue($result, 'news-meta-input'));
	Assert::same('published', fieldValue($result, 'news-status-input'));
	Assert::null($result->text('news-scheduled-note'));
	Assert::null($result->text('news-preview-output'));
	Assert::same('Uložit vlastnosti', $result->text('news-properties-save'));
	Assert::same('Uložit text', $result->text('news-save'));

	$tabs = nodes($result->dom(), '//*[@data-testid="news-tab"]');
	Assert::count(2, $tabs);
	Assert::same("/admin/news/$id", (string) $tabs[0]['href']);
	Assert::same('page', (string) $tabs[0]['aria-current']);
	Assert::same("/admin/news/$id/en", (string) $tabs[1]['href']);

	$en = $app->get("/admin/news/$id/en");
	Assert::same(200, $en->code);
	Assert::count(1, nodes($en->dom(), '//h2[normalize-space()="Přidat překlad"]'));
	Assert::same('', fieldValue($en, 'news-title-input'));
	Assert::same(200, $app->get("/admin/news/$id/cs")->code);
	Assert::same(404, $app->get("/admin/news/$id/de")->code);
	Assert::same(404, $app->get('/admin/news/999999')->code);

	// The author's account is deleted.
	$app->container->getByType(Explorer::class)->query('DELETE FROM user WHERE id = ?', $author);
	Assert::same('(smazaný uživatel)', $app->get("/admin/news/$id")->text('admin-news-author'));
});


test('a scheduled item has a note with the date it appears', function () use ($app): void {
	[$editor] = resetNews($app);
	$id = createNews($app, $editor, 'Budoucí novinka', '2099-01-01 09:00:00');
	$draft = createNews($app, $editor, 'Budoucí koncept', '2099-01-01 09:00:00', PublicationStatus::Draft);
	signedIn($app);
	Assert::same('Na webu se zobrazí od 1. 1. 2099 10:00.', $app->get("/admin/news/$id")->text('news-scheduled-note'));
	Assert::null($app->get("/admin/news/$draft")->text('news-scheduled-note'));
	Assert::null($app->get("/admin/news/$id/en")->text('news-scheduled-note'));
});


test('saving a translation saves it and a missing translation is created', function () use ($app): void {
	[$editor] = resetNews($app);
	$id = createNews($app, $editor, 'Uložená novinka');
	signedIn($app);
	[$action, $body] = formSubmit($app->get("/admin/news/$id"), TRANSLATION_FORM, 'news-save', ['content' => 'Nový obsah', 'perex' => 'Nový perex']);
	$result = $app->post($action, $body);
	Assert::same(303, $result->code);
	Assert::same(['Novinka byla uložena.'], flashes($app->get((string) $result->location)));
	Assert::same('Nový obsah', newsTranslation($app, $id)?->content);
	Assert::same('Nový perex', newsTranslation($app, $id)?->perex);
	Assert::same(2, newsTranslation($app, $id)?->version);

	[$action, $body] = formSubmit($app->get("/admin/news/$id/en"), TRANSLATION_FORM, 'news-save', ['title' => 'Saved item', 'perex' => 'English perex', 'status' => 'published']);
	$result = $app->post($action, $body);
	Assert::same(303, $result->code);
	Assert::match("/admin/news/$id/en?_fid=%a%", (string) $result->location);
	Assert::same('saved-item', newsTranslation($app, $id, 'en')?->slug);
	Assert::same(PublicationStatus::Published, newsTranslation($app, $id, 'en')?->status);
});


test('an empty perex, a too long one and a taken slug are errors at their fields; nothing is saved', function () use ($app): void {
	[$editor] = resetNews($app);
	$id = createNews($app, $editor, 'Chybová novinka');
	createNews($app, $editor, 'Obsazená adresa');
	signedIn($app);
	foreach (['' => 'Vyplňte perex.', '   ' => 'Vyplňte perex.', str_repeat('a', 301) => 'Text je příliš dlouhý.'] as $perex => $message) {
		[$action, $body] = formSubmit($app->get("/admin/news/$id"), TRANSLATION_FORM, 'news-save', ['perex' => $perex, 'content' => 'Změna']);
		$result = $app->post($action, $body);
		Assert::same(200, $result->code);
		Assert::same([$message], errors($result, TRANSLATION_FORM));
		Assert::contains($message, describedBy($result, 'news-perex-input'));
		Assert::same('Změna', fieldValue($result, 'news-content-input'));
	}

	[$action, $body] = formSubmit($app->get("/admin/news/$id"), TRANSLATION_FORM, 'news-save', ['slug' => 'obsazena-adresa', 'content' => 'Změna']);
	$result = $app->post($action, $body);
	Assert::same(200, $result->code);
	Assert::same(['Tuto adresu už používá jiná novinka.'], errors($result, TRANSLATION_FORM));
	Assert::contains('Tuto adresu už používá jiná novinka.', describedBy($result, 'news-slug-input'));
	Assert::same('true', (string) nodes($result->dom(), '//*[@data-testid="news-slug-input"]')[0]['aria-invalid']);

	[$action, $body] = formSubmit($app->get('/admin/news/new'), NEW_FORM, 'news-save', ['title' => 'Bez perexu', 'perex' => '']);
	$count = newsCount($app);
	Assert::same(['Vyplňte perex.'], errors($app->post($action, $body), NEW_FORM));
	[$action, $body] = formSubmit($app->get('/admin/news/new'), NEW_FORM, 'news-save', ['title' => 'Obsazená adresa', 'perex' => 'Perex.', 'content' => 'Můj text']);
	$taken = $app->post($action, $body);
	Assert::same(['Tuto adresu už používá jiná novinka.'], errors($taken, NEW_FORM));
	Assert::same('Můj text', fieldValue($taken, 'news-content-input'));
	Assert::same($count, newsCount($app));

	Assert::same(1, newsTranslation($app, $id)?->version);
	Assert::same('Perex Chybová novinka', newsTranslation($app, $id)?->perex);
});


test('a stale properties or translation save keeps the values with a message', function () use ($app): void {
	[$editor] = resetNews($app);
	$id = createNews($app, $editor, 'Souběžná novinka');
	signedIn($app);
	$detail = $app->get("/admin/news/$id");
	[$propertiesAction, $propertiesBody] = formSubmit($detail, PROPERTIES_FORM, 'news-properties-save', ['publishedAt' => '2001-05-05T12:00']);
	[$translationAction, $translationBody] = formSubmit($detail, TRANSLATION_FORM, 'news-save', ['content' => 'Druhá verze']);
	Assert::same(303, $app->post($propertiesAction, $propertiesBody + [])->code);
	Assert::same(303, $app->post($translationAction, ['content' => 'První verze'] + $translationBody)->code);

	$properties = $app->post($propertiesAction, ['publishedAt' => '2002-06-06T12:00'] + $propertiesBody);
	Assert::same(200, $properties->code);
	Assert::same([STALE], errors($properties, PROPERTIES_FORM));
	Assert::same('2002-06-06T12:00', fieldValue($properties, 'news-published-at'));
	Assert::same('2001-05-05 10:00', storedAt($app, $id));

	$translation = $app->post($translationAction, $translationBody);
	Assert::same(200, $translation->code);
	Assert::same([STALE], errors($translation, TRANSLATION_FORM));
	Assert::same('Druhá verze', fieldValue($translation, 'news-content-input'));
	Assert::same('První verze', newsTranslation($app, $id)?->content);

	foreach (['', '0', 'abc'] as $version) {
		$result = $app->post($propertiesAction, ['version' => $version] + $propertiesBody);
		Assert::same([STALE], errors($result, PROPERTIES_FORM), $version);
	}
	Assert::same(2, newsVersion($app, $id));
});


test('a save of an item deleted meanwhile keeps the values with a message; GET is 404', function () use ($app): void {
	[$editor] = resetNews($app);
	$id = createNews($app, $editor, 'Mizející novinka');
	signedIn($app);
	$detail = $app->get("/admin/news/$id");
	$submissions = [
		PROPERTIES_FORM => formSubmit($detail, PROPERTIES_FORM, 'news-properties-save', ['publishedAt' => '2003-03-03T12:00']),
		TRANSLATION_FORM => formSubmit($detail, TRANSLATION_FORM, 'news-save', ['content' => 'Rozepsaný text', 'title' => 'Nový název']),
	];
	$app->container->getByType(Explorer::class)->query('DELETE FROM news WHERE id = ?', $id);
	$count = newsCount($app);

	foreach ($submissions as $form => [$action, $body]) {
		$result = $app->post($action, $body);
		Assert::same(200, $result->code, $form);
		Assert::same([DELETED_MEANWHILE], errors($result, $form), $form);
		Assert::same([], flashes($result), $form);
		Assert::same($count, newsCount($app), $form);
	}
	$translation = $app->post(...$submissions[TRANSLATION_FORM]);
	Assert::same('Rozepsaný text', fieldValue($translation, 'news-content-input'));
	Assert::same('Nový název', fieldValue($translation, 'news-title-input'));
	Assert::count(0, nodes($translation->dom(), PROPERTIES_FORM));
	$properties = $app->post(...$submissions[PROPERTIES_FORM]);
	Assert::same('2003-03-03T12:00', fieldValue($properties, 'news-published-at'));
	Assert::count(0, nodes($properties->dom(), TRANSLATION_FORM));

	Assert::same(404, $app->get("/admin/news/$id")->code);
	Assert::same(404, $app->post("/admin/news/$id", [])->code);
});


test('preview renders the Markdown below the form and saves nothing', function () use ($app): void {
	[$editor] = resetNews($app);
	$id = createNews($app, $editor, 'Náhledová novinka');
	signedIn($app);
	[$action, $body] = formSubmit($app->get("/admin/news/$id"), TRANSLATION_FORM, 'news-preview', ['content' => "## Náhledový nadpis\n\n<script>x</script>", 'title' => '', 'perex' => '']);
	$result = $app->post($action, $body);
	Assert::same(200, $result->code);
	Assert::same([], errors($result, TRANSLATION_FORM));
	$output = nodes($result->dom(), '//*[@data-testid="news-preview-output"]');
	Assert::count(1, $output);
	Assert::count(1, nodes($output[0], './/h2[normalize-space()="Náhledový nadpis"]'));
	Assert::count(0, nodes($output[0], './/script'));
	Assert::same('', fieldValue($result, 'news-title-input'));
	Assert::same([], flashes($result));
	Assert::same(1, newsTranslation($app, $id)?->version);
	Assert::same('Obsah Náhledová novinka', newsTranslation($app, $id)?->content);
});


test('the confirmation deletes only by POST', function () use ($app): void {
	[$editor] = resetNews($app);
	$id = createNews($app, $editor, 'Mazaná novinka');
	signedIn($app);
	$link = nodes($app->get("/admin/news/$id")->dom(), '//a[@data-testid="news-delete"]');
	Assert::count(1, $link);
	Assert::same("/admin/news/$id/delete", (string) $link[0]['href']);
	Assert::same('Smazat novinku', trim((string) $link[0]));

	$screen = $app->get("/admin/news/$id/delete");
	Assert::same(200, $screen->code);
	Assert::contains('Opravdu smazat novinku „Mazaná novinka“? Smažou se všechny jazyky a staré adresy přestanou přesměrovávat.', $screen->body);
	$back = nodes($screen->dom(), '//a[@data-testid="news-delete-back"]');
	Assert::same("/admin/news/$id", (string) $back[0]['href']);
	Assert::same('Zpět', trim((string) $back[0]));

	$count = newsCount($app);
	[$action, $body] = formSubmit($screen, DELETE_FORM, 'news-delete-confirm');
	$get = $app->get($action . (str_contains($action, '?') ? '&' : '?') . http_build_query($body));
	Assert::same(200, $get->code);
	Assert::same($count, newsCount($app));
	Assert::same(303, $app->post($action, $body, ['Sec-Fetch-Site' => 'cross-site'])->code);
	Assert::same($count, newsCount($app));

	$result = $app->post($action, $body);
	Assert::same(303, $result->code);
	Assert::match('/admin/news?_fid=%a%', (string) $result->location);
	Assert::same(['Novinka byla smazána.'], flashes($app->get((string) $result->location)));
	Assert::same($count - 1, newsCount($app));
	Assert::same(404, $app->get("/admin/news/$id")->code);
	Assert::same(404, $app->get("/admin/news/$id/delete")->code);
	Assert::same(404, $app->post($action, $body)->code);
});


test('a form posted to another screen is 404 and a cross-site save changes nothing', function () use ($app): void {
	[$editor] = resetNews($app);
	$id = createNews($app, $editor, 'Cizí novinka');
	signedIn($app);
	$count = newsCount($app);
	$posts = [
		'/admin/news?do=properties-submit' => ['save' => '', 'version' => '1', 'publishedAt' => ''],
		'/admin/news?do=translation-submit' => ['save' => '', 'title' => 'X', 'perex' => 'P'],
		'/admin/news/new?do=translation-submit' => ['save' => '', 'title' => 'X', 'perex' => 'P'],
		"/admin/news/$id?do=newNews-submit" => ['save' => '', 'title' => 'X', 'perex' => 'P'],
		"/admin/news/$id?do=delete-submit" => ['delete' => ''],
		"/admin/news/$id/delete?do=translation-submit" => ['save' => '', 'title' => 'X', 'perex' => 'P'],
		'/admin/news?do=newNews-submit' => ['save' => '', 'title' => 'X', 'perex' => 'P'],
	];
	foreach ($posts as $path => $body) {
		Assert::same(404, $app->post($path, $body)->code, $path);
	}
	Assert::same($count, newsCount($app));
	Assert::same(1, newsTranslation($app, $id)?->version);

	[$action, $body] = formSubmit($app->get("/admin/news/$id"), TRANSLATION_FORM, 'news-save', ['content' => 'Cizí']);
	Assert::same(303, $app->post($action, $body, ['Sec-Fetch-Site' => 'cross-site'])->code);
	Assert::same(1, newsTranslation($app, $id)?->version);
});


test('a save replayed after the sign-in expired shows the typed values and saves nothing', function () use ($app): void {
	[$editor] = resetNews($app);
	$id = createNews($app, $editor, 'Obnovená novinka');
	signedIn($app);

	[$action, $body] = formSubmit($app->get("/admin/news/$id"), TRANSLATION_FORM, 'news-save', ['content' => 'Rozepsaný text']);
	$restored = restoredAfterSignIn($app, $action, $body);
	Assert::same('Rozepsaný text', fieldValue($restored, 'news-content-input'));
	Assert::same(1, newsTranslation($app, $id)?->version);

	[$action, $body] = formSubmit($app->get("/admin/news/$id"), PROPERTIES_FORM, 'news-properties-save', ['publishedAt' => '2001-05-05T12:00']);
	$restored = restoredAfterSignIn($app, $action, $body);
	Assert::same('2001-05-05T12:00', fieldValue($restored, 'news-published-at'));
	Assert::same(1, newsVersion($app, $id));
	Assert::same('2000-01-01 10:00', storedAt($app, $id));

	$count = newsCount($app);
	[$action, $body] = formSubmit($app->get('/admin/news/new'), NEW_FORM, 'news-save', ['title' => 'Rozepsaná', 'perex' => 'Rozepsaný perex', 'content' => 'Rozepsaný text']);
	$restored = restoredAfterSignIn($app, $action, $body);
	Assert::same('Rozepsaná', fieldValue($restored, 'news-title-input'));
	Assert::same('Rozepsaný text', fieldValue($restored, 'news-content-input'));
	Assert::same($count, newsCount($app));

	// Saving the restored form works.
	[$action, $body] = formSubmit($restored, NEW_FORM, 'news-save');
	Assert::same(303, $app->post($action, $body)->code);
	Assert::same($count + 1, newsCount($app));
});


test('without sign-in the screens redirect to sign-in', function () use ($app): void {
	$app->newBrowser();
	foreach (['/admin/news/1', '/admin/news/new', '/admin/news/1/delete'] as $path) {
		$result = $app->get($path);
		Assert::same(302, $result->code, $path);
		Assert::match('/admin/sign-in?backlink=%a%', (string) $result->location);
	}
});


test('with one language there are no tabs', function (): void {
	$app = HttpApplication::boot();
	[$editor] = resetNews($app);
	$id = createNews($app, $editor, 'Jednojazyčná');
	signedIn($app);
	$result = $app->get("/admin/news/$id");
	Assert::same(200, $result->code);
	Assert::count(0, nodes($result->dom(), '//*[@data-testid="news-tab"]'));
	Assert::same(404, $app->get("/admin/news/$id/en")->code);
});
