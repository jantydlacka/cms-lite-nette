<?php

declare(strict_types=1);

namespace App\Presentation\Admin\News;

use App\Model\PublicationStatus;

final class NewsTranslationFormData
{
	public string $title;

	public string $slug;

	public string $perex;

	public string $content;

	public string $metaDescription;

	public PublicationStatus $status;

	/** Translation version the form was rendered from; empty for a translation that did not exist then. */
	public string $version = '';
}
