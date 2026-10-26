<?php

declare(strict_types=1);

namespace App\Core\Markdown;

use App\Model\Content\MarkdownRenderer;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\MarkdownConverter;

final class CommonMarkRenderer implements MarkdownRenderer
{
	private readonly MarkdownConverter $converter;


	public function __construct()
	{
		// Both options default to the unsafe value in league/commonmark (html_input: allow, allow_unsafe_links: true).
		$environment = new Environment([
			'html_input' => 'escape',
			'allow_unsafe_links' => false,
		]);
		$environment->addExtension(new CommonMarkCoreExtension());
		$this->converter = new MarkdownConverter($environment);
	}


	public function render(string $markdown): string
	{
		return $this->converter->convert($markdown)->getContent();
	}
}
