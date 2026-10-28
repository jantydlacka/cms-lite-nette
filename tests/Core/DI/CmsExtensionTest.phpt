<?php

declare(strict_types=1);

use App\Bootstrap;
use App\Core\DI\CmsExtension;
use App\Model\CmsConfig;
use App\Model\Content\ComponentRegistry;
use App\Model\Login\LoginLimits;
use App\Model\News\NewsListComponent;
use Nette\Bootstrap\Configurator;
use Nette\DI\Container;
use Nette\DI\InvalidConfigurationException;
use Nette\Utils\FileSystem;
use Tester\Assert;
use Tests\Support\FakeNewsRepository;
use Tests\Support\SliderComponent;

require __DIR__ . '/../../bootstrap.php';

/** Compiles a container from a cms: section only; no database is touched. */
function compile(string $cms, string $parameters = ''): Container
{
	$dir = sys_get_temp_dir();
	$dir .= '/cms-extension-' . bin2hex(random_bytes(6));
	FileSystem::createDir($dir);
	$file = $dir . '/config.neon';
	// The default news component needs a NewsRepository, which the full application config provides.
	file_put_contents($file, $parameters . "extensions:\n\tcms: " . CmsExtension::class . "\n\ncms:\n" . $cms . "\n\nservices:\n\t- " . FakeNewsRepository::class . "\n");

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
	Assert::same('Europe/Prague', $config->timezone);
	Assert::true($config->newsInMenu);
});


test('demo data follow the demoData parameter and are off without it', function (): void {
	Assert::false(compile("\tsiteName: Demo")->getByType(CmsConfig::class)->demoData);
	Assert::false(compile("\tsiteName: Demo", "parameters:\n\tdemoData: false\n")->getByType(CmsConfig::class)->demoData);
	Assert::true(compile("\tsiteName: Demo", "parameters:\n\tdemoData: true\n")->getByType(CmsConfig::class)->demoData);
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
	'component class that does not exist' => ["\tsiteName: Demo\n\tcomponents:\n\t\tnovinky: App\\Model\\News\\Neexistuje", '%a%components%a%novinky%a%'],
	'component class without the interface' => ["\tsiteName: Demo\n\tcomponents:\n\t\tnovinky: stdClass", '%a%components%a%novinky%a%'],
	'component name in upper case' => ["\tsiteName: Demo\n\tcomponents:\n\t\tNovinky: App\\Model\\News\\NewsListComponent", '%a%components%a%'],
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


test('timezone and newsInMenu may be overridden', function (): void {
	$config = compile("\tsiteName: Demo\n\ttimezone: Asia/Tokyo\n\tnewsInMenu: false")->getByType(CmsConfig::class);
	Assert::same('Asia/Tokyo', $config->timezone);
	Assert::false($config->newsInMenu);
});


test('an unknown timezone is a configuration error naming the key', function (): void {
	Assert::exception(
		fn () => compile("\tsiteName: Demo\n\ttimezone: Mars/Base"),
		InvalidConfigurationException::class,
		'%a%timezone%a%',
	);
});


test('the news component is registered by default', function (): void {
	$registry = compile("\tsiteName: Demo")->getByType(ComponentRegistry::class);
	Assert::same(['novinky'], $registry->names());
	Assert::type(NewsListComponent::class, $registry->get('novinky'));
});


test('a configured component is registered next to the default one', function (): void {
	$registry = compile("\tsiteName: Demo\n\tcomponents:\n\t\tuvod: Tests\\Support\\IntroComponent")->getByType(ComponentRegistry::class);
	Assert::same(['novinky', 'uvod'], $registry->names());
});


test('a component whose name differs from its key fails when the registry is built', function (): void {
	Assert::exception(
		fn () => compile("\tsiteName: Demo\n\tcomponents:\n\t\tjine: Tests\\Support\\IntroComponent")->getByType(ComponentRegistry::class),
		InvalidArgumentException::class,
	);
});


test('a hyphenated component name is registered', function (): void {
	$registry = compile("\tsiteName: Demo\n\tcomponents:\n\t\tslider-main: Tests\\Support\\SliderComponent")->getByType(ComponentRegistry::class);
	Assert::same(['novinky', 'slider-main'], $registry->names());
	Assert::type(SliderComponent::class, $registry->get('slider-main'));
});
