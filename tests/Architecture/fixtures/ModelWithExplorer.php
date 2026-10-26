<?php

declare(strict_types=1);

namespace App\Model\ArchProbe;

use Nette\Database\Explorer;

final class ModelWithExplorer
{
	public function __construct(public readonly Explorer $dependency)
	{
	}
}
