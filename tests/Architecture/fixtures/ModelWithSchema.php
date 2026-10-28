<?php

declare(strict_types=1);

namespace App\Model\ArchProbe;

use Nette\Schema\Expect;
use Nette\Schema\Processor;

final class ModelWithSchema
{
	public function validate(mixed $value): mixed
	{
		return (new Processor())->process(Expect::int(), $value);
	}
}
