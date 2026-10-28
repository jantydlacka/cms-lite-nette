<?php

declare(strict_types=1);

namespace App\Model\Content;

use RuntimeException;

final class InvalidComponentArguments extends RuntimeException
{
	/** @param string $messageKey translation key; its text takes the %line% parameter */
	public function __construct(public readonly string $messageKey)
	{
		parent::__construct("Invalid component arguments ($messageKey).");
	}
}
