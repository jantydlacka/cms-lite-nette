<?php

declare(strict_types=1);

namespace App\Model;

use Nette\Utils\Strings;

final class Slug
{
	public const MAX_LENGTH = 100;
	private const PATTERN = '~^[a-z0-9]+(?:-[a-z0-9]+)*$~D';


	/** Cut at a hyphen boundary within MAX_LENGTH. */
	public static function fromTitle(string $title): string
	{
		$slug = Strings::webalize($title);
		if (strlen($slug) > self::MAX_LENGTH) {
			$hyphen = strrpos(substr($slug, 0, self::MAX_LENGTH + 1), '-');
			$slug = substr($slug, 0, $hyphen === false ? self::MAX_LENGTH : $hyphen);
		}

		return rtrim($slug, '-');
	}


	/** @throws InvalidSlugException FORMAT|LONG, length first */
	public static function assertValid(string $slug): void
	{
		if (strlen($slug) > self::MAX_LENGTH) {
			throw new InvalidSlugException(InvalidSlugException::LONG);
		}
		if (preg_match(self::PATTERN, $slug) !== 1) {
			throw new InvalidSlugException(InvalidSlugException::FORMAT);
		}
	}
}
