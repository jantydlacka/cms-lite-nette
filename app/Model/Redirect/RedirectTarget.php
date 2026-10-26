<?php

declare(strict_types=1);

namespace App\Model\Redirect;

final readonly class RedirectTarget
{
	public function __construct(
		public string $type,
		public int $id,
	) {
	}
}
