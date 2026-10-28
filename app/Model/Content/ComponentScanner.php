<?php

declare(strict_types=1);

namespace App\Model\Content;

interface ComponentScanner
{
	/**
	 * Finds every line of the Markdown that is a component marker, including malformed and misplaced ones;
	 * lines inside code and escaped `\:::` are text.
	 *
	 * @return list<ComponentMarker> ordered by line
	 */
	public function scan(string $markdown): array;
}
