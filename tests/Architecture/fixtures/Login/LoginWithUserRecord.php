<?php

declare(strict_types=1);

namespace App\Model\Login;

use App\Model\User\UserRecordProbe;

final class LoginWithUserRecord
{
	public function __construct(public readonly UserRecordProbe $dependency)
	{
	}
}
