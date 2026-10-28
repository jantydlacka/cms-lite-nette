<?php

declare(strict_types=1);

namespace App\Model\Content;

interface ContentComponent
{
	/** The type written after `:::`; lower case letters, digits and hyphens. */
	public function name(): string;


	public function allowedIn(ContentKind $kind): bool;


	/**
	 * Validates the marker's arguments (identifiers and numbers only) and returns them in the component's own form.
	 *
	 * @param list<string> $arguments
	 * @return array<string, mixed>
	 * @throws InvalidComponentArguments
	 */
	public function arguments(array $arguments): array;
}
