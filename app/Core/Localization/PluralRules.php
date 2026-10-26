<?php

declare(strict_types=1);

namespace App\Core\Localization;

final class PluralRules
{
	/**
	 * @return 'one'|'few'|'other'
	 */
	public static function cs(int $n): string
	{
		return match (abs($n)) {
			1 => 'one',
			2, 3, 4 => 'few',
			default => 'other',
		};
	}
}
