<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Model\Content\ContentContext;
use App\Model\Content\MarkdownRenderer;

/** An implementation of the port without dependencies, for containers that have nothing else. */
final class StubMarkdownRenderer implements MarkdownRenderer
{
	public function render(string $markdown, ContentContext $context): string
	{
		return '';
	}
}
