<?php

declare(strict_types=1);

namespace App\Model\ArchProbe;

use Nette\Application\UI\Presenter;

final class ModelWithPresenter
{
	public function __construct(public readonly Presenter $dependency)
	{
	}
}
