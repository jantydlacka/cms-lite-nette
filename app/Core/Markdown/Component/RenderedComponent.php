<?php

declare(strict_types=1);

namespace App\Core\Markdown\Component;

use League\CommonMark\Node\Block\AbstractBlock;

/** Takes the place of a marker: the HTML of its component, or of its problem in the preview. */
final class RenderedComponent extends AbstractBlock
{
	/** @param string $html trusted: rendered by a Latte template with auto-escaping */
	public function __construct(public readonly string $html)
	{
		parent::__construct();
	}
}
