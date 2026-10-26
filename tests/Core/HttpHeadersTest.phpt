<?php

declare(strict_types=1);

use App\Bootstrap;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';

// Response headers are compiled into the container's initialization only outside console mode,
// and are never sent under CLI, so a web-mode container is compiled and its code checked.
test('the web container does not send X-Powered-By', function (): void {
	$configurator = Bootstrap::bootForTests();
	$configurator->addStaticParameters(['consoleMode' => false]);
	$container = $configurator->createContainer();
	$file = (new ReflectionClass($container))->getFileName();
	assert(is_string($file));
	$code = (string) file_get_contents($file);

	Assert::contains('->setHeader(', $code);
	Assert::notContains('X-Powered-By', $code);
});
