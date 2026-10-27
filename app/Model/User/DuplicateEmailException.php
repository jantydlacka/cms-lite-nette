<?php

declare(strict_types=1);

namespace App\Model\User;

use DomainException;
use Throwable;

final class DuplicateEmailException extends DomainException
{
	public function __construct(?Throwable $previous = null)
	{
		parent::__construct('E-mail is already taken.', 0, $previous);
	}
}
