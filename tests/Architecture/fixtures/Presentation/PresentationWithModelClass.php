<?php

declare(strict_types=1);

namespace App\Presentation;

use App\Model\User\UserRecordProbe;

final class PresentationWithModelClass
{
	public function __construct(public readonly UserRecordProbe $dependency)
	{
	}
}
