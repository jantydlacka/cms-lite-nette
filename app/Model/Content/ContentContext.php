<?php

declare(strict_types=1);

namespace App\Model\Content;

/**
 * What a piece of content is rendered for: components need the language explicitly, because the admin preview
 * of a translation runs in the default language; a skipped marker is logged with the kind and id.
 */
final readonly class ContentContext
{
	public function __construct(
		public ContentKind $kind,
		public int $id,
		public string $locale,
		public bool $preview,
	) {
	}
}
