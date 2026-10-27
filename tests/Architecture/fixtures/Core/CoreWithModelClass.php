<?php

declare(strict_types=1);

namespace App\Core;

use App\Model\User\UserRecordProbe;

final class CoreWithModelClass
{
	public function __construct(public readonly UserRecordProbe $dependency)
	{
	}
}
