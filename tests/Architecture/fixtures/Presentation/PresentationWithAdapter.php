<?php

declare(strict_types=1);

namespace App\Presentation;

use App\Model\Login\Database\LoginAdapterProbe;

final class PresentationWithAdapter
{
	public function __construct(public readonly LoginAdapterProbe $dependency)
	{
	}
}
