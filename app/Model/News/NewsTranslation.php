<?php

declare(strict_types=1);

namespace App\Model\News;

use App\Model\PublicationStatus;
use DateTimeImmutable;

final readonly class NewsTranslation
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
		public DateTimeImmutable $updatedAt,
		public int $version,
	) {
	}
}
