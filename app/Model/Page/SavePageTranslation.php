<?php

declare(strict_types=1);

namespace App\Model\Page;

use App\Model\PublicationStatus;

final readonly class SavePageTranslation
{
	public function __construct(
		public int $pageId,
		public string $locale,
		public string $title,
		public string $slug,
		public string $content,
		public string $metaDescription,
		public PublicationStatus $status,
		/** Version the editor started from; null for a translation that does not exist yet. */
		public ?int $expectedVersion,
	) {
	}
}
