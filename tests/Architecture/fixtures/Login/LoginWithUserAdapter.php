<?php

declare(strict_types=1);

namespace App\Model\Login;

use App\Model\User\Database\UserAdapterProbe;

final class LoginWithUserAdapter
{
	public function __construct(public readonly UserAdapterProbe $dependency)
	{
	}
}
