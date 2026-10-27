<?php

declare(strict_types=1);

namespace App\Presentation\Admin;

use App\Model\Page\ReservedSegments;

/** Error pages under the administration path use the administration layout. */
final class AdminArea
{
	public const string Layout = __DIR__ . '/@layout.latte';


	/** @param string $relativePath URL path without the base path, e.g. 'admin/sign-in' */
	public static function contains(string $relativePath): bool
	{
		return explode('/', $relativePath, 2)[0] === ReservedSegments::ADMIN;
	}
}
