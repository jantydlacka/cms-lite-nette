<?php

declare(strict_types=1);

namespace App\Model\Login;

use App\Model\Login\Database\LoginAdapterProbe;

final class LoginWithOwnAdapter
{
	public function __construct(public readonly LoginAdapterProbe $dependency)
	{
	}
}
