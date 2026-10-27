<?php

declare(strict_types=1);

namespace App\Model\Page;

use App\Model\PublicationStatus;

final readonly class CreatePage
{
	public function __construct(
		public ?int $parentId,
		public bool $inMenu,
		public string $locale,
		public string $title,
		public string $slug,
		public string $content,
		public string $metaDescription,
		public PublicationStatus $status,
	) {
	}
}
