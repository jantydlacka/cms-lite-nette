<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Markdown\ComponentHtmlRenderer;
use App\Model\Content\ContentComponent;
use App\Model\Content\ContentContext;
use App\Model\Content\ContentProblem;
use Nette\Utils\Json;

/** Prints what it was asked to render, as one element; `$empty` makes every component render nothing. */
final class FakeComponentHtmlRenderer implements ComponentHtmlRenderer
{
	/** @var list<string> */
	public array $rendered = [];

	/** @var list<ContentProblem> */
	public array $problems = [];


	public function __construct(private readonly bool $empty = false)
	{
	}


	/** @param array<string, mixed> $arguments */
	public function render(ContentComponent $component, array $arguments, ContentContext $context): string
	{
		$text = $component->name() . ' ' . Json::encode($arguments) . ' ' . $context->locale . ($context->preview ? ' preview' : '');
		$this->rendered[] = $text;

		return $this->empty ? '' : '<div class="fake">' . htmlspecialchars($text) . '</div>';
	}


	public function renderProblem(ContentProblem $problem): string
	{
		$this->problems[] = $problem;

		return '<p class="problem">' . $problem->messageKey . ' ' . $problem->line . '</p>';
	}
}
