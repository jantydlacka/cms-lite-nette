<?php

declare(strict_types=1);

namespace App\Model\News;

use App\Model\PublicationStatus;
use DateTimeImmutable;

final readonly class CreateNews
{
	public function __construct(
		/** Null = no date yet; publishing the translation dates the item now. */
		public ?DateTimeImmutable $publishedAt,
		public int $authorId,
		public string $locale,
		public string $title,
		public string $slug,
		public string $perex,
		public string $content,
		public string $metaDescription,
		public PublicationStatus $status,
	) {
	}
}
