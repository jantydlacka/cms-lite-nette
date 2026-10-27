<?php

declare(strict_types=1);

use App\Model\User\Role;
use Nette\Utils\Strings;
use Tester\Assert;
use Tests\Presentation\Admin\AdminBrowser;
use Tests\Support\HttpApplication;
use Tests\Support\HttpResult;

require __DIR__ . '/../../bootstrap.php';

const EDITOR = 'editor@example.com';
// Keep the failed attempts of this file under the IP limit (20): every request comes from the same (unknown) address.
const THROTTLED = 'throttled@example.com';
// Nette answers a redirect after POST with 303 See Other (302 after GET).
const POST_REDIRECT = 303;


function assertSignedIn(HttpApplication $app): void
{
	$result = $app->get('/admin');
	Assert::same(200, $result->code, 'signed in');
	Assert::same('Administrace', $result->text('admin-title'));
}


function assertSignedOut(HttpApplication $app): void
{
	$result = $app->get('/admin');
	Assert::same(302, $result->code, 'signed out');
	Assert::match('/admin/sign-in?%a%', (string) $result->location);
}


function formError(HttpResult $result): ?string
{
	return $result->text('form-error');
}


$app = HttpApplication::boot();
AdminBrowser::seed($app->container, EDITOR, 'Eva Editorová', Role::Editor);
AdminBrowser::seed($app->container, THROTTLED, 'Tomáš Blokovaný', Role::Editor);
AdminBrowser::seed($app->container, 'jan@žluť.cz', 'Jan Žluťoučký', Role::Editor);


test('/admin redirects to sign-in with a backlink and signing in returns there', function () use ($app): void {
	$app->newBrowser();
	$result = $app->get('/admin');
	Assert::same(302, $result->code);
	Assert::match('/admin/sign-in?backlink=%a%', (string) $result->location);
	$backlink = Strings::match((string) $result->location, '~backlink=([^&]*)~')[1] ?? '';
	Assert::same(1, preg_match('~^[a-z0-9]{5}$~', $backlink), "the backlink is a key, not a URL: $backlink");

	$page = $app->get((string) $result->location);
	Assert::same(200, $page->code);
	Assert::same('Pro pokračování se přihlaste.', $page->text('flash'));

	$result = AdminBrowser::signIn($app, EDITOR, page: (string) $result->location);
	Assert::same(POST_REDIRECT, $result->code);
	Assert::same('/admin', $result->location);
});


test('an unknown backlink leads to the dashboard', function () use ($app): void {
	$app->newBrowser();
	$result = AdminBrowser::signIn($app, EDITOR, page: '/admin/sign-in?backlink=nonsense');
	Assert::same(POST_REDIRECT, $result->code);
	Assert::same('/admin', $result->location);
});


test('the sign-in page renders the form', function () use ($app): void {
	$app->newBrowser();
	$result = $app->get('/admin/sign-in');
	Assert::same(200, $result->code);
	foreach (['sign-in-form', 'sign-in-email', 'sign-in-password', 'sign-in-submit'] as $testId) {
		Assert::count(1, $result->dom()->find('[data-testid="' . $testId . '"]'), $testId);
	}
	Assert::same('254', (string) $result->dom()->find('[data-testid="sign-in-email"]')[0]['maxlength']);
	Assert::null($result->text('admin-user'));
	Assert::null($result->text('sign-out'));
});


test('an editor signs in and sees the dashboard with their name', function () use ($app): void {
	$app->newBrowser();
	Assert::same('/admin', AdminBrowser::signIn($app, EDITOR)->location);

	$result = $app->get('/admin');
	Assert::same(200, $result->code);
	Assert::same('Eva Editorová', $result->text('admin-user'));
	Assert::same('Administrace', $result->text('admin-title'));
	Assert::count(1, $result->dom()->find('[data-testid="sign-out"]'));
});


test('an account with a non-ASCII domain signs in with the domain typed either way', function () use ($app): void {
	foreach (['jan@xn--lu-yta1h.cz', 'jan@žluť.cz'] as $email) {
		$app->newBrowser();
		Assert::same('/admin', AdminBrowser::signIn($app, $email)->location, $email);
		assertSignedIn($app);
	}
});

test('a wrong password shows the form error and signs nobody in', function () use ($app): void {
	$app->newBrowser();
	$result = AdminBrowser::signIn($app, EDITOR, 'wrong-password-1');
	Assert::same(200, $result->code);
	Assert::same('Nesprávný e-mail nebo heslo.', formError($result));
	assertSignedOut($app);
});


test('after five failures the sixth attempt is refused even with the right password', function () use ($app): void {
	$app->newBrowser();
	for ($i = 1; $i <= 5; $i++) {
		Assert::same('Nesprávný e-mail nebo heslo.', formError(AdminBrowser::signIn($app, THROTTLED, 'wrong-password-1')), "attempt $i");
	}

	$result = AdminBrowser::signIn($app, THROTTLED);
	Assert::same(200, $result->code);
	Assert::same('Příliš mnoho pokusů, zkuste to později.', formError($result));
	assertSignedOut($app);
});


test('a cross-site sign-in post is not processed', function () use ($app): void {
	$app->newBrowser();
	$result = AdminBrowser::signIn($app, EDITOR, headers: ['Sec-Fetch-Site' => 'cross-site']);
	Assert::same(POST_REDIRECT, $result->code);
	Assert::same('/admin/sign-in', $result->location);
	assertSignedOut($app);
});


test('a same-site sign-in post is not processed', function () use ($app): void {
	$app->newBrowser();
	$result = AdminBrowser::signIn($app, EDITOR, headers: ['Sec-Fetch-Site' => 'same-site']);
	Assert::notSame('/admin', $result->location);
	assertSignedOut($app);
});


test('without Sec-Fetch-Site and without the strict cookie the sign-in post is not processed', function () use ($app): void {
	$app->newBrowser();
	AdminBrowser::signIn($app, EDITOR, headers: ['Sec-Fetch-Site' => null]);
	assertSignedOut($app);
});


test('without Sec-Fetch-Site the strict cookie lets the sign-in through', function () use ($app): void {
	$app->newBrowser();
	$app->setCookie('_nss', '1');
	Assert::same('/admin', AdminBrowser::signIn($app, EDITOR, headers: ['Sec-Fetch-Site' => null])->location);
	assertSignedIn($app);
});


test('a cross-site sign-out redirects and keeps the user signed in', function () use ($app): void {
	$app->newBrowser();
	AdminBrowser::signIn($app, EDITOR);

	$result = AdminBrowser::signOut($app, ['Sec-Fetch-Site' => 'cross-site']);
	Assert::same(POST_REDIRECT, $result->code);
	Assert::same('/admin', $result->location);
	assertSignedIn($app);
});


test('a same-site sign-out keeps the user signed in', function () use ($app): void {
	$app->newBrowser();
	AdminBrowser::signIn($app, EDITOR);

	AdminBrowser::signOut($app, ['Sec-Fetch-Site' => 'same-site']);
	assertSignedIn($app);
});


test('a GET of the sign-out signal is refused and keeps the user signed in', function () use ($app): void {
	$app->newBrowser();
	AdminBrowser::signIn($app, EDITOR);

	$action = AdminBrowser::signOutAction($app);
	Assert::same('/admin?do=signOut', $action);
	$result = $app->get($action);
	Assert::same(405, $result->code);
	assertSignedIn($app);
});


test('a same-origin sign-out signs out with a message', function () use ($app): void {
	$app->newBrowser();
	AdminBrowser::signIn($app, EDITOR);

	$result = AdminBrowser::signOut($app);
	Assert::same(POST_REDIRECT, $result->code);
	Assert::match('/admin/sign-in%a?%', (string) $result->location);
	Assert::same('Byli jste odhlášeni.', $app->get((string) $result->location)->text('flash'));
	assertSignedOut($app);
});


test('the sign-in page redirects a signed-in user to the dashboard', function () use ($app): void {
	$app->newBrowser();
	AdminBrowser::signIn($app, EDITOR);

	$result = $app->get('/admin/sign-in');
	Assert::same(302, $result->code);
	Assert::same('/admin', $result->location);
});


test('signing in changes the session id started by the redirect to sign-in', function () use ($app): void {
	$app->newBrowser();
	// storeRequest() and the flash message start the session before any output.
	$redirect = $app->get('/admin');
	$before = $redirect->sessionId();
	Assert::notSame(null, $before);

	$result = AdminBrowser::signIn($app, EDITOR, page: (string) $redirect->location);
	Assert::same('/admin', $result->location);
	Assert::notSame(null, $result->sessionId());
	Assert::notSame($before, $result->sessionId());
	assertSignedIn($app);
});


test('signing in without a prior session creates one that ends with the browser', function () use ($app): void {
	$app->newBrowser();
	$page = $app->get('/admin/sign-in');
	Assert::same(200, $page->code);
	Assert::null($page->sessionId());
	Assert::false(isset($app->cookies()[(string) session_name()]));

	$result = AdminBrowser::signIn($app, EDITOR);
	Assert::same('/admin', $result->location);
	Assert::notSame(null, $result->sessionId());
	Assert::same($result->sessionId(), $app->cookies()[(string) session_name()] ?? null);
	assertSignedIn($app);

	$params = session_get_cookie_params();
	Assert::same(0, $params['lifetime']);
	Assert::true($params['httponly']);
	Assert::same('Lax', $params['samesite']);
});


// The tests below boot their own application, which wipes the shared data above.
test('the demo banner shows only while demo data are enabled', function (): void {
	// Static parameters outrank config files, so demo data are switched like in production: by the environment.
	$original = getenv('CMS_DEMO_DATA');
	try {
		foreach (['1' => true, '0' => false] as $env => $demoData) {
			putenv('CMS_DEMO_DATA=' . $env);
			$app = HttpApplication::boot();
			AdminBrowser::seed($app->container, EDITOR, 'Eva Editorová', Role::Editor);
			$expected = $demoData ? 'Běží demo data – účty se známým heslem, nepoužívat na ostrém webu.' : null;

			Assert::same($expected, $app->get('/admin/sign-in')->text('demo-banner'), "sign-in page, CMS_DEMO_DATA=$env");
			$notFound = $app->get('/admin/neexistuje');
			Assert::same(404, $notFound->code);
			Assert::same($expected, $notFound->text('demo-banner'), "admin 404, CMS_DEMO_DATA=$env");
			AdminBrowser::signIn($app, EDITOR);
			Assert::same($expected, $app->get('/admin')->text('demo-banner'), "dashboard, CMS_DEMO_DATA=$env");
		}
	} finally {
		putenv($original === false ? 'CMS_DEMO_DATA' : 'CMS_DEMO_DATA=' . $original);
	}
});
