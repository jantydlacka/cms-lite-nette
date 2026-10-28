<?php

declare(strict_types=1);

namespace App\Core\Markdown\Component;

use App\Core\Markdown\CommonMarkEnvironment;
use App\Model\Content\ComponentMarker;
use App\Model\Content\ComponentScanner;
use App\Model\Content\ContentBlocks;
use App\Model\Content\MarkerError;
use League\CommonMark\Extension\CommonMark\Node\Block\HtmlBlock;
use League\CommonMark\Parser\MarkdownParser;
use LogicException;

final class CommonMarkComponentScanner implements ComponentScanner
{
	private readonly MarkdownParser $parser;


	public function __construct()
	{
		$this->parser = new MarkdownParser(CommonMarkEnvironment::create());
	}


	/** @return list<ComponentMarker> */
	public function scan(string $markdown): array
	{
		$lines = ContentBlocks::lines($markdown);
		$markers = [];
		foreach ($this->parser->parse($markdown)->iterator() as $node) {
			if ($node instanceof ComponentBlock) {
				$line = $node->getStartLine() ?? throw new LogicException('A parsed block has a start line.');
				$markers[$line] = new ComponentMarker($line, $node->type, $node->arguments, $node->error, $lines[$line - 1]);
			} elseif ($node instanceof HtmlBlock) {
				// A `:::` line inside an HTML block never reaches the start parser; it must not pass as text.
				$start = $node->getStartLine() ?? throw new LogicException('A parsed block has a start line.');
				foreach (explode("\n", $node->getLiteral()) as $i => $text) {
					if (ComponentBlock::isMarkerLine($text)) {
						$block = ComponentBlock::fromLine(ltrim($text, ' '));
						$markers[$start + $i] = new ComponentMarker($start + $i, $block->type, $block->arguments, MarkerError::Misplaced, $lines[$start + $i - 1]);
					}
				}
			}
		}
		ksort($markers);

		return array_values($markers);
	}
}
