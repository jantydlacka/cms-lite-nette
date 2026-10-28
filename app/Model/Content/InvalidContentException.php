<?php

declare(strict_types=1);

namespace App\Model\Content;

use DomainException;

/** The content has broken component markers; nothing was written. */
final class InvalidContentException extends DomainException
{
	/** @param list<ContentProblem> $problems ordered by line */
	public function __construct(public readonly array $problems)
	{
		parent::__construct('The content has ' . count($problems) . ' broken component marker(s), the first on line ' . ($problems[0]->line ?? 0) . '.');
	}
}
