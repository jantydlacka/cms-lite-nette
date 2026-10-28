<?php

declare(strict_types=1);

namespace App\Core\Markdown;

use App\Core\Markdown\Component\ComponentBlock;
use App\Core\Markdown\Component\RenderedComponent;
use App\Model\Content\ComponentRegistry;
use App\Model\Content\ContentBlocks;
use App\Model\Content\ContentChecker;
use App\Model\Content\ContentContext;
use App\Model\Content\ContentProblem;
use App\Model\Content\MarkdownRenderer;
use League\CommonMark\Extension\CommonMark\Node\Block\HtmlBlock;
use League\CommonMark\Node\Block\AbstractBlock;
use League\CommonMark\Node\Block\Document;
use League\CommonMark\Parser\MarkdownParser;
use League\CommonMark\Renderer\HtmlRenderer;
use LogicException;
use Tracy\ILogger;

final class CommonMarkRenderer implements MarkdownRenderer
{
	private readonly MarkdownParser $parser;

	private readonly HtmlRenderer $htmlRenderer;


	public function __construct(
		private readonly ContentChecker $checker,
		private readonly ComponentRegistry $registry,
		private readonly ComponentHtmlRenderer $components,
		private readonly ILogger $logger,
		private readonly ComponentWarningGate $warnings,
	) {
		$environment = CommonMarkEnvironment::create();
		$this->parser = new MarkdownParser($environment);
		$this->htmlRenderer = new HtmlRenderer($environment);
	}


	public function render(string $markdown, ContentContext $context): string
	{
		$document = $this->parser->parse($markdown);
		$this->placeComponents($document, $markdown, $context);

		return $this->htmlRenderer->renderDocument($document)->getContent();
	}


	private function placeComponents(Document $document, string $markdown, ContentContext $context): void
	{
		$markers = [];
		$htmlBlocks = [];
		foreach ($document->iterator() as $node) {
			if ($node instanceof ComponentBlock) {
				$markers[] = $node;
			} elseif ($node instanceof HtmlBlock && preg_match('~^ {0,3}:::~m', $node->getLiteral()) === 1) {
				$htmlBlocks[] = $node;
			}
		}
		if ($markers === [] && $htmlBlocks === []) {
			return;
		}

		// The same check as on save, so the preview reports exactly what saving would refuse.
		$problems = [];
		foreach ($this->checker->check($markdown, $context->kind) as $problem) {
			$problems[$problem->line] = $problem;
		}
		$lines = ContentBlocks::lines($markdown);

		foreach ($markers as $marker) {
			$line = self::line($marker);
			$problem = $problems[$line] ?? null;
			$html = $problem === null
				? $this->componentHtml($marker, $context)
				: $this->problemHtml($problem, $marker->type, $lines[$line - 1], $markdown, $context);
			if ($html === '') {
				$marker->detach();
			} else {
				$marker->replaceWith(new RenderedComponent($html));
			}
		}

		// A marker line inside an HTML block is part of the block's text; it must not show up as text.
		foreach ($htmlBlocks as $block) {
			$start = self::line($block);
			$kept = [];
			$after = [];
			foreach (explode("\n", $block->getLiteral()) as $i => $text) {
				if (!ComponentBlock::isMarkerLine($text)) {
					$kept[] = $text;
					continue;
				}
				$problem = $problems[$start + $i] ?? throw new LogicException('A marker in an HTML block is always misplaced.');
				$html = $this->problemHtml($problem, ComponentBlock::fromLine(ltrim($text, ' '))->type, $text, $markdown, $context);
				if ($html !== '') {
					$after[] = new RenderedComponent($html);
				}
			}
			$block->setLiteral(implode("\n", $kept));
			foreach (array_reverse($after) as $rendered) {
				$block->insertAfter($rendered);
			}
		}
	}


	private function componentHtml(ComponentBlock $marker, ContentContext $context): string
	{
		$component = $this->registry->get((string) $marker->type)
			?? throw new LogicException('A marker without a problem names a registered component.');

		return $this->components->render($component, $component->arguments($marker->arguments), $context);
	}


	/** The preview shows the problem; the website leaves the marker out and logs it, as often as the gate lets it. */
	private function problemHtml(ContentProblem $problem, ?string $type, string $source, string $markdown, ContentContext $context): string
	{
		if ($context->preview) {
			return $this->components->renderProblem($problem);
		}

		if ($this->warnings->pass($context, $problem->line, $markdown)) {
			$what = $type !== null ? '"' . self::shorten($type) . '"' : 'malformed "' . self::shorten(trim($source)) . '"';
			$this->logger->log(
				"Component marker left out: $what on line $problem->line of {$context->kind->value} $context->id ($context->locale), $problem->messageKey.",
				ILogger::WARNING,
			);
		}

		return '';
	}


	/** A log line quotes at most 80 characters of the type or the source line. */
	private static function shorten(string $source): string
	{
		return mb_strlen($source) > 80 ? mb_substr($source, 0, 79) . '…' : $source;
	}


	private static function line(AbstractBlock $block): int
	{
		return $block->getStartLine() ?? throw new LogicException('A parsed block has a start line.');
	}
}
