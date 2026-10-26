<?php

declare(strict_types=1);

namespace App\Model\ArchProbe\Database;

use Nette\Database\Explorer;

final class DatabaseAdapter
{
	public function __construct(public readonly Explorer $dependency)
	{
	}
}
