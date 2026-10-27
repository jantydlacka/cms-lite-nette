<?php

declare(strict_types=1);

use App\Model\Page\PageRepository;
use App\Model\Page\PageService;
use App\Model\Page\SavePageTranslation;
use App\Model\PublicationStatus;
use App\Model\Setting\SettingRepository;
use App\Model\User\Role;
use Nette\Database\Explorer;
use Tester\Assert;
use Tests\Presentation\Admin\AdminBrowser;
use Tests\Presentation\Admin\fixtures\ProbeControl;
use Tests\Support\HttpApplication;

require __DIR__ . '/../../bootstrap.php';

const EDITOR = 'editor@example.com';


/** Follows the redirect to sign-in and returns its flash message. */
function signInFlash(HttpApplication $app): ?string
{
	$result = $app->get('/admin');
	Assert::same(302, $result->code);
	Assert::match('/admin/sign-in?backlink=%a%', (string) $result->location);

	return $app->get((string) $result->location)->text('flash');
}


/** Moves the inactivity deadline of the signed-in session into the past (SessionStorage compares it with time()). */
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
		Assert::type('int', $section['expireTime'] ?? null);
		$section['expireTime'] = time() - 1;
		$nf['DATA']['Nette.Http.UserStorage/'] = $section;
		$_SESSION['__NF'] = $nf;
	} finally {
		session_write_close();
		session_id('');
		$_SESSION = [];
	}
}


$app = HttpApplication::boot([__DIR__ . '/admin-probe.neon']);
$editorId = AdminBrowser::seed($app->container, EDITOR, 'Eva Editorová', Role::Editor);


test('an anonymous visitor is sent to sign-in with a request to sign in', function () use ($app): void {
	$app->newBrowser();
	Assert::same('Pro pokračování se přihlaste.', signInFlash($app));
});


test('a deactivated account is signed out on the next request', function () use ($app, $editorId): void {
	$app->newBrowser();
	AdminBrowser::signIn($app, EDITOR);
	Assert::same(200, $app->get('/admin')->code);

	AdminBrowser::updateUser($app->container, $editorId, ['active' => 0]);
	try {
		// The identity is vetoed, not expired: the reason is unknown, so the generic message applies.
		Assert::same('Pro pokračování se přihlaste.', signInFlash($app));
	} finally {
		AdminBrowser::updateUser($app->container, $editorId, ['active' => 1]);
	}
});


test('a role change applies on the next request', function () use ($app, $editorId): void {
	$app->newBrowser();
	AdminBrowser::signIn($app, EDITOR);
	$result = $app->get('/admin');
	Assert::contains('Editor', (string) $result->text('admin-role'));

	AdminBrowser::updateUser($app->container, $editorId, ['role' => Role::Admin->value]);
	try {
		$result = $app->get('/admin');
		Assert::same(200, $result->code);
		Assert::same('Administrátor', $result->text('admin-role'));
	} finally {
		AdminBrowser::updateUser($app->container, $editorId, ['role' => Role::Editor->value]);
	}
});


test('after the inactivity deadline the user is signed out with a message', function () use ($app): void {
	$app->newBrowser();
	AdminBrowser::signIn($app, EDITOR);
	Assert::same(200, $app->get('/admin')->code);

	expireSignIn($app);
	Assert::same('Byli jste odhlášeni kvůli nečinnosti.', signInFlash($app));
});


test('a reactivated account does not revive the session signed out by the deactivation', function () use ($app, $editorId): void {
	$app->newBrowser();
	AdminBrowser::signIn($app, EDITOR);
	Assert::same(200, $app->get('/admin')->code);

	AdminBrowser::updateUser($app->container, $editorId, ['active' => 0]);
	try {
		Assert::same(302, $app->get('/admin')->code);
	} finally {
		AdminBrowser::updateUser($app->container, $editorId, ['active' => 1]);
	}

	Assert::same('Pro pokračování se přihlaste.', signInFlash($app));
});


test('a cross-site request of an anonymous visitor is not stored for after sign-in', function () use ($app): void {
	$app->newBrowser();
	$result = $app->post('/admin?do=signOut', [], ['Sec-Fetch-Site' => 'cross-site']);
	Assert::same(303, $result->code);
	Assert::match('/admin/sign-in%a?%', (string) $result->location);
	Assert::notContains('backlink', (string) $result->location);

	$app->newBrowser();
	$result = $app->post('/admin?do=signOut', []);
	Assert::same(303, $result->code);
	Assert::match('/admin/sign-in?backlink=%a%', (string) $result->location);
});


test('a stored signal is not executed when its request is restored after sign-in', function () use ($app): void {
	$app->newBrowser();
	$stored = $app->post('/admin?do=signOut', []);
	Assert::match('/admin/sign-in?backlink=%a%', (string) $stored->location);

	AdminBrowser::signIn($app, EDITOR, page: (string) $stored->location);
	Assert::same(200, $app->get('/admin')->code, 'still signed in');
});


test('a stored component signal is not executed when its request is restored after sign-in', function () use ($app): void {
	$app->newBrowser();
	AdminBrowser::signIn($app, EDITOR);
	ProbeControl::$calls = 0;
	Assert::same(200, $app->post('/admin/probe?do=probe-touch', [])->code);
	Assert::same(1, ProbeControl::$calls, 'the signal works for a signed-in user');

	$app->newBrowser();
	ProbeControl::$calls = 0;
	$stored = $app->post('/admin/probe?do=probe-touch', []);
	Assert::match('/admin/sign-in?backlink=%a%', (string) $stored->location);

	AdminBrowser::signIn($app, EDITOR, page: (string) $stored->location);
	Assert::same(0, ProbeControl::$calls);
});


test('a GET with a signal is not stored, a GET without one is, even cross-site', function () use ($app): void {
	foreach ([['/admin?do=signOut', 'cross-site'], ['/admin?do=signOut', 'same-origin'], ['/admin/probe?do=probe-touch', 'cross-site'], ['/admin/probe?do=probe-touch', 'same-origin']] as [$path, $site]) {
		$app->newBrowser();
		$result = $app->get($path, ['Sec-Fetch-Site' => $site]);
		Assert::same(302, $result->code, "$path $site");
		Assert::match('/admin/sign-in%a?%', (string) $result->location, "$path $site");
		Assert::notContains('backlink', (string) $result->location, "$path $site");
	}

	$app->newBrowser();
	$result = $app->get('/admin?x=1', ['Sec-Fetch-Site' => 'cross-site']);
	Assert::match('/admin/sign-in?backlink=%a%', (string) $result->location);
});


test('signing in returns to the stored request with its parameters', function () use ($app): void {
	$app->newBrowser();
	$stored = $app->get('/admin?x=1');
	Assert::match('/admin/sign-in?backlink=%a%', (string) $stored->location);

	$result = AdminBrowser::signIn($app, EDITOR, page: (string) $stored->location);
	Assert::same(303, $result->code);
	Assert::same('/admin?x=1', $result->location);
	Assert::same(200, $app->get('/admin?x=1')->code);
});


foreach (['empty' => '', 'invalid' => 'not-an-email', 'too long' => str_repeat('a', 243) . '@example.com'] as $name => $email) {
	test("a malformed e-mail is refused without a sign-in attempt: $name", function () use ($app, $email): void {
		$app->newBrowser();
		$attempts = $app->container->getByType(Explorer::class)->table('login_attempt');
		$before = $attempts->count('*');

		$result = AdminBrowser::signIn($app, $email);
		Assert::same(200, $result->code);
		Assert::same('Nesprávný e-mail nebo heslo.', $result->text('form-error'));
		Assert::same($before, $app->container->getByType(Explorer::class)->table('login_attempt')->count('*'));
	});
}


test('the public web neither starts a session nor sets a cookie', function () use ($app): void {
	$pages = $app->container->getByType(PageRepository::class);
	$service = $app->container->getByType(PageService::class);
	$home = $pages->createPage(null, 0, true);
	$service->saveTranslation(new SavePageTranslation($home, 'cs', 'Úvod', 'uvod', 'Text', '', PublicationStatus::Published));
	$about = $pages->createPage(null, 1, true);
	$service->saveTranslation(new SavePageTranslation($about, 'cs', 'O nás', 'o-nas', 'Text', '', PublicationStatus::Published));
	$app->container->getByType(SettingRepository::class)->set('homepage', (string) $home);

	$app->newBrowser();
	foreach (['/' => 200, '/o-nas' => 200, '/o-nas/' => 301, '/neexistuje' => 404] as $path => $code) {
		$result = $app->get($path);
		Assert::same($code, $result->code, $path);
		Assert::false($result->sessionStarted(), $path);
		Assert::same([], $app->cookies(), $path);
	}
});
