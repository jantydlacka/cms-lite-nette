<?php

declare(strict_types=1);

namespace App\Model\News;

use App\Model\PublicationStatus;

final readonly class SaveNewsTranslation
{
	public function __construct(
		public int $newsId,
		public string $locale,
		public string $title,
		public string $slug,
		public string $perex,
		public string $content,
		public string $metaDescription,
		public PublicationStatus $status,
		/** Version the editor started from; null for a translation that does not exist yet. */
		public ?int $expectedVersion,
	) {
	}
}
