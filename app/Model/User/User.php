<?php

declare(strict_types=1);

namespace App\Model\User;

final readonly class User
{
	public function __construct(
		public int $id,
		public string $email,
		public string $name,
		public Role $role,
		public bool $active,
	) {
	}
}
