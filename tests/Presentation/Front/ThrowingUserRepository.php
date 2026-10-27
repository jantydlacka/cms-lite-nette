<?php

declare(strict_types=1);

namespace Tests\Presentation\Front;

use App\Model\User\Role;
use App\Model\User\User;
use App\Model\User\UserRepository;
use RuntimeException;

/** Stands in for an unreachable database. */
final class ThrowingUserRepository implements UserRepository
{
	public function findById(int $id): ?User
	{
		throw self::down();
	}


	public function findByEmail(string $email): ?User
	{
		throw self::down();
	}


	public function passwordHash(int $id): ?string
	{
		throw self::down();
	}


	public function insert(string $email, string $passwordHash, string $name, Role $role): int
	{
		throw self::down();
	}


	public function updatePasswordHash(int $id, string $passwordHash): void
	{
		throw self::down();
	}


	private static function down(): RuntimeException
	{
		return new RuntimeException('Database is down.');
	}
}
