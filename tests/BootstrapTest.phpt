<?php

declare(strict_types=1);

use App\Bootstrap;
use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

test('debug mode is enabled only by the exact value 1', function (): void {
	Assert::true(Bootstrap::isDebugMode('1'));
	Assert::false(Bootstrap::isDebugMode('0'));
	Assert::false(Bootstrap::isDebugMode(false));
	Assert::false(Bootstrap::isDebugMode('true'));
});


test('demo data follows debug mode unless CMS_DEMO_DATA is set', function (): void {
	Assert::true(Bootstrap::isDemoData(false, true));
	Assert::false(Bootstrap::isDemoData(false, false));
	Assert::true(Bootstrap::isDemoData('1', false));
	Assert::false(Bootstrap::isDemoData('0', true));
	Assert::false(Bootstrap::isDemoData('', true));
});


/** @return array{string, callable(): void} temp root with config/, lang/ and app/ */
function fingerprintRoot(): array
{
	$root = sys_get_temp_dir() . '/fp-' . bin2hex(random_bytes(4));
	FileSystem::createDir($root . '/config');
	FileSystem::createDir($root . '/lang');
	FileSystem::createDir($root . '/app/Sub');
	file_put_contents($root . '/config/common.neon', "a: 1\n");
	file_put_contents($root . '/lang/cs.neon', "b: 1\n");
	file_put_contents($root . '/app/Sub/Foo.php', '<?php // foo');

	return [$root, static fn () => FileSystem::delete($root)];
}


test('test container fingerprint is stable and reacts to config and code changes', function (): void {
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
	Assert::notSame($touched, Bootstrap::testContainerFingerprint($root));

	$cleanup();
});


test('bootForTests keys the container by the fingerprint', function (): void {
	$parameters = Bootstrap::bootForTests()->createContainer()->getParameters();
	Assert::type('string', $parameters['testContainerFingerprint'] ?? null);
	Assert::same(Bootstrap::testContainerFingerprint(dirname(__DIR__)), $parameters['testContainerFingerprint']);
});
