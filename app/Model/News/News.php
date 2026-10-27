<?php

declare(strict_types=1);

namespace App\Model\News;

use DateTimeImmutable;

final readonly class News
{
	public function __construct(
		public int $id,
		public ?DateTimeImmutable $publishedAt,
		public ?int $authorId,
		public int $version,
		public DateTimeImmutable $createdAt,
	) {
	}
}
