<?php

declare(strict_types=1);

namespace App\Core\Security;

use App\Model\User\PasswordHasher;
use Nette\Security\Passwords;

final class NettePasswordHasher implements PasswordHasher
{
	public function __construct(private readonly Passwords $passwords)
	{
	}


	public function hash(string $password): string
	{
		return $this->passwords->hash($password);
	}


	public function verify(string $password, string $hash): bool
	{
		return $this->passwords->verify($password, $hash);
	}


	public function needsRehash(string $hash): bool
	{
		return $this->passwords->needsRehash($hash);
	}
}
