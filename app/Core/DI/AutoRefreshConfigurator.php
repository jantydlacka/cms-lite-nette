<?php

declare(strict_types=1);

namespace App\Core\DI;

use Nette\Bootstrap\Configurator;
use Nette\DI\Compiler;
use Nette\DI\ContainerLoader;

/** Rebuilds the cached container when config or code changes, independently of debug mode (used by tests). */
final class AutoRefreshConfigurator extends Configurator
{
	public function loadContainer(): string
	{
		$loader = new ContainerLoader($this->getCacheDirectory() . '/nette.configurator', true);

		return $loader->load(
			function (Compiler $compiler): ?string {
				$this->generateContainer($compiler);

				return null;
			},
			$this->generateContainerKey(),
		);
	}
}
