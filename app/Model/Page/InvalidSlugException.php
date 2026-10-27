<?php

declare(strict_types=1);

namespace App\Model\Page;

use DomainException;
use Throwable;

final class InvalidSlugException extends DomainException
{
	public const FORMAT = 'format';
	public const RESERVED = 'reserved';
	public const TAKEN = 'taken';
	public const LONG = 'long';


	/** @param self::FORMAT|self::RESERVED|self::TAKEN|self::LONG $reason */
	public function __construct(public readonly string $reason, ?Throwable $previous = null)
	{
		parent::__construct('Invalid slug: ' . $reason, 0, $previous);
	}
}
