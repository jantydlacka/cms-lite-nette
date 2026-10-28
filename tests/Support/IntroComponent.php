<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Model\Content\ContentComponent;
use App\Model\Content\ContentKind;

/** A component without dependencies, named `uvod`. */
final class IntroComponent implements ContentComponent
{
	public function name(): string
	{
		return 'uvod';
	}


	public function allowedIn(ContentKind $kind): bool
	{
		return true;
	}


	/**
	 * @param list<string> $arguments
	 * @return array<string, mixed>
	 */
	public function arguments(array $arguments): array
	{
		return [];
	}
}
