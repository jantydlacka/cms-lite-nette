<?php

declare(strict_types=1);

namespace App\Model\Page;

/** Page properties; the position is never taken from here. */
final readonly class UpdatePage
{
	public function __construct(
		public int $pageId,
		public ?int $parentId,
		public bool $inMenu,
		public int $expectedVersion,
	) {
	}
}
