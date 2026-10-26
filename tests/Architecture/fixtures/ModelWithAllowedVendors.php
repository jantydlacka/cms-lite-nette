<?php

declare(strict_types=1);

namespace App\Model\ArchProbe;

use Nette\Utils\Strings;
use Psr\Clock\ClockInterface;

final class ModelWithAllowedVendors
{
	public function __construct(public readonly ClockInterface $clock)
	{
	}


	public function slug(string $value): string
	{
		return Strings::webalize($value);
	}
}
