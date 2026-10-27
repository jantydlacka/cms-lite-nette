<?php

declare(strict_types=1);

namespace App\Core;

use App\Presentation\PresentationLayerProbe;

final class CoreWithPresentation
{
	public function __construct(public readonly PresentationLayerProbe $dependency)
	{
	}
}
