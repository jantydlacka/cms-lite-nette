<?php

declare(strict_types=1);

namespace App\Model\Page;

use DomainException;

/** A page with subpages cannot be deleted; nothing was written. */
final class PageHasChildrenException extends DomainException
{
}
