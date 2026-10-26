<?php

declare(strict_types=1);

namespace App\Model\ArchProbe\Database;

use Nette\Database\Explorer;
use Nette\Forms\Form;

final class DatabaseAdapterWithForms
{
	public function __construct(
		public readonly Explorer $database,
		public readonly Form $form,
	) {
	}
}
