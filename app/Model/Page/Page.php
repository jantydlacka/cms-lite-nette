<?php

declare(strict_types=1);

namespace App\Model\Page;

final readonly class Page
{
	public function __construct(
		public int $id,
		public ?int $parentId,
		public int $position,
		public bool $inMenu,
	) {
	}
}
