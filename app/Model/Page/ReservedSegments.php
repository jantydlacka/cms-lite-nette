<?php

declare(strict_types=1);

namespace App\Model\Page;

use App\Model\CmsConfig;
use App\Model\News\NewsSegments;

/** First path segments that belong to the application, not to pages. */
final class ReservedSegments
{
	public const ADMIN = 'admin';
	public const FIXED = [self::ADMIN, 'sitemap.xml'];


	public function __construct(private readonly CmsConfig $config)
	{
	}


	/** @return list<string> */
	public function all(): array
	{
		return array_values(array_unique(array_map(strtolower(...), [...self::FIXED, ...NewsSegments::allSegments(), ...$this->config->locales])));
	}


	public function isReserved(string $path): bool
	{
		return in_array(strtolower(explode('/', $path, 2)[0]), $this->all(), true);
	}
}
