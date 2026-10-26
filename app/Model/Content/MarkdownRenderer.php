<?php

declare(strict_types=1);

namespace App\Model\Content;

interface MarkdownRenderer
{
	/**
	 * Renders untrusted Markdown to safe HTML: raw HTML is escaped and unsafe link targets are dropped.
	 * Headings are not shifted: content starts at `##` because the page `<h1>` is the title.
	 */
	public function render(string $markdown): string;
}
