<?php

declare(strict_types=1);

namespace Tests;

use App\Bootstrap;
use Nette\Application\Responses\TextResponse;
use Nette\DI\Container;
use Stringable;
use Tester\Environment;
use Tests\Support\TestDatabase;

require __DIR__ . '/../vendor/autoload.php';

Environment::setup();
Environment::setupFunctions();

function container(): Container
{
	// Every call wipes the thread database (prepare() runs per call).
	$container = Bootstrap::bootForTests()->createContainer();
	TestDatabase::prepare($container);

	return $container;
}


function html(TextResponse $response): string
{
	$source = $response->getSource();
	assert(is_string($source) || $source instanceof Stringable);

	return (string) $source;
}
