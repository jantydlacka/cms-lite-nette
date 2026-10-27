<?php

declare(strict_types=1);

use App\Bootstrap;
use App\Core\DI\CmsExtension;
use App\Model\CmsConfig;
use App\Model\Login\LoginLimits;
use Nette\Bootstrap\Configurator;
use Nette\DI\Container;
use Nette\DI\InvalidConfigurationException;
use Nette\Utils\FileSystem;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';

/** Compiles a container from a cms: section only; no database is touched. */
function compile(string $cms): Container
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
});


test('login limits default to 5 / 20 / 15', function (): void {
	$limits = compile("\tsiteName: Demo")->getByType(LoginLimits::class);
	Assert::same(5, $limits->maxPairFailures);
	Assert::same(20, $limits->maxIpFailures);
	Assert::same(15, $limits->windowMinutes);
});


test('login limits may be overridden', function (): void {
	$limits = compile("\tsiteName: Demo\n\tlogin:\n\t\tmaxPairFailures: 3\n\t\tmaxIpFailures: 7\n\t\twindowMinutes: 1")->getByType(LoginLimits::class);
	Assert::same([3, 7, 1], [$limits->maxPairFailures, $limits->maxIpFailures, $limits->windowMinutes]);
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
	'empty siteName' => ["\tsiteName: ''", '%a%siteName%a%'],
	'blank siteName' => ["\tsiteName: '  '", '%a%siteName%a%'],
	'default locale not in locales' => ["\tsiteName: Demo\n\tdefaultLocale: en\n\tlocales: [cs]", '%a%defaultLocale%a%'],
	'empty locales' => ["\tsiteName: Demo\n\tlocales: []", '%a%locales%a%'],
	'duplicate locales' => ["\tsiteName: Demo\n\tlocales: [cs, cs]", '%a%locales%a%'],
	'empty locale code' => ["\tsiteName: Demo\n\tlocales: ['']", '%a%locales%a%'],
	'uppercase locale code' => ["\tsiteName: Demo\n\tlocales: [CZ]", '%a%locales%a%'],
	'newsPerPage below 1' => ["\tsiteName: Demo\n\tnewsPerPage: 0", '%a%newsPerPage%a%'],
	'unknown key' => ["\tsiteName: Demo\n\tfoo: 1", '%a%foo%a%'],
	'maxPairFailures below 1' => ["\tsiteName: Demo\n\tlogin:\n\t\tmaxPairFailures: 0", '%a%maxPairFailures%a%'],
	'maxIpFailures below 1' => ["\tsiteName: Demo\n\tlogin:\n\t\tmaxIpFailures: 0", '%a%maxIpFailures%a%'],
	'windowMinutes below 1' => ["\tsiteName: Demo\n\tlogin:\n\t\twindowMinutes: 0", '%a%windowMinutes%a%'],
	'unknown login key' => ["\tsiteName: Demo\n\tlogin:\n\t\tfoo: 1", '%a%foo%a%'],
	// Demo data come only from the static %demoData% parameter (env CMS_DEMO_DATA or debug mode).
	'demoData key' => ["\tsiteName: Demo\n\tdemoData: no", '%a%demoData%a%'],
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
