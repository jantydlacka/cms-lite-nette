<?php

declare(strict_types=1);

namespace App\Model\News;

final class NewsSegments
{
	private const CS = ['segment' => 'novinky', 'pageParameter' => 'strana'];
	private const DEFAULT = ['segment' => 'news', 'pageParameter' => 'page'];


	/** @return array{segment: string, pageParameter: string} */
	public static function forLocale(string $locale): array
	{
		return $locale === 'cs' ? self::CS : self::DEFAULT;
	}


	/** @return list<string> */
	public static function allSegments(): array
	{
		return [self::CS['segment'], self::DEFAULT['segment']];
	}


	/** @return list<string> */
	public static function allPageParameters(): array
	{
		return [self::CS['pageParameter'], self::DEFAULT['pageParameter']];
	}
}
