<?php

declare(strict_types=1);

namespace App\Model\Content;

/**
 * A line of content that starts with `:::` (see spec/components.md).
 */
final readonly class ComponentMarker
{
	/**
	 * @param ?string $type null when the line does not have the form of a marker
	 * @param list<string> $arguments
	 * @param string $source the whole line as written, line ending included, so that blocks join back into the same text
	 */
	public function __construct(
		public int $line,
		public ?string $type,
		public array $arguments,
		public ?MarkerError $error,
		public string $source,
	) {
	}
}
