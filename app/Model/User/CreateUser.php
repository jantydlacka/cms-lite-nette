<?php

declare(strict_types=1);

namespace App\Model\User;

final readonly class CreateUser
{
	public function __construct(
		public string $email,
		public string $name,
		public string $password,
		public Role $role,
	) {
	}
}
