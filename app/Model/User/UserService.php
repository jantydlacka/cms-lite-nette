<?php

declare(strict_types=1);

namespace App\Model\User;

use Nette\Utils\Strings;
use Nette\Utils\Validators;

final class UserService
{
	public const PASSWORD_MIN_LENGTH = 12;
	public const PASSWORD_MAX_BYTES = 72;
	private const NAME_MAX_LENGTH = 100;
	private const EMAIL_MAX_LENGTH = 254;


	public function __construct(
		private readonly UserRepository $users,
		private readonly PasswordHasher $hasher,
	) {
	}


	/** @throws InvalidUserException nothing is stored then */
	public function create(CreateUser $command): User
	{
		$email = Email::normalize($command->email);
		if ($email === '' || strlen($email) > self::EMAIL_MAX_LENGTH || !Validators::isEmail($email)) {
			throw new InvalidUserException(InvalidUserException::EMAIL_FORMAT);
		}

		$name = Strings::trim($command->name);
		if ($name === '') {
			throw new InvalidUserException(InvalidUserException::NAME_EMPTY);
		}
		if (mb_strlen($name) > self::NAME_MAX_LENGTH) {
			throw new InvalidUserException(InvalidUserException::NAME_LONG);
		}

		$password = $command->password;
		if (Strings::trim($password) !== $password) {
			throw new InvalidUserException(InvalidUserException::PASSWORD_WHITESPACE);
		}
		if (mb_strlen($password) < self::PASSWORD_MIN_LENGTH) {
			throw new InvalidUserException(InvalidUserException::PASSWORD_SHORT);
		}
		if (strlen($password) > self::PASSWORD_MAX_BYTES) {
			throw new InvalidUserException(InvalidUserException::PASSWORD_LONG);
		}

		if ($this->users->findByEmail($email) !== null) {
			throw new InvalidUserException(InvalidUserException::EMAIL_TAKEN);
		}

		try {
			$id = $this->users->insert($email, $this->hasher->hash($password), $name, $command->role);
		} catch (DuplicateEmailException) {
			throw new InvalidUserException(InvalidUserException::EMAIL_TAKEN);
		}

		return new User($id, $email, $name, $command->role, true);
	}
}
