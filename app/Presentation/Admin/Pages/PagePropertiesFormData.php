<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Pages;

final class PagePropertiesFormData
{
	/** Null for the top level, and for a submitted id that is not a page (see PagesPresenter::submittedParent()). */
	public ?int $parent;

	public bool $inMenu;

	/** Page version the form was rendered from. */
	public string $version;
}
