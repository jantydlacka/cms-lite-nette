<?php

declare(strict_types=1);

namespace App\Model\News;

use DateTimeImmutable;

final readonly class UpdateNews
{
	public function __construct(
		public int $newsId,
		public ?DateTimeImmutable $publishedAt,
		public int $expectedVersion,
	) {
	}
}
