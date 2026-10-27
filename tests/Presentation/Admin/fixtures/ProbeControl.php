<?php

declare(strict_types=1);

namespace Tests\Presentation\Admin\fixtures;

use Nette\Application\Attributes\Requires;
use Nette\Application\UI\Control;

/** Stands in for a mutating component signal (e.g. deleting a page); counts the calls. */
final class ProbeControl extends Control
{
	public static int $calls = 0;


	#[Requires(methods: 'POST')]
	public function handleTouch(): void
	{
		self::$calls++;
	}
}
