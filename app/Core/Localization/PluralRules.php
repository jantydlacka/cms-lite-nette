<?php

declare(strict_types=1);

namespace App\Core\Localization;

use InvalidArgumentException;

final class PluralRules
{
	/** Plural forms per language, in catalog order. */
	private const FORMS = [
		'cs' => ['one', 'few', 'other'],
		'en' => ['one', 'other'],
	];


	/**
	 * @return list<string>
	 * @throws InvalidArgumentException for a language without rules
	 */
	public static function for(string $locale): array
	{
		return self::FORMS[$locale] ?? throw new InvalidArgumentException("No plural rules for locale '$locale'.");
	}


	/** Form for $n; negative numbers use the absolute value. */
	public static function form(string $locale, int $n): string
	{
		$n = abs($n);

		return match ($locale) {
			'cs' => match ($n) {
				1 => 'one',
				2, 3, 4 => 'few',
				default => 'other',
			},
			'en' => $n === 1 ? 'one' : 'other',
			default => throw new InvalidArgumentException("No plural rules for locale '$locale'."),
		};
	}
}
