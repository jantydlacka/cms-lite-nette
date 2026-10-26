<?php

declare(strict_types=1);

namespace Tests\Support;

use Nextras\Migrations\Exception;
use Nextras\Migrations\Printers\DevNull;

/** Silent printer that turns the runner's swallowed errors into exceptions. */
final class ThrowingPrinter extends DevNull
{
	public function printError(Exception $e): void
	{
		throw $e;
	}
}
