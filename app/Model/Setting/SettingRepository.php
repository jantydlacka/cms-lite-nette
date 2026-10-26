<?php

declare(strict_types=1);

namespace App\Model\Setting;

interface SettingRepository
{
	/** Null when the (key, locale) pair was never set; an empty string is a stored value. */
	public function get(string $key, string $locale = ''): ?string;


	/** Inserts or overwrites the value for (key, locale). */
	public function set(string $key, string $value, string $locale = ''): void;
}
