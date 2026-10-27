<?php

declare(strict_types=1);

namespace App\Model\Page;

use App\Model\PublicationStatus;
use DateTimeImmutable;

final readonly class PageTranslation
{
	public function __construct(
		public int $pageId,
		public string $locale,
		public string $title,
		public string $slug,
		public string $path,
		public string $content,
		public string $metaDescription,
		public PublicationStatus $status,
		public DateTimeImmutable $updatedAt,
		public int $version,
	) {
	}
}
