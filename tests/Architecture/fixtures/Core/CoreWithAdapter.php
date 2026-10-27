<?php

declare(strict_types=1);

namespace App\Core;

use App\Model\Login\Database\LoginAdapterProbe;

final class CoreWithAdapter
{
	public function __construct(public readonly LoginAdapterProbe $dependency)
	{
	}
}
