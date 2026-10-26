<?php

declare(strict_types=1);

namespace App\Model\Page;

interface PagePathStrategy
{
	/** @param list<string> $ancestorSlugs from the root down to the direct parent */
	public function pathFor(string $slug, array $ancestorSlugs): string;
}
