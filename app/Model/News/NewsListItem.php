<?php

declare(strict_types=1);

namespace App\Model\News;

final readonly class NewsListItem
{
	public function __construct(
		public News $news,
		public NewsTranslation $translation,
	) {
	}
}
