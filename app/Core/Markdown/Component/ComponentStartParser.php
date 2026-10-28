<?php

declare(strict_types=1);

namespace App\Core\Markdown\Component;

use League\CommonMark\Parser\Block\BlockStart;
use League\CommonMark\Parser\Block\BlockStartParserInterface;
use League\CommonMark\Parser\Cursor;
use League\CommonMark\Parser\MarkdownParserStateInterface;

/**
 * Like an ATX heading, a marker may interrupt a paragraph. Inside fenced or indented code and HTML blocks
 * start parsers are not asked at all.
 */
final class ComponentStartParser implements BlockStartParserInterface
{
	public function tryStart(Cursor $cursor, MarkdownParserStateInterface $parserState): ?BlockStart
	{
		if ($cursor->isIndented() || $cursor->getNextNonSpaceCharacter() !== ':') {
			return BlockStart::none();
		}
		$cursor->advanceToNextNonSpaceOrTab();
		$text = $cursor->getRemainder();
		if (!str_starts_with($text, ':::')) {
			return BlockStart::none();
		}
		$cursor->advanceToEnd();

		return BlockStart::of(new ComponentContinueParser(ComponentBlock::fromLine($text)))->at($cursor);
	}
}
