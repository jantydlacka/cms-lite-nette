<?php

declare(strict_types=1);

namespace App\Model\User;

interface UserRepository
{
	public function findById(int $id): ?User;


	/** The e-mail must already be normalized. */
	public function findByEmail(string $email): ?User;


	public function passwordHash(int $id): ?string;


	/**
	 * The e-mail must already be normalized.
	 *
	 * @throws DuplicateEmailException
	 */
	public function insert(string $email, string $passwordHash, string $name, Role $role): int;


	/** An unknown id is a silent no-op. */
	public function updatePasswordHash(int $id, string $passwordHash): void;
}
