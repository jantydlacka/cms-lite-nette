<?php

declare(strict_types=1);

namespace App\Model\Content;

final readonly class ContentProblem
{
	/** @param array<string, string|int> $parameters translation parameters, `line` included */
	public function __construct(
		public int $line,
		public string $messageKey,
		public array $parameters,
	) {
	}
}
