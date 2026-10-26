<?php

declare(strict_types=1);

use App\Bootstrap;
use App\Model\CmsConfig;
use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

test('debug mode is enabled only by the exact value 1', function (): void {
	Assert::true(Bootstrap::isDebugMode('1'));
	Assert::false(Bootstrap::isDebugMode('0'));
	Assert::false(Bootstrap::isDebugMode(false));
	Assert::false(Bootstrap::isDebugMode('true'));
});


test('booting forces the default timezone to UTC', function (): void {
	date_default_timezone_set('Europe/Prague');
	try {
		Bootstrap::bootForTests();
		Assert::same('UTC', date_default_timezone_get());
	} finally {
		date_default_timezone_set('UTC');
	}
});


test('demo data follows debug mode unless CMS_DEMO_DATA is set', function (): void {
	Assert::true(Bootstrap::isDemoData(false, true));
	Assert::false(Bootstrap::isDemoData(false, false));
	Assert::true(Bootstrap::isDemoData('1', false));
	Assert::false(Bootstrap::isDemoData('0', true));
	Assert::false(Bootstrap::isDemoData('', true));
});


/** @return array{string, callable(): void} temp root with config/, lang/ and app/ (PHP and Latte) */
function fingerprintRoot(): array
{
	$root = sys_get_temp_dir() . '/fp-' . bin2hex(random_bytes(4));
	FileSystem::createDir($root . '/config');
	FileSystem::createDir($root . '/lang');
	FileSystem::createDir($root . '/app/Sub');
	file_put_contents($root . '/config/common.neon', "a: 1\n");
	file_put_contents($root . '/lang/cs.neon', "b: 1\n");
	file_put_contents($root . '/app/Sub/Foo.php', '<?php // foo');
	file_put_contents($root . '/app/Sub/default.latte', 'foo');

	return [$root, static fn () => FileSystem::delete($root)];
}


test('test container fingerprint is stable and reacts to config, code and template changes', function (): void {
	[$root, $cleanup] = fingerprintRoot();
	$base = Bootstrap::testContainerFingerprint($root);
	Assert::same($base, Bootstrap::testContainerFingerprint($root));

	file_put_contents($root . '/config/common.neon', "a: 2\n");
	$config = Bootstrap::testContainerFingerprint($root);
	Assert::notSame($base, $config);

	file_put_contents($root . '/lang/cs.neon', "b: 2\n");
	$lang = Bootstrap::testContainerFingerprint($root);
	Assert::notSame($config, $lang);

	file_put_contents($root . '/app/Bar.php', '<?php');
	$added = Bootstrap::testContainerFingerprint($root);
	Assert::notSame($lang, $added);

	touch($root . '/app/Bar.php', time() + 100);
	$touched = Bootstrap::testContainerFingerprint($root);
	Assert::notSame($added, $touched);

	$mtime = filemtime($root . '/app/Sub/Foo.php');
	file_put_contents($root . '/app/Sub/Foo.php', '<?php // foo, longer');
	touch($root . '/app/Sub/Foo.php', (int) $mtime);
	clearstatcache();
	$resized = Bootstrap::testContainerFingerprint($root);
	Assert::notSame($touched, $resized);

	file_put_contents($root . '/app/Sub/default.latte', 'foo, longer');
	clearstatcache();
	Assert::notSame($resized, Bootstrap::testContainerFingerprint($root));

	$cleanup();
});


test('bootForTests keys the container by the fingerprint', function (): void {
	$parameters = Bootstrap::bootForTests()->createContainer()->getParameters();
	Assert::type('string', $parameters['testContainerFingerprint'] ?? null);
	Assert::same(Bootstrap::testContainerFingerprint(dirname(__DIR__)), $parameters['testContainerFingerprint']);
});


const DB_ENV = ['CMS_DB_HOST' => 'db', 'CMS_DB_PORT' => '3310', 'CMS_DB_NAME' => 'site', 'CMS_DB_USER' => 'app', 'CMS_DB_PASSWORD' => 'secret'];


test('database parameters come from the environment', function (): void {
	$expected = ['dbHost' => 'db', 'dbPort' => '3310', 'dbName' => 'site', 'dbUser' => 'app', 'dbPassword' => 'secret'];
	Assert::same($expected, Bootstrap::databaseParameters(DB_ENV, false));
	Assert::same($expected, Bootstrap::databaseParameters(DB_ENV, true));
});


test('database defaults apply to unset or empty variables when allowed', function (): void {
	Assert::same(
		['dbHost' => '127.0.0.1', 'dbPort' => '3306', 'dbName' => 'cms', 'dbUser' => 'root', 'dbPassword' => 'root'],
		Bootstrap::databaseParameters([], true),
	);
	Assert::same('root', Bootstrap::databaseParameters(['CMS_DB_PASSWORD' => ''] + DB_ENV, true)['dbPassword']);
	Assert::same('3306', Bootstrap::databaseParameters(['CMS_DB_PORT' => false] + DB_ENV, true)['dbPort']);
});


test('without defaults every unset or empty database variable is reported', function (): void {
	Assert::exception(
		fn () => Bootstrap::databaseParameters([], false),
		RuntimeException::class,
		'%a%CMS_DB_HOST, CMS_DB_PORT, CMS_DB_NAME, CMS_DB_USER, CMS_DB_PASSWORD.',
	);
	Assert::exception(
		fn () => Bootstrap::databaseParameters(['CMS_DB_PASSWORD' => ''] + DB_ENV, false),
		RuntimeException::class,
		'%a%: CMS_DB_PASSWORD.',
	);
	Assert::exception(
		fn () => Bootstrap::databaseParameters(['CMS_DB_USER' => false] + DB_ENV, false),
		RuntimeException::class,
		'%a%: CMS_DB_USER.',
	);
});


/** Renders a template in a fresh PHP process, as a direct `tester` run of another test would. */
function renderInChildProcess(string $file): string
{
	$code = 'require $argv[1]; echo App\\Bootstrap::bootForTests()->createContainer()'
		. '->getByType(Nette\\Bridges\\ApplicationLatte\\LatteFactory::class)->create()->renderToString($argv[2]);';
	$process = proc_open([PHP_BINARY, '-r', $code, dirname(__DIR__) . '/vendor/autoload.php', $file], [1 => ['pipe', 'w']], $pipes);
	assert(is_resource($process));
	$output = (string) stream_get_contents($pipes[1]);
	fclose($pipes[1]);
	Assert::same(0, proc_close($process));

	return $output;
}


test('test containers recompile edited templates', function (): void {
	$dir = sys_get_temp_dir() . '/latte-refresh-' . bin2hex(random_bytes(4));
	FileSystem::createDir($dir);
	$file = $dir . '/page.latte';
	file_put_contents($file, 'first');

	Assert::same('first', renderInChildProcess($file));
	file_put_contents($file, 'second');
	Assert::same('second', renderInChildProcess($file));

	FileSystem::delete($dir);
});


test('demo data adds the second language, production keeps the default locale only', function (): void {
	$locales = static function (string $demoData): array {
		putenv('CMS_DEMO_DATA=' . $demoData);
		try {
			$config = Bootstrap::bootForTests()->createContainer()->getByType(CmsConfig::class);
		} finally {
			putenv('CMS_DEMO_DATA');
		}

		return $config->locales;
	};

	Assert::same(['cs', 'en'], $locales('1'));
	Assert::same(['cs'], $locales('0'));
});


test('tests keep a single locale when CMS_DEMO_DATA is unset', function (): void {
	putenv('CMS_DEMO_DATA');
	Assert::same(['cs'], Bootstrap::bootForTests()->createContainer()->getByType(CmsConfig::class)->locales);
});
