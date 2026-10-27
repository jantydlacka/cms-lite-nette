<?php

declare(strict_types=1);

namespace App\Model\Page;

use DomainException;

/** The homepage would lose its published default-locale translation, or the page lacks one to become the homepage; nothing was written. */
final class HomepageProtectedException extends DomainException
{
	public const NEEDS_PUBLISHED = 'needsPublished';
	public const PROTECTED = 'protected';


	/** @param self::NEEDS_PUBLISHED|self::PROTECTED $reason */
	public function __construct(public readonly string $reason)
	{
		parent::__construct('Homepage: ' . $reason);
	}
}
