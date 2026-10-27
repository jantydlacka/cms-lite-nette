<?php

declare(strict_types=1);

namespace App\Model;

use App\Model\User\UserRecordProbe;

final class RootModelWithRecord
{
	public function __construct(public readonly UserRecordProbe $dependency)
	{
	}
}
