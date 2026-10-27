<?php

declare(strict_types=1);

namespace App\Model\Page;

use DomainException;

/** The parent would be the page itself or one of its descendants; nothing was written. */
final class InvalidParentException extends DomainException
{
}
