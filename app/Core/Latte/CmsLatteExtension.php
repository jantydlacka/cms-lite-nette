<?php

declare(strict_types=1);

namespace App\Core\Latte;

use App\Model\Content\MarkdownRenderer;
use Latte\Extension;
use Latte\Runtime\Html;

final class CmsLatteExtension extends Extension
{
	public function __construct(
		private readonly MarkdownRenderer $markdown,
	) {
	}


	/** @return array<string, callable> */
	public function getFilters(): array
	{
		return [
			'markdown' => fn (string $markdown): Html => new Html($this->markdown->render($markdown)),
		];
	}
}
