<?php

declare(strict_types=1);

/** Model ports are wired to exactly one implementation, and implementations are reachable only through their port. */

use App\Bootstrap;
use App\Core\Database\ExplorerTransaction;
use App\Model\Content\MarkdownRenderer;
use App\Model\Page\Database\DatabasePageRepository;
use App\Model\Page\PageRepository;
use App\Model\Transaction;
use Nette\Bootstrap\Configurator;
use Nette\DI\Container;
use Nette\Utils\Finder;
use Tester\Assert;

require __DIR__ . '/../../bootstrap.php';

const APP_DIR = __DIR__ . '/../../../app';


function containerWith(?string $config = null): Container
{
	$configurator = Bootstrap::bootForTests();
	if ($config !== null) {
		$configurator->addConfig(__DIR__ . '/fixtures/' . $config);
		// Debug mode is off, so the compiled container is never refreshed: the fixture's content is part of its key.
		$configurator->addStaticParameters(['fixtureFingerprint' => hash_file('xxh128', __DIR__ . '/fixtures/' . $config)]);
	}

	return $configurator->createContainer();
}


/** A container of the fixture alone: in the application container a second implementation of a consumed port fails compilation already. */
function bareContainer(string $config): Container
{
	$configurator = new Configurator();
	$configurator->setDebugMode(false);
	$configurator->setTempDirectory(__DIR__ . '/../../../temp/tests/service-wiring');
	$configurator->addConfig(__DIR__ . '/fixtures/' . $config);
	$configurator->addStaticParameters([
		'fixtureFingerprint' => hash_file('xxh128', __DIR__ . '/fixtures/' . $config),
		'appFingerprint' => Bootstrap::testContainerFingerprint(dirname(APP_DIR)),
	]);

	return $configurator->createContainer();
}


/** @return list<class-string> every class and interface declared under app/ (PSR-4: App\ => app/) */
function appTypes(): array
{
	$types = [];
	foreach (Finder::findFiles('*.php')->from(APP_DIR) as $file) {
		$relative = substr($file->getRealPath(), strlen((string) realpath(APP_DIR)) + 1, -4);
		$type = 'App\\' . str_replace('/', '\\', $relative);
		if (class_exists($type) || interface_exists($type)) {
			$types[] = $type;
		}
	}
	sort($types);

	return $types;
}


/**
 * @param list<class-string> $types
 * @return array<class-string, list<class-string>> App\Model interface => its concrete implementations in app/
 */
function modelPorts(array $types): array
{
	$ports = [];
	foreach ($types as $type) {
		$class = new ReflectionClass($type);
		if ($class->isInterface() || $class->isAbstract()) {
			continue;
		}
		foreach ($class->getInterfaceNames() as $interface) {
			if (str_starts_with($interface, 'App\\Model\\')) {
				$ports[$interface][] = $type;
			}
		}
	}
	ksort($ports);

	return $ports;
}


/**
 * @param array<class-string, list<class-string>> $ports
 * @return list<string> ports without exactly one autowired service
 */
function portViolations(Container $container, array $ports): array
{
	$violations = [];
	foreach (array_keys($ports) as $port) {
		$autowired = $container->findAutowired($port);
		if (count($autowired) !== 1) {
			$violations[] = $port . ': ' . count($autowired) . ' autowired services (' . implode(', ', $autowired) . ')';
		}
	}

	return $violations;
}


/**
 * @param list<class-string> $types
 * @param array<class-string, list<class-string>> $ports
 * @return list<class-string> implementations of the ports plus every App\Model\*\Database\ class
 */
function implementations(array $types, array $ports): array
{
	$classes = array_merge(...array_values($ports));
	foreach ($types as $type) {
		if (preg_match('~^App\\\\Model\\\\[^\\\\]+\\\\Database\\\\~', $type) === 1 && !(new ReflectionClass($type))->isInterface()) {
			$classes[] = $type;
		}
	}
	$classes = array_values(array_unique($classes));
	sort($classes);

	return $classes;
}


/**
 * @param list<class-string> $classes
 * @return list<string> classes autowired under their own class
 */
function implementationViolations(Container $container, array $classes): array
{
	$violations = [];
	foreach ($classes as $class) {
		$autowired = $container->findAutowired($class);
		if ($autowired !== []) {
			$violations[] = $class . ': autowired under its own class as ' . implode(', ', $autowired);
		}
	}

	return $violations;
}


$types = appTypes();
$ports = modelPorts($types);
$implementations = implementations($types, $ports);


test('the scan finds the model ports and their database adapters', function () use ($ports): void {
	// Guards the scan itself: an empty result would make the checks below pass vacuously.
	Assert::contains(DatabasePageRepository::class, $ports[PageRepository::class] ?? []);
	Assert::contains(ExplorerTransaction::class, $ports[Transaction::class] ?? []);
});


test('the checked implementations include the Core adapters of model ports', function () use ($implementations): void {
	Assert::contains(ExplorerTransaction::class, $implementations);
	Assert::contains(DatabasePageRepository::class, $implementations);
});


test('every model interface implemented in app/ has exactly one autowired service', function () use ($ports): void {
	Assert::same([], portViolations(containerWith(), $ports));
});


test('a second autowired implementation of a port is reported', function (): void {
	Assert::same(
		['App\\Model\\Content\\MarkdownRenderer: 2 autowired services (01, secondRenderer)'],
		portViolations(bareContainer('second-implementation.neon'), [MarkdownRenderer::class => []]),
	);
});


test('no implementation of a model port is autowired under its own class', function () use ($implementations): void {
	Assert::same([], implementationViolations(containerWith(), $implementations));
});


test('an adapter autowired under its own class is reported', function () use ($implementations): void {
	Assert::same(
		['App\\Model\\Page\\Database\\DatabasePageTreeLock: autowired under its own class as lockByClass'],
		implementationViolations(containerWith('adapter-by-class.neon'), $implementations),
	);
});
