<?php

declare(strict_types=1);

namespace App\Core\Markdown;

use App\Model\Content\ContentComponent;
use App\Model\Content\ContentContext;
use App\Model\Content\ContentProblem;

interface ComponentHtmlRenderer
{
	/**
	 * @param array<string, mixed> $arguments as returned by ContentComponent::arguments()
	 * @return string HTML; empty when the component has nothing to show
	 */
	public function render(ContentComponent $component, array $arguments, ContentContext $context): string;


	/** HTML of the translated problem of a marker, for the preview. */
	public function renderProblem(ContentProblem $problem): string;
}
