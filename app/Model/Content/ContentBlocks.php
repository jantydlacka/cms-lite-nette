<?php

declare(strict_types=1);

namespace App\Model\Content;

use InvalidArgumentException;

/**
 * Content as a sequence of text and component markers; join(split(x)) gives x back byte for byte.
 */
final class ContentBlocks
{
	/**
	 * Cuts the text at the lines of top-level markers. A misplaced marker stays part of the text around it,
	 * because its line belongs to a list, quote or HTML block.
	 *
	 * @param list<ComponentMarker> $markers as found by ComponentScanner
	 * @return list<string|ComponentMarker>
	 */
	public static function split(string $markdown, array $markers): array
	{
		$lines = self::lines($markdown);
		$cuts = [];
		foreach ($markers as $marker) {
			if (($lines[$marker->line - 1] ?? null) !== $marker->source) {
				throw new InvalidArgumentException("The marker of line $marker->line does not match the text.");
			}
			if ($marker->error !== MarkerError::Misplaced) {
				$cuts[$marker->line] = $marker;
			}
		}

		$blocks = [];
		$text = '';
		foreach ($lines as $i => $line) {
			$marker = $cuts[$i + 1] ?? null;
			if ($marker === null) {
				$text .= $line;
				continue;
			}
			if ($text !== '') {
				$blocks[] = $text;
				$text = '';
			}
			$blocks[] = $marker;
		}
		if ($text !== '') {
			$blocks[] = $text;
		}

		return $blocks;
	}


	/** @param list<string|ComponentMarker> $blocks */
	public static function join(array $blocks): string
	{
		$markdown = '';
		foreach ($blocks as $block) {
			$markdown .= $block instanceof ComponentMarker ? $block->source : $block;
		}

		return $markdown;
	}


	/**
	 * Physical lines with their own line endings (LF, CRLF or CR, as CommonMark counts lines); line N is at index N - 1.
	 *
	 * @return list<string>
	 */
	public static function lines(string $markdown): array
	{
		if ($markdown === '') {
			return [];
		}
		$lines = preg_split('~(?<=\r\n|\n|\r(?!\n))~', $markdown);
		assert(is_array($lines));
		if (end($lines) === '') {
			array_pop($lines);
		}

		return $lines;
	}
}
