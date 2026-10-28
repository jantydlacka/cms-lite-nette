<?php

declare(strict_types=1);

namespace App\Model\Content;

interface MarkdownRenderer
{
	/**
	 * Renders untrusted Markdown to safe HTML: raw HTML is escaped and unsafe link targets are dropped.
	 * Headings are not shifted: content starts at `##` because the page `<h1>` is the title.
	 * A valid component marker renders its component. A broken one is left out and logged on the website;
	 * the preview shows its problem instead.
	 */
	public function render(string $markdown, ContentContext $context): string;
}
