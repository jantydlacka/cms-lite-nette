<?php

declare(strict_types=1);

namespace App\Model\User;

use Nette\Utils\Strings;

final class Email
{
	public static function normalize(string $email): string
	{
		return mb_strtolower(Strings::trim($email));
	}
}
