<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

App\Bootstrap::bootWebApplication()
	->getByType(Nette\Application\Application::class)
	->run();
