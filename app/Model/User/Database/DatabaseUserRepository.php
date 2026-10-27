<?php

declare(strict_types=1);

namespace App\Model\User\Database;

use App\Model\User\DuplicateEmailException;
use App\Model\User\Role;
use App\Model\User\User;
use App\Model\User\UserRepository;
use DateTimeZone;
use Nette\Database\Explorer;
use Nette\Database\UniqueConstraintViolationException;
use Psr\Clock\ClockInterface;

final class DatabaseUserRepository implements UserRepository
{
	public function __construct(
		private readonly Explorer $explorer,
		private readonly ClockInterface $clock,
	) {
	}


	public function findById(int $id): ?User
	{
		return $this->find('id', $id);
	}


	public function findByEmail(string $email): ?User
	{
		return $this->find('email', $email);
	}


	public function passwordHash(int $id): ?string
	{
		$hash = $this->explorer->query('SELECT password_hash FROM `user` WHERE id = ?', $id)->fetchField();

		return is_string($hash) ? $hash : null;
	}


	public function insert(string $email, string $passwordHash, string $name, Role $role): int
	{
		try {
			$this->explorer->query('INSERT INTO `user`', [
				'email' => $email,
				'password_hash' => $passwordHash,
				'name' => $name,
				'role' => $role->value,
				'created_at' => $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
			]);
		} catch (UniqueConstraintViolationException $e) {
			throw new DuplicateEmailException($e);
		}

		return (int) $this->explorer->getInsertId();
	}


	public function updatePasswordHash(int $id, string $passwordHash): void
	{
		$this->explorer->query('UPDATE `user` SET password_hash = ? WHERE id = ?', $passwordHash, $id);
	}


	private function find(string $column, int|string $value): ?User
	{
		$row = $this->explorer->table('user')->where($column, $value)->fetch();
		if ($row === null) {
			return null;
		}
		assert(is_int($row->id) && is_string($row->email) && is_string($row->name) && is_string($row->role));

		return new User($row->id, $row->email, $row->name, Role::from($row->role), (bool) $row->active);
	}
}
