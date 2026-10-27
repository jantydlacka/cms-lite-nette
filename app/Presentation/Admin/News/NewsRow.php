<?php

declare(strict_types=1);

namespace App\Presentation\Admin\News;

use App\Model\News\News;
use App\Model\News\NewsState;

final readonly class NewsRow
{
	/** @param array<string, NewsState> $states by enabled locale */
	public function __construct(
		public News $news,
		public ?string $title,
		public ?string $author,
		public array $states,
	) {
	}
}
