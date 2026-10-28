<?php

declare(strict_types=1);

namespace App\Core\Markdown\Component;

use App\Model\Content\MarkerError;
use League\CommonMark\Node\Block\Document;
use League\CommonMark\Parser\Block\AbstractBlockContinueParser;
use League\CommonMark\Parser\Block\BlockContinue;
use League\CommonMark\Parser\Block\BlockContinueParserInterface;
use League\CommonMark\Parser\Cursor;

/** A marker is a single line: it never continues. */
final class ComponentContinueParser extends AbstractBlockContinueParser
{
	public function __construct(
		private readonly ComponentBlock $block,
	) {
	}


	public function getBlock(): ComponentBlock
	{
		return $this->block;
	}


	public function tryContinue(Cursor $cursor, BlockContinueParserInterface $activeBlockParser): ?BlockContinue
	{
		return BlockContinue::none();
	}


	/** Only now is the block attached to its container. A malformed marker stays Syntax wherever it is. */
	public function closeBlock(): void
	{
		if ($this->block->error === null && !$this->block->parent() instanceof Document) {
			$this->block->error = MarkerError::Misplaced;
		}
	}
}
