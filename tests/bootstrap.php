<?php

declare(strict_types=1);

namespace Tests;

use App\Bootstrap;
use Nette\DI\Container;
use Tester\Environment;

require __DIR__ . '/../vendor/autoload.php';

Environment::setup();
Environment::setupFunctions();

function container(): Container
{
	return Bootstrap::bootForTests()->createContainer();
}
