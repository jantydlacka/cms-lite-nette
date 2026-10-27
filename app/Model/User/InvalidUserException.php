<?php

declare(strict_types=1);

namespace App\Model\User;

use DomainException;

final class InvalidUserException extends DomainException
{
	public const EMAIL_FORMAT = 'user.email.format';
	public const EMAIL_TAKEN = 'user.email.taken';
	public const NAME_EMPTY = 'user.name.empty';
	public const NAME_LONG = 'user.name.long';
	public const PASSWORD_SHORT = 'user.password.short';
	public const PASSWORD_LONG = 'user.password.long';
	public const PASSWORD_WHITESPACE = 'user.password.whitespace';
	public const PASSWORD_CHARS = 'user.password.chars';


	/** @param self::* $reason */
	public function __construct(public readonly string $reason)
	{
		parent::__construct('Invalid user: ' . $reason);
	}
}
