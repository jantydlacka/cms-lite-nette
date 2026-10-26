<?php

declare(strict_types=1);

namespace App\Model\Page;

/** The path is the slug alone: ancestors are ignored on purpose, so moving a page never changes its URL. */
final class FlatPagePathStrategy implements PagePathStrategy
{
	/** @param list<string> $ancestorSlugs */
	public function pathFor(string $slug, array $ancestorSlugs): string
	{
		return $slug;
	}
}
