<?php

declare(strict_types=1);

namespace Tests;

use App\Bootstrap;
use Nette\Application\Responses\TextResponse;
use Nette\DI\Container;
use Stringable;
use Tester\Environment;

require __DIR__ . '/../vendor/autoload.php';

Environment::setup();
Environment::setupFunctions();

function container(): Container
{
	return Bootstrap::bootForTests()->createContainer();
}


function html(TextResponse $response): string
{
	$source = $response->getSource();
	assert(is_string($source) || $source instanceof Stringable);

	return (string) $source;
}
