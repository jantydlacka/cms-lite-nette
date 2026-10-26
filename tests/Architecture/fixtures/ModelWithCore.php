<?php

declare(strict_types=1);

namespace App\Model\ArchProbe;

use App\Core\RouterFactory;

final class ModelWithCore
{
	public function __construct(public readonly RouterFactory $dependency)
	{
	}
}
