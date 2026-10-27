<?php

declare(strict_types=1);

namespace App\Model;

use App\Model\Login\Database\LoginAdapterProbe;

final class RootModelWithAdapter
{
	public function __construct(public readonly LoginAdapterProbe $dependency)
	{
	}
}
