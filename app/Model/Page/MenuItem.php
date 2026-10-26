<?php

declare(strict_types=1);

namespace App\Model\Page;

final readonly class MenuItem
{
	/** @param list<MenuItem> $children */
	public function __construct(
		public int $pageId,
		public string $title,
		public string $path,
		public array $children = [],
	) {
	}
}
