<?php

declare(strict_types=1);

use App\Bootstrap;
use App\Core\DI\CmsExtension;
use App\Model\CmsConfig;
use Nette\Bootstrap\Configurator;
use Nette\DI\Container;
use Nette\DI\InvalidConfigurationException;
use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';

/** Compiles a container from a cms: section only; no database is touched. */
function compile(string $cms, bool $demoData = false): Container
{
	$dir = sys_get_temp_dir();
	$dir .= '/cms-extension-' . bin2hex(random_bytes(6));
	FileSystem::createDir($dir);
	$file = $dir . '/config.neon';
	file_put_contents($file, "extensions:\n\tcms: " . CmsExtension::class . "\n\ncms:\n" . $cms);

	try {
		$configurator = new Configurator();
		$configurator->setDebugMode(false);
		$configurator->setTempDirectory($dir);
		$configurator->addStaticParameters(['demoData' => $demoData]);
		$configurator->addConfig($file);

		return $configurator->createContainer();
	} finally {
		FileSystem::delete($dir);
	}
}


test('defaults are applied', function (): void {
	$config = compile("\tsiteName: Demo")->getByType(CmsConfig::class);
	Assert::same('Demo', $config->siteName);
	Assert::same('cs', $config->defaultLocale);
	Assert::same(['cs'], $config->locales);
	Assert::same(10, $config->newsPerPage);
	Assert::false($config->demoData);
});


test('demoData defaults to the demoData parameter and can be overridden', function (): void {
	Assert::true(compile("\tsiteName: Demo", true)->getByType(CmsConfig::class)->demoData);
	Assert::false(compile("\tsiteName: Demo\n\tdemoData: no", true)->getByType(CmsConfig::class)->demoData);
});


test('default locale may differ from the default when listed', function (): void {
	$config = compile("\tsiteName: Demo\n\tdefaultLocale: en\n\tlocales: [en]")->getByType(CmsConfig::class);
	Assert::same('en', $config->defaultLocale);
	Assert::same(['en'], $config->locales);
});


test('explicit values are used', function (): void {
	$config = compile("\tsiteName: Demo\n\tdefaultLocale: en\n\tlocales: [cs, en]\n\tnewsPerPage: 5")->getByType(CmsConfig::class);
	Assert::same('en', $config->defaultLocale);
	Assert::same(['cs', 'en'], $config->locales);
	Assert::same(5, $config->newsPerPage);
});


$invalid = [
	'missing siteName' => ["\tnewsPerPage: 3", '%a%siteName%a%'],
	'default locale not in locales' => ["\tsiteName: Demo\n\tdefaultLocale: en\n\tlocales: [cs]", '%a%defaultLocale%a%'],
	'empty locales' => ["\tsiteName: Demo\n\tlocales: []", '%a%locales%a%'],
	'duplicate locales' => ["\tsiteName: Demo\n\tlocales: [cs, cs]", '%a%locales%a%'],
	'empty locale code' => ["\tsiteName: Demo\n\tlocales: ['']", '%a%locales%a%'],
	'uppercase locale code' => ["\tsiteName: Demo\n\tlocales: [CZ]", '%a%locales%a%'],
	'newsPerPage below 1' => ["\tsiteName: Demo\n\tnewsPerPage: 0", '%a%newsPerPage%a%'],
	'unknown key' => ["\tsiteName: Demo\n\tfoo: 1", '%a%foo%a%'],
];

foreach ($invalid as $name => [$cms, $message]) {
	test('rejected: ' . $name, function () use ($cms, $message): void {
		Assert::exception(fn () => compile($cms), InvalidConfigurationException::class, $message);
	});
}


test('the real application config exposes CmsConfig', function (): void {
	$config = Bootstrap::bootForTests()->createContainer()->getByType(CmsConfig::class);
	Assert::same('CMS Lite', $config->siteName);
	Assert::same('cs', $config->defaultLocale);
});
