<?php

declare(strict_types=1);

namespace App\Model\Page;

use App\Model\PublicationStatus;

final readonly class PageTreeItem
{
	public function __construct(
		public int $pageId,
		public ?int $parentId,
		/** 0 for a top-level page. */
		public int $depth,
		/** Null when the page has no translation in the requested locale. */
		public ?string $title,
		public ?PublicationStatus $status,
		public bool $inMenu,
		public int $childCount,
	) {
	}
}
