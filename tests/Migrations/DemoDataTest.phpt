<?php

declare(strict_types=1);

use App\Bootstrap;
use App\Model\News\NewsRepository;
use Nette\Database\Connection;
use Nette\Security\Passwords;
use Tester\Assert;
use Tests\Support\TestDatabase;
use function Tests\container;

require __DIR__ . '/../bootstrap.php';

// One container for the file: container() prepares the database on every call, and resetDummyData() runs the
// migrations only on its first call.
$container = container();

test('the prepared test database has no pages', function () use ($container): void {
	$connection = $container->getByType(Connection::class);

	Assert::same(0, $connection->fetchField('SELECT COUNT(*) FROM page'));
	Assert::same(0, $connection->fetchField('SELECT COUNT(*) FROM page_translation'));
	Assert::same(0, $connection->fetchField('SELECT COUNT(*) FROM path_redirect'));
});


test('the dummy-data group creates the demo pages', function () use ($container): void {
	TestDatabase::resetDummyData($container);
	$connection = $container->getByType(Connection::class);

	Assert::same(5, $connection->fetchField('SELECT COUNT(*) FROM page'));
	Assert::same(['kontakt', 'o-nas', 'rozpracovano', 'tym', 'uvod'], array_keys($connection->fetchPairs('SELECT slug, locale FROM page_translation WHERE locale = ? ORDER BY slug', 'cs')));

	$homepage = $connection->fetchField('SELECT value FROM setting WHERE `key` = ? AND locale = ?', 'homepage', '');
	Assert::same('uvod', $connection->fetchField('SELECT slug FROM page_translation WHERE locale = ? AND page_id = ?', 'cs', $homepage));

	Assert::same(['o-nas' => 'about-us', 'uvod' => 'welcome'], $connection->fetchPairs('SELECT cs.slug AS cs_slug, en.slug AS en_slug FROM page_translation en JOIN page_translation cs ON cs.page_id = en.page_id AND cs.locale = ? WHERE en.locale = ? ORDER BY en.slug', 'cs', 'en'));
	Assert::same('published', $connection->fetchField('SELECT status FROM page_translation WHERE locale = ? AND slug = ?', 'en', 'about-us'));
	Assert::same(['Welcome', 'published'], [$connection->fetchField('SELECT title FROM page_translation WHERE locale = ? AND slug = ?', 'en', 'welcome'), $connection->fetchField('SELECT status FROM page_translation WHERE locale = ? AND slug = ?', 'en', 'welcome')]);
	Assert::same((int) (is_scalar($homepage) ? $homepage : 0), $connection->fetchField('SELECT page_id FROM page_translation WHERE locale = ? AND slug = ?', 'en', 'welcome'));
	Assert::same(['rozpracovano' => 'draft'], $connection->fetchPairs('SELECT slug, status FROM page_translation WHERE status = ?', 'draft'));

	$parent = $connection->fetchField('SELECT p.parent_id FROM page p JOIN page_translation t ON t.page_id = p.id AND t.locale = ? WHERE t.slug = ?', 'cs', 'tym');
	Assert::same('o-nas', $connection->fetchField('SELECT slug FROM page_translation WHERE locale = ? AND page_id = ?', 'cs', $parent));
	Assert::same(
		['uvod' => 0, 'o-nas' => 1, 'kontakt' => 2],
		$connection->fetchPairs('SELECT t.slug, p.position FROM page p JOIN page_translation t ON t.page_id = p.id AND t.locale = ? WHERE t.slug IN (?, ?, ?) AND p.parent_id IS NULL ORDER BY p.position', 'cs', 'uvod', 'o-nas', 'kontakt'),
	);
	Assert::same(5, $connection->fetchField('SELECT COUNT(*) FROM page WHERE in_menu = 1'));

	$redirect = $connection->fetch('SELECT * FROM path_redirect');
	assert($redirect !== null);
	Assert::same(['cs', 'o-firme', 'page'], [$redirect->locale, $redirect->old_path, $redirect->target_type]);
	Assert::same('o-nas', $connection->fetchField('SELECT slug FROM page_translation WHERE locale = ? AND page_id = ?', 'cs', $redirect->target_id));

	foreach ($connection->fetchPairs('SELECT slug, content FROM page_translation') as $slug => $content) {
		assert(is_string($content));
		Assert::match('~^## .+~m', $content, $slug);
		Assert::match('~\[[^\]]+\]\([^)]+\)~', $content, $slug);
	}

	// The page title is rendered as the h1; content must not open with the same heading again.
	foreach ($connection->fetchAll('SELECT slug, title, content FROM page_translation') as $row) {
		assert(is_string($row->content) && is_string($row->title) && is_string($row->slug));
		$first = trim(explode("\n", ltrim($row->content), 2)[0]);
		Assert::notSame($row->title, trim(ltrim($first, "#")), $row->slug);
	}
});


test('the dummy-data group creates the public demo accounts', function () use ($container): void {
	TestDatabase::resetDummyData($container);
	$connection = $container->getByType(Connection::class);
	$passwords = new Passwords();

	$accounts = [
		'admin@example.com' => ['Demo Admin', 'admin', 'demo-admin-password'],
		'editor@example.com' => ['Demo Editor', 'editor', 'demo-editor-password'],
	];
	Assert::same(2, $connection->fetchField('SELECT COUNT(*) FROM `user`'));
	foreach ($accounts as $email => [$name, $role, $password]) {
		$row = $connection->fetch('SELECT * FROM `user` WHERE email = ?', $email);
		assert($row !== null && is_string($row->password_hash));
		Assert::same([$name, $role, 1], [$row->name, $row->role, $row->active], $email);
		Assert::true($passwords->verify($password, $row->password_hash), $email);
		Assert::false($passwords->verify('wrong-password-123', $row->password_hash), $email);
	}
});


test('the dummy-data group creates the demo news', function () use ($container): void {
	TestDatabase::resetDummyData($container);
	$connection = $container->getByType(Connection::class);
	$repository = $container->getByType(NewsRepository::class);

	Assert::same(12, $repository->countVisible('cs'));
	Assert::same(3, $repository->countVisible('en'));
	Assert::same(14, $connection->fetchField('SELECT COUNT(*) FROM news'));

	$draft = $connection->fetch('SELECT n.published_at, t.status FROM news n JOIN news_translation t ON t.news_id = n.id WHERE n.published_at IS NULL');
	assert($draft !== null);
	Assert::same('draft', $draft->status);

	$scheduled = $connection->fetch('SELECT t.status FROM news n JOIN news_translation t ON t.news_id = n.id WHERE n.published_at > UTC_TIMESTAMP()');
	assert($scheduled !== null);
	Assert::same('published', $scheduled->status);

	$admin = $connection->fetchField('SELECT id FROM `user` WHERE email = ?', 'admin@example.com');
	Assert::same(0, $connection->fetchField('SELECT COUNT(*) FROM news WHERE author_id IS NULL OR author_id <> ?', $admin));

	foreach ($connection->fetchAll('SELECT slug, title, perex, content FROM news_translation') as $row) {
		assert(is_string($row->content) && is_string($row->title) && is_string($row->perex) && is_string($row->slug));
		Assert::match('~^## .+~m', $row->content, $row->slug);
		Assert::true(mb_strlen($row->perex) >= 1 && mb_strlen($row->perex) <= 300, $row->slug);
		Assert::notSame($row->title, trim(ltrim(trim(explode("\n", ltrim($row->content), 2)[0]), '#')), $row->slug);
	}
});


/**
 * @param array<string, string> $extraEnv
 * @return array{int, string}
 */
function resetThroughConsole(array $extraEnv): array
{
	$database = Bootstrap::testDatabaseName(getenv('NETTE_TESTER_THREAD'));
	Assert::true(str_starts_with($database, Bootstrap::TEST_DB_PREFIX));

	$env = ['CMS_DB_NAME' => $database, 'NETTE_DEBUG' => '0'] + $extraEnv;
	foreach (['CMS_DB_HOST', 'CMS_DB_PORT', 'CMS_DB_USER', 'CMS_DB_PASSWORD'] as $name) {
		$value = getenv($name);
		$env[$name] = $value === false ? '' : $value;
	}
	$env['PATH'] = getenv('PATH') === false ? '/usr/local/bin:/usr/bin:/bin' : getenv('PATH');

	$process = proc_open([PHP_BINARY, __DIR__ . '/../../bin/console', 'migrations:reset'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
	assert(is_resource($process));
	$output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);

	return [proc_close($process), $output];
}


test('migrations:reset loads demo pages only when CMS_DEMO_DATA is 1', function () use ($container): void {
	$connection = $container->getByType(Connection::class);
	// The earlier tests left the demo data here; start empty so that the console has to load the pages.
	TestDatabase::prepare($container);
	Assert::same(0, $connection->fetchField('SELECT COUNT(*) FROM page'));

	[$code, $output] = resetThroughConsole(['CMS_DEMO_DATA' => '1']);
	Assert::same(0, $code, $output);
	Assert::same(5, $connection->fetchField('SELECT COUNT(*) FROM page'));

	[$code, $output] = resetThroughConsole(['CMS_DEMO_DATA' => '0']);
	Assert::same(0, $code, $output);
	Assert::same(0, $connection->fetchField('SELECT COUNT(*) FROM page'));
});
