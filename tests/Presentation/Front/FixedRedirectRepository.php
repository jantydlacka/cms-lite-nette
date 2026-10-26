<?php

declare(strict_types=1);

namespace Tests\Presentation\Front;

use App\Model\Redirect\PathRedirectRepository;
use App\Model\Redirect\RedirectTarget;

/** Every path is an old path of $target (set it before the request); writes are ignored so PageService can seed pages. */
final class FixedRedirectRepository implements PathRedirectRepository
{
	public static ?RedirectTarget $target = null;


	public function findTarget(string $locale, string $path): ?RedirectTarget
	{
		return self::$target;
	}


	public function record(string $locale, string $oldPath, RedirectTarget $target): void
	{
	}


	public function deleteByPath(string $locale, string $path): void
	{
	}


	public function deleteForTarget(RedirectTarget $target): void
	{
	}
}
