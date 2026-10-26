<?php

declare(strict_types=1);

namespace App\Model\Redirect;

interface PathRedirectRepository
{
	public function findTarget(string $locale, string $path): ?RedirectTarget;


	/** Upsert by (locale, oldPath). */
	public function record(string $locale, string $oldPath, RedirectTarget $target): void;


	public function deleteByPath(string $locale, string $path): void;


	public function deleteForTarget(RedirectTarget $target): void;
}
