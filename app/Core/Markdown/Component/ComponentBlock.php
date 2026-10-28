<?php

declare(strict_types=1);

namespace App\Core\Markdown\Component;

use App\Model\Content\MarkerError;
use League\CommonMark\Node\Block\AbstractBlock;

/**
 * A line starting with `:::`; see spec/components.md.
 */
final class ComponentBlock extends AbstractBlock
{
	private const string Form = '~^:::( +)([a-z][a-z0-9-]*)((?: +[a-z0-9][a-z0-9-]*)*) *$~D';


	/** @param list<string> $arguments */
	private function __construct(
		public readonly ?string $type,
		public readonly array $arguments,
		public ?MarkerError $error,
	) {
		parent::__construct();
	}


	/** @param string $text the line without its indentation, starting with `:::` */
	public static function fromLine(string $text): self
	{
		if (preg_match(self::Form, $text, $match) !== 1) {
			return new self(null, [], MarkerError::Syntax);
		}

		$arguments = array_values(array_filter(explode(' ', $match[3]), static fn (string $argument): bool => $argument !== ''));

		return new self($match[2], $arguments, null);
	}


	/** True for a line that, after at most three spaces of indentation, starts with `:::`. */
	public static function isMarkerLine(string $line): bool
	{
		return preg_match('~^ {0,3}:::~', $line) === 1;
	}
}
