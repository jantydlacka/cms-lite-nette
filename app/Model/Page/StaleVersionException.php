<?php

declare(strict_types=1);

namespace App\Model\Page;

use DomainException;

/** The row changed since the editor loaded it; nothing was written. */
final class StaleVersionException extends DomainException
{
}
